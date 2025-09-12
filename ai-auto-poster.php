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
    const LOCK_KEY   = 'aiap_lite_lock';

    private $logger;
    private $openai_client;
    private $image_generator;
    private $content_generator;
    private $block_converter;
    private $tabs;

    function __construct() {
        // 各クラスを初期化
        $this->logger = new AIAP_Logger();
        $this->openai_client = new AIAP_OpenAI_Client();
        $this->image_generator = new AIAP_Image_Generator($this->logger);
        $this->content_generator = new AIAP_Content_Generator($this->openai_client, $this->logger);
        $this->block_converter = new AIAP_Block_Converter();

        // タブ設定
        // タブIDとページスラッグの対応：
        // api_settings     -> aiap-lite-main        (OpenAI設定タブ)
        // image_settings   -> aiap-lite-image_gen   (アイキャッチ設定タブ)
        // post_settings    -> aiap-lite-post_config (投稿設定タブ)
        // content_settings -> aiap-lite-content_gen (コンテンツ設定タブ)
        $this->tabs = array(
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

        // テーマ設定前にサムネイルサポートを有効化
        add_action('after_setup_theme',  array($this, 'ensure_thumbnails'), 9);
        add_action('init',               array($this, 'ensure_thumbnails'));
        
        // その他の通常の初期化
        add_action('admin_menu',         array($this, 'menu'));
        add_action('admin_init',         array($this, 'register'));
        add_action('wp_enqueue_scripts', array($this, 'styles'));
        add_action('admin_notices',      array($this, 'admin_notices'));
        
        // 記事生成処理
        add_action('admin_post_aiap_queue_job', array($this, 'handle_queue_job'));
        
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
        register_setting('aiap_lite_group', self::OPT_KEY, array(
            'type' => 'array',
            'sanitize_callback' => function($value) {
                if (!is_array($value)) {
                    $value = array();
                }

                // 既存の設定を取得
                $current_settings = get_option(self::OPT_KEY, array());
                
                // 現在のタブを取得
                $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api_settings';
                
                // タブごとの設定キーを定義
                $tab_settings = array(
                    'api_settings' => array('api_key'),
                    'image_settings' => array('gen_featured', 'theme', 'detail'),
                    'post_settings' => array('post_status'),
                    'content_settings' => array('main_topic', 'main_topic_desc', 'topic_keywords', 'topic_enabled')
                );
                
                // 現在のタブ以外の設定を保持
                foreach ($tab_settings as $tab => $fields) {
                    if ($tab !== $current_tab) {
                        foreach ($fields as $field) {
                            if (isset($current_settings[$field])) {
                                $value[$field] = $current_settings[$field];
                            }
                        }
                    }
                }
                
                // APIキーのサニタイズ
                if (isset($value['api_key'])) {
                    $value['api_key'] = trim(sanitize_text_field($value['api_key']));
                }
                
                // その他の設定のサニタイズ
                $text_fields = array('main_topic', 'main_topic_desc', 'theme', 'detail');
                foreach ($text_fields as $field) {
                    if (isset($value[$field])) {
                        $value[$field] = sanitize_text_field($value[$field]);
                    }
                }
                
                // カテゴリ関連の設定
                if (isset($value['topic_keywords']) && is_array($value['topic_keywords'])) {
                    foreach ($value['topic_keywords'] as $cat_id => $keywords) {
                        $value['topic_keywords'][$cat_id] = sanitize_text_field($keywords);
                    }
                }
                
                if (isset($value['topic_enabled']) && is_array($value['topic_enabled'])) {
                    foreach ($value['topic_enabled'] as $cat_id => $enabled) {
                        $value['topic_enabled'][$cat_id] = $enabled ? '1' : '0';
                    }
                }
                
                return $value;
            }
        ));

        // 中項目の追加・削除処理
        if (isset($_POST['add_topic']) && isset($_POST['_wpnonce'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'aiap_topic_action')) {
                $new_title = sanitize_text_field($_POST['new_topic_title']);
                $new_keywords = sanitize_text_field($_POST['new_topic_keywords']);
                
                if (!empty($new_title)) {
                    $o = get_option(self::OPT_KEY, array());
                    $new_id = 'topic_' . time();
                    
                    $sub_topics = isset($o['sub_topics']) ? $o['sub_topics'] : array();
                    $sub_topics[$new_id] = array(
                        'id' => $new_id,
                        'title' => $new_title,
                        'keywords' => $new_keywords,
                        'enabled' => true
                    );
                    
                    $o['sub_topics'] = $sub_topics;
                    update_option(self::OPT_KEY, $o);
                    
                    $this->set_notice('success', '新しい項目を追加しました。');
                }
            }
            wp_redirect(add_query_arg(array('page' => 'aiap-lite', 'tab' => 'content_settings'), admin_url('options-general.php')));
            exit;
        }

        if (isset($_POST['delete_topic']) && isset($_POST['_wpnonce'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'aiap_topic_action')) {
                $topic_id = sanitize_text_field($_POST['topic_id']);
                $o = get_option(self::OPT_KEY, array());
                
                if (isset($o['sub_topics'][$topic_id])) {
                    $title = $o['sub_topics'][$topic_id]['title'];
                    unset($o['sub_topics'][$topic_id]);
                    update_option(self::OPT_KEY, $o);
                    
                    $this->set_notice('success', sprintf('「%s」を削除しました。', esc_html($title)));
                }
            }
            wp_redirect(add_query_arg(array('page' => 'aiap-lite', 'tab' => 'content_settings'), admin_url('options-general.php')));
            exit;
        }

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

        // 中項目の管理（カテゴリベース）
        add_settings_field('sub_topics_manager', '中項目の管理', function() {
            $o = get_option(self::OPT_KEY, array());
            $categories = get_categories(array(
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC'
            ));

            // キーワード設定を取得
            $topic_keywords = isset($o['topic_keywords']) ? $o['topic_keywords'] : array();
            $topic_enabled = isset($o['topic_enabled']) ? $o['topic_enabled'] : array();
            ?>
            <div class="sub-topics-manager">
                <div class="topics-list">
                    <?php if (!empty($categories)) : ?>
                        <?php foreach ($categories as $category) : ?>
                            <div class="topic-item" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <span class="topic-title" style="font-weight: bold; min-width: 200px;">
                                        <?php echo esc_html($category->name); ?>
                                    </span>
                                    <input type="text" 
                                           name="<?php echo esc_attr(self::OPT_KEY); ?>[topic_keywords][<?php echo esc_attr($category->term_id); ?>]" 
                                           value="<?php echo esc_attr(isset($topic_keywords[$category->term_id]) ? $topic_keywords[$category->term_id] : ''); ?>" 
                                           placeholder="キーワード（カンマ区切り）"
                                           class="regular-text"
                                           style="flex: 1;">
                                    <label style="white-space: nowrap;">
                                        <input type="checkbox" 
                                               name="<?php echo esc_attr(self::OPT_KEY); ?>[topic_enabled][<?php echo esc_attr($category->term_id); ?>]" 
                                               value="1"
                                               <?php checked(isset($topic_enabled[$category->term_id]) && $topic_enabled[$category->term_id]); ?>>
                                        有効
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="notice notice-warning">
                            <p>カテゴリが設定されていません。WordPressの投稿カテゴリを追加してください。</p>
                        </div>
                    <?php endif; ?>
                </div>
                <p class="description">カテゴリから中項目を管理します。有効な項目からランダムに選択して記事が生成されます。<br>カテゴリの追加・編集・削除は投稿 > カテゴリから行ってください。</p>
            </div>
            <?php
        }, 'aiap-lite-content_gen', 'content_gen');

        // 中項目と小項目の設定
    }


    private function show_job_status() {
        // 通知は admin_notices で処理するため、ここでは何もしない
        return;
    }

    function page() {
        // 現在のタブを取得
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api_settings';
        ?>
        <div class="wrap">
            <h1>AI Auto Poster Lite (Box)</h1>
            
            <?php $this->show_job_status(); ?>
            
            <nav class="nav-tab-wrapper">
                <?php foreach ($this->tabs as $tab_key => $tab) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'aiap-lite', 'tab' => $tab_key], admin_url('options-general.php'))); ?>" 
                       class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab['name']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="aiap-content">
                <form method="post" action="options.php">
                <?php 
                settings_fields('aiap_lite_group');
                
                // 現在のタブのセクションのみを表示
                if (isset($this->tabs[$current_tab])) {
                    foreach ($this->tabs[$current_tab]['sections'] as $section) {
                        do_settings_sections('aiap-lite-' . $section);
                    }
                }
                
                submit_button(); 
                ?>
                </form>

                <?php if ($current_tab === 'content_settings') : ?>
                    <?php if ($this->is_locked()) : ?>
                        <div class="notice notice-info">
                            <p>
                                <span class="spinner is-active" style="float:left;margin:0 8px 0 0;"></span>
                                記事を生成中です。このままお待ちください...
                            </p>
                        </div>
                    <?php else : ?>
                        <div class="aiap-test-run" style="margin: 20px 0;">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('aiap_run'); ?>
                                <input type="hidden" name="action" value="aiap_queue_job">
                                <button type="submit" class="button button-secondary">今すぐ実行（テスト投稿）</button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
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


    private function is_locked() {
        $lock = get_transient(self::LOCK_KEY);
        if ($lock) {
            $lock_time = strtotime($lock['started_at']);
            $now = time();
            
            // 10分以上経過したロックは無効とする
            if (($now - $lock_time) > 600) {
                delete_transient(self::LOCK_KEY);
                return false;
            }
            return true;
        }
        return false;
    }

    private function set_lock() {
        return set_transient(self::LOCK_KEY, [
            'started_at' => current_time('mysql'),
            'user_id' => get_current_user_id()
        ], 600); // 10分でタイムアウト
    }

    private function release_lock() {
        delete_transient(self::LOCK_KEY);
    }

    function handle_queue_job() {
        check_admin_referer('aiap_run');
        
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません');
        }

        // 実行中チェック
        if ($this->is_locked()) {
            $this->set_notice('error', '記事生成の処理が既に実行中です。完了までお待ちください。');
            wp_safe_redirect(admin_url('options-general.php?page=aiap-lite&tab=content_settings'));
            exit;
        }
        
        // APIキーチェック
        $o = get_option(self::OPT_KEY, array());
        $api_key = trim(isset($o['api_key']) ? $o['api_key'] : '');
        if (!$api_key) {
            $this->set_notice('error', 'APIキーが未設定です。設定画面で保存してください。');
            wp_safe_redirect(admin_url('options-general.php?page=aiap-lite&tab=api_settings'));
            exit;
        }
        
        try {
            // ロックを設定
            $this->set_lock();
            
            // 実行時間を延長（5分）
            set_time_limit(300);
            
            // APIキーチェック
            $o = get_option(self::OPT_KEY, array());
            $api_key = trim(isset($o['api_key']) ? $o['api_key'] : '');
            if (!$api_key) {
                throw new Exception('APIキーが未設定です');
            }

            // 有効な中項目（カテゴリ）があるかチェック
            $topic_enabled = isset($o['topic_enabled']) ? $o['topic_enabled'] : array();
            $enabled_categories = array_filter($topic_enabled, function($enabled) {
                return $enabled === '1';
            });
            
            if (empty($enabled_categories)) {
                throw new Exception('有効な中項目が設定されていません。少なくとも1つのカテゴリを有効にしてください。');
            }

            // 題材生成
            list($title, $angle, $outline) = $this->content_generator->generate_topic($api_key);
            
            // 本文生成
            $data = $this->content_generator->generate_content($api_key, $title, $angle, $outline);
            $final_title = sanitize_text_field(isset($data['title']) ? $data['title'] : $title);
            
            // 投稿作成
            $post_content = $this->block_converter->convert_sections($data['sections']);
            
            if (function_exists('parse_blocks') && function_exists('serialize_blocks')) {
                $blocks = parse_blocks($post_content);
                $post_content = serialize_blocks($blocks);
            }

            // 投稿を作成
            $post_data = array(
                'post_title'   => $final_title,
                'post_content' => $post_content,
                'post_status'  => isset($o['post_status']) ? $o['post_status'] : 'draft',
                'post_author'  => get_current_user_id() ?: 1
            );

            // 選択されたカテゴリを設定
            $category_id = $this->content_generator->get_current_category_id();
            if ($category_id) {
                $post_data['post_category'] = array($category_id);
            }

            $post_id = wp_insert_post($post_data, true);

            if (is_wp_error($post_id)) {
                throw new Exception($post_id->get_error_message());
            }

            // アイキャッチ画像生成
            if (!empty($o['gen_featured'])) {
                try {
                    $hint = trim(isset($o['image_hint']) ? $o['image_hint'] : '');
                    $img_prompt = $this->image_generator->build_prompt($final_title, $angle, $hint);
                    
                    $err = '';
                    $b64 = $this->openai_client->generate_image($api_key, $img_prompt, '1792x1024', $err);
                    
                    if (!$b64) {
                        $b64 = $this->openai_client->generate_image($api_key, $img_prompt, '1024x1024', $err);
                    }
                    
                    if ($b64) {
                        $msg = '';
                        $att_id = $this->image_generator->save_as_attachment($b64, $final_title, $post_id, $msg);
                        if ($att_id) {
                            $this->image_generator->set_featured_image($post_id, $att_id);
                        }
                    }
                } catch (Exception $e) {
                    error_log('AI Auto Poster アイキャッチ生成エラー: ' . $e->getMessage());
                    $this->set_notice('warning', '記事は作成しましたが、画像生成に失敗: ' . $e->getMessage(), get_edit_post_link($post_id, ''));
                    wp_safe_redirect(admin_url('options-general.php?page=aiap-lite&tab=content_settings'));
                    exit;
                }
            }

            // 完了通知とリダイレクト
            $this->set_notice('success', '記事生成が完了しました', get_edit_post_link($post_id, ''));
            $this->release_lock();
            
            // リダイレクト先のURLを生成（現在の設定を保持）
            $redirect_url = add_query_arg(
                array(
                    'page' => 'aiap-lite',
                    'tab' => 'content_settings',
                    'settings-updated' => 1
                ),
                admin_url('options-general.php')
            );
            wp_safe_redirect($redirect_url);
            exit;

        } catch (Exception $e) {
            error_log('AI Auto Poster 記事生成エラー: ' . $e->getMessage());
            $this->set_notice('error', 'エラーが発生しました: ' . $e->getMessage());
            $this->release_lock();
            // リダイレクト先のURLを生成（現在の設定を保持）
            $redirect_url = add_query_arg(
                array(
                    'page' => 'aiap-lite',
                    'tab' => 'content_settings',
                    'settings-updated' => 1
                ),
                admin_url('options-general.php')
            );
            wp_safe_redirect($redirect_url);
            exit;
        }
    }
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

}

new AIAP_Lite_Box();