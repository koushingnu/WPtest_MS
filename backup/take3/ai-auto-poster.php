<?php
/**
 * Plugin Name: AI Auto Poster Lite (Box)
 * Description: 最小構成。OpenAIのJSONをGutenbergブロックへ変換（見出し/段落/リスト/ボックス対応）+ タイトル/題材のバリエーション + 実行後は完了ログ表示 + アイキャッチ自動生成(DALL·E 3 フォールバック付)
 * Version: 0.4.3
 * Author: You
 * Requires PHP: 7.3
 */
if (!defined('ABSPATH')) exit;

// 依存ファイルを読み込み
require_once plugin_dir_path(__FILE__) . 'includes/class-logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-openai-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-image-generator.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-content-generator.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-block-converter.php';

class AIAP_Lite_Box {
    const OPT_KEY    = 'aiap_lite_settings';
    const ACTION_RUN = 'aiap_lite_run';
    const NOTICE_KEY = 'aiap_lite_notice';

    private $logger;
    private $openai_client;
    private $image_generator;
    private $content_generator;
    private $block_converter;

    function __construct() {
        // 各クラスを初期化
        $this->logger = new AIAP_Logger();
        $this->openai_client = new AIAP_OpenAI_Client();
        $this->image_generator = new AIAP_Image_Generator($this->logger);
        $this->content_generator = new AIAP_Content_Generator($this->openai_client, $this->logger);
        $this->block_converter = new AIAP_Block_Converter();

        // テーマ設定前にサムネイルサポートを有効化
        add_action('after_setup_theme',  array($this, 'ensure_thumbnails'), 9);
        add_action('init',               array($this, 'ensure_thumbnails'));
        
        // その他の通常の初期化
        add_action('admin_menu',         array($this, 'menu'));
        add_action('admin_init',         array($this, 'register'));
        add_action('admin_post_'.self::ACTION_RUN, array($this, 'handle_run'));
        add_action('wp_enqueue_scripts', array($this, 'styles'));
        add_action('admin_notices',      array($this, 'admin_notices'));
        add_action('wp_ajax_aiap_load_tab', array($this, 'ajax_load_tab'));
        
        error_log('AI Auto Poster: プラグイン初期化完了');
    }

    function ensure_thumbnails() {
        add_theme_support('post-thumbnails');
        add_post_type_support('post', 'thumbnail');
        error_log('AI Auto Poster: サムネイルサポート有効化');
    }

    /* ========== 管理UI ========== */
    function menu() {
        add_options_page('AI Auto Poster Lite', 'AI Auto Poster Lite', 'manage_options', 'aiap-lite', array($this, 'page'));
    }

    function register() {
        register_setting('aiap_lite_group', self::OPT_KEY);

        // === OpenAI設定タブ ===
        add_settings_section('main', 'API設定', '__return_false', 'aiap-lite-main');
        add_settings_field('api_key', 'OpenAI API Key', function() {
            $o = get_option(self::OPT_KEY, array());
            printf('<input type="password" name="%s[api_key]" value="%s" class="regular-text" />',
                esc_attr(self::OPT_KEY), esc_attr(isset($o['api_key'])?$o['api_key']:''));
        }, 'aiap-lite-main', 'main');

        // === 投稿設定タブ ===
        add_settings_section('post_config', '投稿設定', function() {
            echo '<p>投稿の基本設定を行います。</p>';
        }, 'aiap-lite-post_config');

        add_settings_field('post_status', '投稿ステータス', function() {
            $o = get_option(self::OPT_KEY, array());
            $v = isset($o['post_status']) ? $o['post_status'] : 'draft'; ?>
            <select name="<?php echo esc_attr(self::OPT_KEY); ?>[post_status]">
                <option value="publish" <?php selected($v, 'publish'); ?>>公開</option>
                <option value="draft"   <?php selected($v, 'draft');   ?>>下書き</option>
            </select>
            <p class="description">自動生成された記事の投稿状態を設定します</p>
        <?php }, 'aiap-lite-post_config', 'post_config');

        // === アイキャッチ設定タブ ===
        add_settings_section('image_gen', 'アイキャッチ画像生成設定', function() {
            echo '<p>DALL·E 3による画像生成の設定を行います。</p>';
        }, 'aiap-lite-image_gen');

        add_settings_field('gen_featured', 'アイキャッチ自動生成', function() {
            $o = get_option(self::OPT_KEY, array());
            $checked = !empty($o['gen_featured']) ? 'checked' : '';
            
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[gen_featured]" value="1" ' . $checked . '> 生成する</label>';
            echo '<p><strong>画像生成の設定:</strong></p>';
            echo '<p>テーマ：<br>';
            echo '<input type="text" name="' . esc_attr(self::OPT_KEY) . '[theme]" value="' . esc_attr(isset($o['theme']) ? $o['theme'] : 'AGA（男性型脱毛）') . '" class="regular-text" placeholder="例: AGA（男性型脱毛）"/></p>';
            echo '<p>詳細：<br>';
            echo '<input type="text" name="' . esc_attr(self::OPT_KEY) . '[detail]" value="' . esc_attr(isset($o['detail']) ? $o['detail'] : '抽象的・医療系のビジュアル（頭皮/毛髪イメージ、分子・図形、清潔感のある背景）') . '" class="regular-text" placeholder="例: 抽象的・医療系のビジュアル（頭皮/毛髪イメージ、分子・図形、清潔感のある背景）"/></p>';
        }, 'aiap-lite-image_gen', 'image_gen');

        // === コンテンツ設定タブ ===
        add_settings_section('content_gen', 'コンテンツ生成設定', function() {
            echo '<p>記事生成のテーマと内容を設定します。</p>';
        }, 'aiap-lite-content_gen');

        // 大項目設定
        add_settings_field('main_topic', '大項目（メインテーマ）', function() {
            $o = get_option(self::OPT_KEY, array());
            $main_topic = isset($o['main_topic']) ? $o['main_topic'] : 'AGA';
            $main_topic_desc = isset($o['main_topic_desc']) ? $o['main_topic_desc'] : '男性型脱毛症の治療と対策について'; ?>
            <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[main_topic]" 
                   value="<?php echo esc_attr($main_topic); ?>" class="regular-text"
                   placeholder="例: AGA"/>
            <p class="description">記事全体のメインテーマを設定します</p>
            <p style="margin-top: 10px;">テーマの説明：</p>
            <textarea name="<?php echo esc_attr(self::OPT_KEY); ?>[main_topic_desc]" 
                      rows="2" class="large-text" 
                      placeholder="テーマの詳細な説明を入力してください"><?php 
                echo esc_textarea($main_topic_desc); 
            ?></textarea>
        <?php }, 'aiap-lite-content_gen', 'content_gen');

        // 中項目の基本設定
        add_settings_field('sub_topics_base', '中項目の基本設定', function() {
            $o = get_option(self::OPT_KEY, array());
            $sub_topics_style = isset($o['sub_topics_style']) ? $o['sub_topics_style'] : 'medical';
            $sub_topics_tone = isset($o['sub_topics_tone']) ? $o['sub_topics_tone'] : 'professional'; ?>
            
            <p>記事スタイル：</p>
            <select name="<?php echo esc_attr(self::OPT_KEY); ?>[sub_topics_style]" style="width: 200px;">
                <option value="medical" <?php selected($sub_topics_style, 'medical'); ?>>医療系</option>
                <option value="lifestyle" <?php selected($sub_topics_style, 'lifestyle'); ?>>ライフスタイル</option>
                <option value="business" <?php selected($sub_topics_style, 'business'); ?>>ビジネス</option>
                <option value="casual" <?php selected($sub_topics_style, 'casual'); ?>>カジュアル</option>
            </select>
            
            <p style="margin-top: 15px;">文章のトーン：</p>
            <select name="<?php echo esc_attr(self::OPT_KEY); ?>[sub_topics_tone]" style="width: 200px;">
                <option value="professional" <?php selected($sub_topics_tone, 'professional'); ?>>専門的</option>
                <option value="friendly" <?php selected($sub_topics_tone, 'friendly'); ?>>親しみやすい</option>
                <option value="formal" <?php selected($sub_topics_tone, 'formal'); ?>>フォーマル</option>
                <option value="casual" <?php selected($sub_topics_tone, 'casual'); ?>>カジュアル</option>
            </select>
            
            <p class="description">生成される記事全体の文体や雰囲気を設定します</p>
        <?php }, 'aiap-lite-content_gen', 'content_gen');

        // 中項目の管理
        add_settings_field('sub_topics_manager', '中項目の管理', function() {
            $o = get_option(self::OPT_KEY, array());
            $sub_topics = isset($o['sub_topics']) ? $o['sub_topics'] : array(
                array(
                    'id' => 'topic_1',
                    'title' => '治療法と効果',
                    'keywords' => '治療,効果,改善,期間',
                    'enabled' => true
                ),
                array(
                    'id' => 'topic_2',
                    'title' => '費用と料金比較',
                    'keywords' => '費用,料金,保険,比較',
                    'enabled' => true
                )
            );
            ?>
            <div id="sub-topics-manager">
                <!-- 新規追加フォーム -->
                <div class="add-new-topic" style="margin-bottom: 20px; padding: 15px; background: #f8f8f8; border-radius: 5px;">
                    <h4 style="margin-top: 0;">新規項目の追加</h4>
                    <div style="display: flex; gap: 10px; align-items: flex-start;">
                        <div style="flex: 2;">
                            <input type="text" id="new-topic-title" class="regular-text" 
                                   placeholder="項目タイトル（例：治療法と効果）" style="width: 100%;">
                        </div>
                        <div style="flex: 3;">
                            <input type="text" id="new-topic-keywords" class="regular-text" 
                                   placeholder="キーワード（カンマ区切り。例：治療,効果,改善,期間）" style="width: 100%;">
                        </div>
                        <div>
                            <button type="button" class="button button-secondary" id="add-topic-btn">追加</button>
                        </div>
                    </div>
                </div>

                <!-- 項目リスト -->
                <div id="topics-list">
                    <?php foreach ($sub_topics as $topic) : ?>
                        <div class="topic-item" data-id="<?php echo esc_attr($topic['id']); ?>" 
                             style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; display: flex; align-items: center; gap: 10px;">
                            <div style="flex: 1;">
                                <input type="hidden" name="<?php echo esc_attr(self::OPT_KEY); ?>[sub_topics][<?php echo esc_attr($topic['id']); ?>][id]" 
                                       value="<?php echo esc_attr($topic['id']); ?>">
                                <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[sub_topics][<?php echo esc_attr($topic['id']); ?>][title]" 
                                       value="<?php echo esc_attr($topic['title']); ?>" class="regular-text" style="width: 100%;">
                            </div>
                            <div style="flex: 2;">
                                <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[sub_topics][<?php echo esc_attr($topic['id']); ?>][keywords]" 
                                       value="<?php echo esc_attr($topic['keywords']); ?>" class="regular-text" style="width: 100%;">
                            </div>
                            <div>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[sub_topics][<?php echo esc_attr($topic['id']); ?>][enabled]" 
                                           value="1" <?php checked($topic['enabled'], true); ?>>
                                    有効
                                </label>
                            </div>
                            <div>
                                <button type="button" class="button button-link-delete delete-topic-btn">削除</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="description">中項目を管理します。有効な項目からランダムに選択して記事が生成されます。</p>
            </div>
        <?php }, 'aiap-lite-content_gen', 'content_gen');

        // 大項目設定
        add_settings_field('main_topic', '大項目（メインテーマ）', function() {
            $o = get_option(self::OPT_KEY, array());
            $main_topic = isset($o['main_topic']) ? $o['main_topic'] : 'AGA'; ?>
            <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[main_topic]" 
                   value="<?php echo esc_attr($main_topic); ?>" class="regular-text"
                   placeholder="例: AGA"/>
            <p class="description">記事全体のメインテーマを設定します</p>
        <?php }, 'aiap-lite-content_gen', 'content_gen');

        // 中項目と小項目の設定
        add_settings_field('topic_structure', '記事構造設定', function() {
            $o = get_option(self::OPT_KEY, array());
            
            // デフォルトの構造
            $default_structure = array(
                '治療薬・お薬' => array(
                    'enabled' => true,
                    'details' => "フィナステリド\nデュタステリド\nミノキシジル\nプロペシア\nザガーロ"
                ),
                'クリニック比較' => array(
                    'enabled' => true,
                    'details' => ""
                ),
                '費用・料金' => array(
                    'enabled' => true,
                    'details' => ""
                ),
                '治療効果' => array(
                    'enabled' => true,
                    'details' => ""
                ),
                '副作用・リスク' => array(
                    'enabled' => true,
                    'details' => ""
                ),
                '選び方・基準' => array(
                    'enabled' => true,
                    'details' => ""
                ),
                '体験談・口コミ' => array(
                    'enabled' => true,
                    'details' => ""
                )
            );

            $structure = isset($o['topic_structure']) ? $o['topic_structure'] : $default_structure;
            
            echo '<div style="margin-bottom: 20px;">';
            echo '<p class="description">中項目ごとに生成の有効/無効を設定し、必要な場合は小項目を指定できます。</p>';
            echo '</div>';
            
            foreach ($default_structure as $topic => $default_config) {
                $config = isset($structure[$topic]) ? $structure[$topic] : $default_config;
                $enabled = isset($config['enabled']) ? $config['enabled'] : true;
                $details = isset($config['details']) ? $config['details'] : '';
                
                echo '<div style="margin-bottom: 20px; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">';
                // 中項目の有効/無効
                echo '<label style="margin-bottom: 10px; display: block;">';
                echo '<input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[topic_structure][' . esc_attr($topic) . '][enabled]" value="1" ' . 
                     checked($enabled, true, false) . '/> ';
                echo '<strong>' . esc_html($topic) . '</strong></label>';
                
                // 小項目入力欄
                echo '<div style="margin-left: 20px;">';
                echo '<textarea name="' . esc_attr(self::OPT_KEY) . '[topic_structure][' . esc_attr($topic) . '][details]" ' .
                     'rows="3" style="width: 100%;" placeholder="小項目が必要な場合のみ入力（1行1項目）">' . 
                     esc_textarea($details) . '</textarea>';
                echo '</div>';
                echo '</div>';
            }
        }, 'aiap-lite', 'content_gen');

        add_settings_field('gen_featured', 'アイキャッチ自動生成（DALL·E 3）', function() {
            $o = get_option(self::OPT_KEY, array());
            $checked = !empty($o['gen_featured']) ? 'checked' : '';
            
            // デフォルトのプロンプトテンプレート
            $default_prompt = "Web記事のヒーロー画像。テーマは{theme}。横長の構図。\n"
                . "内容を象徴する{detail}\n"
                . "人物の顔の再現やテキスト埋め込みは不要。ロゴ不要。高級感・清潔感・信頼感。\n"
                . "画像は横長のレイアウトで、Webサイトのヘッダーイメージとして最適化。";
            
            $prompt_template = isset($o['prompt_template']) ? $o['prompt_template'] : $default_prompt;
            
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[gen_featured]" value="1" ' . $checked . '> 生成する</label>';
            echo '<p><strong>画像生成の設定:</strong></p>';
            echo '<p>テーマ：<br>';
            echo '<input type="text" name="' . esc_attr(self::OPT_KEY) . '[theme]" value="' . esc_attr(isset($o['theme']) ? $o['theme'] : 'AGA（男性型脱毛）') . '" class="regular-text" placeholder="例: AGA（男性型脱毛）"/></p>';
            echo '<p>詳細：<br>';
            echo '<input type="text" name="' . esc_attr(self::OPT_KEY) . '[detail]" value="' . esc_attr(isset($o['detail']) ? $o['detail'] : '抽象的・医療系のビジュアル（頭皮/毛髪イメージ、分子・図形、清潔感のある背景）') . '" class="regular-text" placeholder="例: 抽象的・医療系のビジュアル（頭皮/毛髪イメージ、分子・図形、清潔感のある背景）"/></p>';
        }, 'aiap-lite', 'main');
    }

    function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_aiap-lite') return;

        wp_enqueue_script(
            'aiap-admin',
            plugins_url('js/admin.js', __FILE__),
            array('jquery'),
            '1.0.0',
            true
        );

        wp_localize_script('aiap-admin', 'aiapAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aiap_ajax_nonce')
        ));
    }

    function page() {
        // 現在のタブを取得
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api_settings';
        
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // 利用可能なタブ
        $tabs = array(
            'api_settings' => array(
                'name' => 'OpenAI設定',
                'sections' => array('main')
            ),
            'image_settings' => array(
                'name' => 'アイキャッチ設定',
                'sections' => array('image_gen')
            ),
            'post_settings' => array(
                'name' => '投稿設定',
                'sections' => array('post_config')
            ),
            'content_settings' => array(
                'name' => 'コンテンツ設定',
                'sections' => array('content_gen')
            )
        );
        ?>
        <div class="wrap">
            <h1>AI Auto Poster Lite (Box)</h1>
            
            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_key => $tab) : ?>
                    <a href="?page=aiap-lite&tab=<?php echo esc_attr($tab_key); ?>" 
                       class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab['name']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="options.php">
                <?php 
                settings_fields('aiap_lite_group');
                
                // 現在のタブのセクションのみを表示
                if (isset($tabs[$current_tab])) {
                    foreach ($tabs[$current_tab]['sections'] as $section) {
                        do_settings_sections('aiap-lite-' . $section);
                    }
                }
                
                submit_button(); 
                ?>
            </form>

            <?php if ($current_tab === 'content_settings') : ?>
                <hr>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::ACTION_RUN); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RUN); ?>">
                    <?php submit_button('今すぐ実行（テスト投稿）', 'secondary'); ?>
                </form>
            <?php endif; ?>
        </div>
    <?php }

    /* ========== 通知 ========== */
    function admin_notices() {
        if (!current_user_can('manage_options')) return;
        $notice = get_transient(self::NOTICE_KEY);
        if (!$notice) return;
        delete_transient(self::NOTICE_KEY);
        $class = ($notice['type'] === 'error') ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
        echo '<div class="' . esc_attr($class) . '"><p>';
        echo wp_kses_post($notice['message']);
        if (!empty($notice['edit_link'])) {
            echo ' <a target="_blank" href="' . esc_url($notice['edit_link']) . '">編集画面を開く</a>';
        }
        echo '</p></div>';
    }

    function ajax_load_tab() {
        check_ajax_referer('aiap_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません');
            return;
        }
        
        $tab = isset($_POST['tab']) ? sanitize_key($_POST['tab']) : 'api_settings';
        
        ob_start();
        
        // タブに応じたコンテンツを出力
        if (isset($this->tabs[$tab])) {
            foreach ($this->tabs[$tab]['sections'] as $section) {
                do_settings_sections('aiap-lite-' . $section);
            }
            submit_button();
            
            if ($tab === 'content_settings') {
                echo '<hr>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field(self::ACTION_RUN);
                echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_RUN) . '">';
                submit_button('今すぐ実行（テスト投稿）', 'secondary');
                echo '</form>';
            }
        }
        
        $content = ob_get_clean();
        wp_send_json_success($content);
    }

    private function set_notice($type, $message, $edit_link = '') {
        $notice_message = $message;
        if ($edit_link) {
            $notice_message .= ' <a target="_blank" href="' . esc_url($edit_link) . '">編集画面を開く</a>';
        }
        if ($logs = $this->logger->format_logs()) {
            $notice_message .= $logs;
        }

        set_transient(self::NOTICE_KEY, array(
            'type'      => $type,
            'message'   => $notice_message,
            'edit_link' => ''  // 既にメッセージに含めたので空に
        ), 90);
    }

    /* ========== スタイル ========== */
    function styles() {
        wp_add_inline_style('wp-block-library', '
            .under-line-yellow{background:linear-gradient(transparent 60%,#fff799 60%)}
            .under-line-pink{background:linear-gradient(transparent 60%,#ffcece 60%)}
            .box_001{border:2px solid #95ccff;border-radius:8px;margin:2em 0}
            .box_001-title{background:#95ccff;color:#fff;padding:.8em;border-radius:8px 8px 0 0;text-align:center;font-weight:700}
            .box_001-content{padding:1.2em}
            .box_002{border:2px solid #95ccff;background:#f9fbff;border-radius:8px;margin:2em 0;padding:1.2em}
            .point-box{border:2px solid #ffd700;background:#fffef4;border-radius:8px;margin:2em 0}
            .point-box-title{background:#ffd700;padding:.8em;border-radius:8px 8px 0 0;text-align:center;font-weight:700}
            .point-box-content{padding:1.2em}
        ');
    }

    /* ========== 実行メイン ========== */
    function handle_run() {
        try {
            if (!current_user_can('manage_options')) wp_die('Forbidden');
            check_admin_referer(self::ACTION_RUN);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_reporting(E_ALL);
                ini_set('display_errors', 1);
            }

            $o = get_option(self::OPT_KEY, array());
            $api_key = trim(isset($o['api_key']) ? $o['api_key'] : '');
            if (!$api_key) {
                throw new Exception('APIキーが未設定です。設定画面で保存してください。');
            }

            // 題材を生成
            list($title, $angle, $outline) = $this->content_generator->generate_topic($api_key);
            
            // 本文を生成
            $data = $this->content_generator->generate_content($api_key, $title, $angle, $outline);
            $final_title = sanitize_text_field(isset($data['title']) ? $data['title'] : $title);
            
            // ブロックに変換
            $this->block_converter = new AIAP_Block_Converter($this->content_generator->get_current_angle());
            $post_content = $this->block_converter->convert_sections($data['sections']);
            
            if (function_exists('parse_blocks') && function_exists('serialize_blocks')) {
                $blocks = parse_blocks($post_content);
                $post_content = serialize_blocks($blocks);
            }

            // 投稿を作成
            $post_id = wp_insert_post(array(
                'post_title'   => $final_title,
                'post_content' => $post_content,
                'post_status'  => isset($o['post_status']) ? $o['post_status'] : 'draft',
                'post_author'  => get_current_user_id() ?: 1
            ), true);

            if (is_wp_error($post_id)) {
                throw new Exception($post_id->get_error_message());
            }

            // アイキャッチ画像を生成
            if (!empty($o['gen_featured'])) {
                try {
                    $hint = trim(isset($o['image_hint']) ? $o['image_hint'] : '');
                    $img_prompt = $this->image_generator->build_prompt($final_title, $angle, $hint);
                    $this->logger->log('🎨 画像生成プロンプト: ' . $img_prompt);

                    $err = '';
                    $b64 = $this->openai_client->generate_image($api_key, $img_prompt, '1792x1024', $err);
                    
                    if (!$b64) {
                        error_log('AI Auto Poster 横長画像生成失敗、正方形で再試行: ' . $err);
                        $b64 = $this->openai_client->generate_image($api_key, $img_prompt, '1024x1024', $err);
                    }
                    
                    if (!$b64) {
                        throw new Exception('画像生成に失敗: ' . $err);
                    }

                    $msg = '';
                    $att_id = $this->image_generator->save_as_attachment($b64, $final_title, $post_id, $msg);
                    
                    if (!$att_id) {
                        throw new Exception('画像の保存に失敗: ' . $msg);
                    }

                    $this->image_generator->set_featured_image($post_id, $att_id);

                } catch (Exception $e) {
                    error_log('AI Auto Poster アイキャッチ処理エラー: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                    $this->set_notice('error', '記事は作成、ただしアイキャッチ未設定: ' . $e->getMessage(), get_edit_post_link($post_id, ''));
                    wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
                }
            }

            $this->set_notice('success', '記事生成が完了しました。', get_edit_post_link($post_id, '')); 
            wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;

        } catch (Exception $e) {
            error_log('AI Auto Poster 致命エラー: ' . $e->getMessage());
            $this->set_notice('error', 'エラーが発生しました: ' . $e->getMessage());
            wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
        }
    }
}

new AIAP_Lite_Box();