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
        add_settings_section('main', '基本設定', '__return_false', 'aiap-lite');

        add_settings_field('api_key', 'OpenAI API Key', function() {
            $o = get_option(self::OPT_KEY, array());
            printf('<input type="password" name="%s[api_key]" value="%s" class="regular-text" />',
                esc_attr(self::OPT_KEY), esc_attr(isset($o['api_key'])?$o['api_key']:''));
        }, 'aiap-lite', 'main');

        // 投稿ステータス設定
        add_settings_field('post_status', '投稿ステータス', function() {
            $o = get_option(self::OPT_KEY, array());
            $v = isset($o['post_status']) ? $o['post_status'] : 'draft'; ?>
            <select name="<?php echo esc_attr(self::OPT_KEY); ?>[post_status]">
                <option value="publish" <?php selected($v, 'publish'); ?>>公開</option>
                <option value="draft"   <?php selected($v, 'draft');   ?>>下書き</option>
            </select>
        <?php }, 'aiap-lite', 'main');

        // コンテンツ生成設定セクション
        add_settings_section('content_gen', 'コンテンツ生成設定', function() {
            echo '<p>記事生成のテーマと内容を設定します。</p>';
        }, 'aiap-lite');

        // 大項目設定
        add_settings_field('main_topic', '大項目（メインテーマ）', function() {
            $o = get_option(self::OPT_KEY, array());
            $main_topic = isset($o['main_topic']) ? $o['main_topic'] : 'AGA'; ?>
            <input type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[main_topic]" 
                   value="<?php echo esc_attr($main_topic); ?>" class="regular-text"
                   placeholder="例: AGA"/>
            <p class="description">記事全体のメインテーマを設定します</p>
        <?php }, 'aiap-lite', 'content_gen');

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

    function page() { ?>
        <div class="wrap">
            <h1>AI Auto Poster Lite (Box)</h1>
            <form method="post" action="options.php">
                <?php settings_fields('aiap_lite_group'); do_settings_sections('aiap-lite'); submit_button(); ?>
            </form>
            <hr>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::ACTION_RUN); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RUN); ?>">
                <?php submit_button('今すぐ実行（テスト投稿）', 'secondary'); ?>
            </form>
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