<?php
/**
 * Plugin Name: AI Auto Poster Lite (Box)
 * Description: 最小構成。OpenAIのJSONをGutenbergブロックへ変換（見出し/段落/リスト/ボックス対応）+ タイトル/題材のバリエーション + 実行後は完了ログ表示 + アイキャッチ自動生成(DALL·E 3 フォールバック付)
 * Version: 0.4.3
 * Author: You
 * Requires PHP: 7.3
 */
if (!defined('ABSPATH')) exit;

class AIAP_Lite_Box {
    const OPT_KEY    = 'aiap_lite_settings';
    const ACTION_RUN = 'aiap_lite_run';
    const NOTICE_KEY = 'aiap_lite_notice';

    /** @var string 直近の題材 angle を保持（導入h3補正に使用） */
    private $current_angle = '';

    function __construct(){
        // テーマ設定前にサムネイルサポートを有効化
        add_action('after_setup_theme',  array($this,'ensure_thumbnails'), 9);
        add_action('init',               array($this,'ensure_thumbnails'));
        
        // その他の通常の初期化
        add_action('admin_menu',         array($this,'menu'));
        add_action('admin_init',         array($this,'register'));
        add_action('admin_post_'.self::ACTION_RUN, array($this,'handle_run'));
        add_action('wp_enqueue_scripts', array($this,'styles'));
        add_action('admin_notices',      array($this,'admin_notices'));
        
        error_log('AI Auto Poster: プラグイン初期化完了');
    }

    function ensure_thumbnails(){
        // 強制的にサムネイルサポートを有効化
        add_theme_support('post-thumbnails');
        add_post_type_support('post', 'thumbnail');
        error_log('AI Auto Poster: サムネイルサポート有効化');
    }

    /* ========== 管理UI ========== */
    function menu(){
        add_options_page('AI Auto Poster Lite','AI Auto Poster Lite','manage_options','aiap-lite',array($this,'page'));
    }
    function register(){
        register_setting('aiap_lite_group', self::OPT_KEY);
        add_settings_section('main','基本設定','__return_false','aiap-lite');

        add_settings_field('api_key','OpenAI API Key', function(){
            $o=get_option(self::OPT_KEY,array());
            printf('<input type="password" name="%s[api_key]" value="%s" class="regular-text" />',
                esc_attr(self::OPT_KEY), esc_attr(isset($o['api_key'])?$o['api_key']:''));
        }, 'aiap-lite','main');

        add_settings_field('post_status','投稿ステータス', function(){
            $o=get_option(self::OPT_KEY,array()); $v=isset($o['post_status'])?$o['post_status']:'draft'; ?>
            <select name="<?php echo esc_attr(self::OPT_KEY); ?>[post_status]">
                <option value="publish" <?php selected($v,'publish'); ?>>公開</option>
                <option value="draft"   <?php selected($v,'draft');   ?>>下書き</option>
            </select>
        <?php }, 'aiap-lite','main');

        add_settings_field('gen_featured','アイキャッチ自動生成（DALL·E 3）', function(){
            $o=get_option(self::OPT_KEY,array());
            $checked = !empty($o['gen_featured']) ? 'checked' : '';
            printf('<label><input type="checkbox" name="%s[gen_featured]" value="1" %s> 生成する</label><br/>', esc_attr(self::OPT_KEY), $checked);
            printf('<input type="text" name="%s[image_hint]" value="%s" class="regular-text" placeholder="例: 清潔感, 医療系ブログ向け, 青系トーン, シンプル"/>',
                esc_attr(self::OPT_KEY), esc_attr(isset($o['image_hint'])?$o['image_hint']:''));
        }, 'aiap-lite','main');
    }
    function page(){ ?>
        <div class="wrap">
            <h1>AI Auto Poster Lite (Box)</h1>
            <form method="post" action="options.php">
                <?php settings_fields('aiap_lite_group'); do_settings_sections('aiap-lite'); submit_button(); ?>
            </form>
            <hr>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::ACTION_RUN); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RUN); ?>">
                <?php submit_button('今すぐ実行（テスト投稿）','secondary'); ?>
            </form>
        </div>
    <?php }

    /* ========== 通知 ========== */
    function admin_notices(){
        if (!current_user_can('manage_options')) return;
        $notice = get_transient(self::NOTICE_KEY);
        if (!$notice) return;
        delete_transient(self::NOTICE_KEY);
        $class = ($notice['type']==='error') ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
        echo '<div class="'.esc_attr($class).'"><p>';
        echo wp_kses_post($notice['message']);
        if (!empty($notice['edit_link'])) {
            echo ' <a target="_blank" href="'.esc_url($notice['edit_link']).'">編集画面を開く</a>';
        }
        echo '</p></div>';
    }
    private $debug_logs = array();

    private function add_debug_log($message) {
        $this->debug_logs[] = $message;
    }

    private function set_notice($type, $message, $edit_link=''){
        // デバッグログを通知に追加
        if (!empty($this->debug_logs)) {
            $message .= '<br><br>📝 処理ログ:<br>' . implode('<br>', array_map('esc_html', $this->debug_logs));
        }

        set_transient(self::NOTICE_KEY, array(
            'type'      => $type,
            'message'   => $message,
            'edit_link' => $edit_link
        ), 90);
    }

    /* ========== スタイル ========== */
    function styles(){
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
    function handle_run(){
        try {
            if(!current_user_can('manage_options')) wp_die('Forbidden');
            check_admin_referer(self::ACTION_RUN);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_reporting(E_ALL);
                ini_set('display_errors', 1);
            }

            $o=get_option(self::OPT_KEY,array());
            $api_key=trim(isset($o['api_key'])?$o['api_key']:'');
            if(!$api_key){
                throw new Exception('APIキーが未設定です。設定画面で保存してください。');
            }

            $seed = wp_generate_uuid4();

            /* (1) 題材生成 */
            $system1 = 'あなたはAGA専門の日本語ライター。必ずJSONのみ返す。Markdown/HTMLは返さない。';
            $prompt1 = <<<EOT
根本テーマは「AGA」。ただし毎回、切り口を変え、同じ趣旨の繰り返しを避ける。
出力はJSON:
{
  "title": "タイトル（32字以内・句点なし）",
  "angle": "切り口をひとこと",
  "outline": ["H2見出し1","H2見出し2","H2見出し3","H2見出し4"]
}
ランダム種: {$seed}
EOT;
            $topic = $this->openai_json($api_key, $system1, $prompt1, 800, 0.8);
            if(!$topic || empty($topic['title'])){
                $this->set_notice('error','題材生成に失敗しました。');
                wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
            }
            $title   = sanitize_text_field($topic['title']);
            $angle   = sanitize_text_field(isset($topic['angle'])?$topic['angle']:'');
            $outline_in = isset($topic['outline']) ? (array)$topic['outline'] : array();
            $outline = array();
            foreach ($outline_in as $h2) {
                $h2 = sanitize_text_field($h2);
                if ($h2 !== '') $outline[] = $h2;
            }

            $this->current_angle = $angle; // 導入h3補正用

            /* (2) 本文生成 */
            $system2 = 'あなたは医療系SEOに精通した日本語ライター。必ず有効なJSONを返す。Markdown/HTMLは返さない。';
            $outline_json = json_encode($outline, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $prompt2 = <<<EOT
ヘアクリニック向けブログ。タイトルに沿い、angleとoutlineを反映。
- title: {$title}
- angle: {$angle}
- outline: {$outline_json}
分量: 1600〜2300字。

重要な注意点:
- 各セクションの内容は、必ずその直前のH2見出しに関連する内容のみを含める
- box_001は「主な症状」として、その見出しに関連する具体的な症状のみを列挙
- box_002は「ポイント」として、その見出しに関連する具体的なアドバイスのみを列挙
- 箇条書きの項目は必ず見出しの内容に直接関連するものだけを含める

JSON形式で:
{
 "title": "{$title}",
 "sections":[
   {"type":"heading","level":3,"text":"※angleを短く言い換えた実文サブタイトル（プレースホルダ不可）"},
   {"type":"paragraph","text":"導入文。読者に寄り添い、2段落程度で概要"},

   {"type":"heading","level":2,"text":"outlineの1番目"},
   {"type":"paragraph","text":"本文1","emphasis":[{"text":"重要な部分を強調","style":"strong"}]},
   {"type":"list","items":["ポイント1","ポイント2","ポイント3"]},

   {"type":"heading","level":2,"text":"outlineの2番目"},
   {"type":"paragraph","text":"本文2"},
   {"type":"box","style":"box_001","purpose":"signs","title":"","items":[
     "直前のH2の内容に直接関連する具体的な兆候や症状を4〜6個（必ず直前のH2の内容に沿った兆候のみを記載）"
   ]},

   {"type":"heading","level":2,"text":"outlineの3番目"},
   {"type":"paragraph","text":"本文3"},
   {"type":"box","style":"box_002","purpose":"actions","title":"","items":[
     "直前のH2の内容に直接関連する具体的な改善行動を3〜5個（必ず直前のH2の内容に沿った行動のみを記載）"
   ]},

   {"type":"heading","level":2,"text":"まとめ"},
   {"type":"box","style":"point-box","title":"重要ポイント","items":["本文要点を3〜5個"]},
   {"type":"paragraph","text":"次のアクションを自然に案内"}
 ]
}
ルール:
- 見出しに「導入のh3」「H2：〜」のようなプレースホルダは禁止。必ず実文。
- 箇条書きitemsは兆候と行動を混在させない。purposeに従う。
- ボックスの内容は必ず直前H2に関連。
- 同じ言い回しの連続を避ける（ランダム種: {$seed}）。
EOT;

            try{
                $data = $this->openai_json($api_key, $system2, $prompt2, 2200, 0.65);
                if (!is_array($data) || empty($data['sections'])) throw new Exception('JSON解析失敗');

                $final_title = sanitize_text_field(isset($data['title'])?$data['title']:$title);
                $post_content = $this->render_sections($data['sections']);
                if(function_exists('parse_blocks') && function_exists('serialize_blocks')){
                    $blocks = parse_blocks($post_content);
                    $post_content = serialize_blocks($blocks);
                }

                $post_id = wp_insert_post(array(
                    'post_title'  => $final_title,
                    'post_content'=> $post_content,
                    'post_status' => isset($o['post_status'])?$o['post_status']:'draft',
                    'post_author' => get_current_user_id()?:1
                ), true);
                if(is_wp_error($post_id)) throw new Exception($post_id->get_error_message());

                            /* (3) アイキャッチ生成 */
            if(!empty($o['gen_featured'])){
                try {
                    $hint = trim(isset($o['image_hint'])?$o['image_hint']:'');
                    $img_prompt = $this->build_image_prompt($final_title,$angle,$hint);
                    
                    // プロンプトをログに記録

                    $this->add_debug_log('🎨 画像生成プロンプト: ' . $img_prompt);

                    $err = '';
                    // まず横長サイズで試行
                    $b64 = $this->openai_image($api_key, $img_prompt, '1792x1024', $err);
                    
                    // 失敗した場合は正方形サイズで再試行
                    if (!$b64) {
                        error_log('AI Auto Poster 横長画像生成失敗、正方形で再試行: ' . $err);
                        $b64 = $this->openai_image($api_key, $img_prompt, '1024x1024', $err);
                    }
                    
                    if(!$b64) {
                        error_log('AI Auto Poster 画像生成失敗: ' . $err);
                        throw new Exception('画像生成に失敗: ' . $err);
                    }

                    $msg = '';
                    $att_id = $this->save_base64_image_as_attachment($b64, $final_title, $post_id, $msg);
                    
                    if(!$att_id) {
                        error_log('AI Auto Poster 画像保存失敗: ' . $msg);
                        throw new Exception('画像の保存に失敗: ' . $msg);
                    }

                    // アイキャッチ画像の設定
                    $this->add_debug_log('🔄 アイキャッチ画像設定開始');

                    // 既存のアイキャッチを削除
                    delete_post_thumbnail($post_id);
                    delete_post_meta($post_id, '_thumbnail_id');
                    
                    // 新しいアイキャッチを設定（複数の方法で試行）
                    $methods_tried = [];
                    
                    // アイキャッチ画像を設定
                    $result = set_post_thumbnail($post_id, $att_id);
                    
                    // 設定結果を確認
                    $current_thumbnail_id = get_post_thumbnail_id($post_id);
                    $current_meta = get_post_meta($post_id, '_thumbnail_id', true);
                    
                    if ($result && $current_thumbnail_id == $att_id) {
                        $this->add_debug_log('✅ アイキャッチ画像が正しく設定されました');
                        $this->add_debug_log('   thumbnail_id: ' . $current_thumbnail_id);
                        $this->add_debug_log('   meta値: ' . $current_meta);
                    } else {
                        $this->add_debug_log('⚠️ アイキャッチ画像の設定を確認:');
                        $this->add_debug_log('   set_post_thumbnail結果: ' . ($result ? '成功' : '失敗'));
                        $this->add_debug_log('   現在のthumbnail_id: ' . $current_thumbnail_id);
                        $this->add_debug_log('   現在のmeta値: ' . $current_meta);
                    }

                    // キャッシュをクリアして最終確認
                    clean_post_cache($post_id);
                    clean_attachment_cache($att_id);
                    wp_cache_delete($post_id, 'post_meta');
                    
                    $final_check_id = get_post_thumbnail_id($post_id);
                    $final_meta = get_post_meta($post_id, '_thumbnail_id', true);
                    
                    $this->add_debug_log('🔍 キャッシュクリア後の最終確認:');
                    $this->add_debug_log('   thumbnail_id: ' . $final_check_id);
                    $this->add_debug_log('   meta値: ' . $final_meta);

                    if ($check_id != $att_id && $meta_check != $att_id) {
                        throw new Exception('アイキャッチ画像の設定に失敗。ID不一致: ' . $att_id . ' vs ' . $check_id . '/' . $meta_check);
                    }

                    $this->add_debug_log('✅ アイキャッチ画像設定完了');

                    // キャッシュのクリア
                    clean_post_cache($post_id);
                    clean_attachment_cache($att_id);
                    
                    // 画像のURL取得とキャッシュ更新
                    $image_url = wp_get_attachment_url($att_id);
                    $this->add_debug_log('🖼 アイキャッチ画像URL: ' . $image_url);
                    
                    // 管理画面用のサムネイルを強制的に生成
                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                    
                    // アップロードディレクトリの取得
                    $upload_dir = wp_upload_dir();
                    
                    // 保存済みの添付ファイル情報を取得
                    $saved_file = get_post_meta($att_id, '_wp_attached_file', true);
                    $this->add_debug_log('📂 保存済みの添付ファイル: ' . $saved_file);

                    // 実際のファイルパスを構築
                    $file = $upload_dir['basedir'] . '/' . $saved_file;
                    $this->add_debug_log('📂 構築したファイルパス: ' . $file);

                    // ファイルの存在確認
                    if (!file_exists($file)) {
                        $this->add_debug_log('⚠️ ファイルが見つかりません。代替パスを試行します');
                        
                        // 代替パスを試行
                        $alt_paths = array(
                            $filepath,  // 直接保存したパス
                            $upload_dir['path'] . '/' . basename($saved_file),  // 現在の月のディレクトリ
                            $upload_dir['basedir'] . '/' . basename($saved_file)  // アップロードルート
                        );
                        
                        foreach ($alt_paths as $alt_path) {
                            $this->add_debug_log('🔍 代替パスを確認: ' . $alt_path);
                            if (file_exists($alt_path)) {
                                $file = $alt_path;
                                $this->add_debug_log('✅ 有効なファイルを発見: ' . $file);
                                break;
                            }
                        }
                    }

                    // ファイルが見つかった場合、メタデータを更新
                    if (file_exists($file)) {
                        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file);
                        update_post_meta($att_id, '_wp_attached_file', $relative_path);
                        $this->add_debug_log('✅ 最終的な相対パス: ' . $relative_path);
                    } else {
                        $this->add_debug_log('❌ 有効なファイルが見つかりませんでした');
                    }
                    
                    // 既存のメタデータを削除
                    delete_post_meta($att_id, '_wp_attachment_metadata');
                    
                    // 保存済みの添付ファイル情報を再取得
                    $saved_file = get_post_meta($att_id, '_wp_attached_file', true);
                    
                    $this->add_debug_log('📁 ファイルパス確認:');
                    $this->add_debug_log('   実際のファイル: ' . $file);
                    $this->add_debug_log('   保存済み相対パス: ' . $saved_file);
                    $this->add_debug_log('   ディレクトリ存在: ' . (is_dir(dirname($file)) ? 'はい' : 'いいえ'));
                    $this->add_debug_log('   ファイル存在: ' . (file_exists($file) ? 'はい' : 'いいえ'));
                    
                    if (!file_exists($file)) {
                        // ファイルが見つからない場合、代替パスを試行
                        $alt_paths = array(
                            $upload_dir['basedir'] . '/' . $saved_file,  // 保存済みの相対パスを使用
                            $upload_dir['path'] . '/' . basename($file), // 現在の月のディレクトリ
                            dirname($upload_dir['basedir']) . '/' . $saved_file, // 1階層上から
                        );
                        
                        foreach ($alt_paths as $alt_path) {
                            $this->add_debug_log('🔍 代替パスを確認: ' . $alt_path);
                            if (file_exists($alt_path)) {
                                $file = $alt_path;
                                $this->add_debug_log('✅ 有効なファイルを発見: ' . $file);
                                break;
                            }
                        }
                    }
                    
                    if (file_exists($file)) {
                        // メタデータ生成前に必要なファイルを読み込み
                        require_once(ABSPATH . 'wp-admin/includes/image.php');
                        require_once(ABSPATH . 'wp-admin/includes/file.php');
                        require_once(ABSPATH . 'wp-admin/includes/media.php');
                        
                        // メタデータを生成
                        $metadata = wp_generate_attachment_metadata($att_id, $file);
                        if (is_wp_error($metadata)) {
                            $this->add_debug_log('⚠️ メタデータ生成エラー: ' . $metadata->get_error_message());
                        } else {
                            wp_update_attachment_metadata($att_id, $metadata);
                            $this->add_debug_log('🔄 サムネイルを再生成しました');
                            
                            // サムネイルサイズを確認
                            if (!empty($metadata['sizes'])) {
                                $this->add_debug_log('📐 生成されたサイズ: ' . implode(', ', array_keys($metadata['sizes'])));
                            }
                        }
                    } else {
                        $this->add_debug_log('⚠️ 添付ファイルが見つかりません:');
                        $this->add_debug_log('   最終確認パス: ' . $file);
                        $this->add_debug_log('   アップロードベースディレクトリ: ' . $upload_dir['basedir']);
                        $this->add_debug_log('   保存済み相対パス: ' . $saved_file);
                    }
                    
                    // アイキャッチ表示用のキャッシュをクリア
                    wp_cache_delete($post_id, 'post_meta');
                    wp_cache_delete($att_id, 'post_meta');
                    
                    // 投稿を再保存して確実に更新
                    wp_update_post(array(
                        'ID' => $post_id,
                        'post_modified' => current_time('mysql'),
                        'post_modified_gmt' => current_time('mysql', 1)
                    ));

                } catch (Exception $e) {
                    error_log('AI Auto Poster アイキャッチ処理エラー: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                    $this->set_notice('error', '記事は作成、ただしアイキャッチ未設定: ' . $e->getMessage(), get_edit_post_link($post_id,''));
                    wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
                }
                }

                $this->set_notice('success','記事生成が完了しました。',get_edit_post_link($post_id,'')); 
                wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
            }catch(Exception $e){
                error_log('AI Auto Poster エラー: ' . $e->getMessage());
                $this->set_notice('error','記事生成に失敗: '.$e->getMessage());
                wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
            }
        } catch (Exception $e) {
            // ここで捕まえたら管理画面に戻す
            error_log('AI Auto Poster 致命エラー: ' . $e->getMessage());
            $this->set_notice('error', 'エラーが発生しました: '.$e->getMessage());
            wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
        }
    }

    /* ========== OpenAI API ========== */
        private function openai_json($api_key,$system,$prompt,$max_tokens=1800,$temperature=0.5){
        if (empty($api_key)) {
            throw new Exception('APIキーが設定されていません');
        }
        $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer '.$api_key,
                'Content-Type'  => 'application/json'
            ),
            'timeout' => 300,
            'body' => wp_json_encode(array(
                // JSON出力対応モデルに変更
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array('role'=>'system','content'=>$system),
                    array('role'=>'user','content'=>$prompt),
                ),
                'response_format' => array('type'=>'json_object'),
                'temperature' => $temperature,
                'max_tokens' => $max_tokens,
            )),
        ));
        if(is_wp_error($resp)) throw new Exception('APIリクエストエラー: '.$resp->get_error_message());
        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        if($code!==200){
            $j = json_decode($raw, true);
            $msg = isset($j['error']['message']) ? $j['error']['message'] : $raw;
            throw new Exception('APIエラー('.$code.'): '.$msg);
        }
        $body = json_decode($raw, true);
        if (json_last_error()!==JSON_ERROR_NONE) {
            throw new Exception('JSONデコードエラー: '.json_last_error_msg());
        }
        $json = trim(isset($body['choices'][0]['message']['content'])?$body['choices'][0]['message']['content']:'');
        if ($json==='') throw new Exception('APIからの応答が空です');
        if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s',$json,$m)) $json = $m[1];
        $p1=strpos($json,'{'); $p2=strrpos($json,'}');
        if($p1!==false && $p2!==false && $p2>$p1) $json=substr($json,$p1,$p2-$p1+1);
        $result = json_decode($json, true);
        if (json_last_error()!==JSON_ERROR_NONE) {
            throw new Exception('生成JSON解析エラー: '.json_last_error_msg());
        }
        return $result;
    }

    /**
     * DALL·E 3 を優先し、失敗/権限なし/パラメータ不一致などでは DALL·E 2 に自動フォールバック。
     * 戻り値: base64文字列 / 失敗時 null（$err に詳細）
     */
        private function openai_image($api_key, $prompt, $size='1792x1024', &$err=''){
        $err = '';

        // リクエストデータを準備
        $data = [
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'size' => $size,
            'n' => 1,
            'quality' => 'hd',
            'style' => 'vivid'
        ];

        // cURLセッションを初期化
        $ch = curl_init('https://api.openai.com/v1/images/generations');

        // cURLオプションを設定
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json'
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300
        ]);

        // リクエストを実行
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // エラーチェック
        if ($response === false) {
            $err = "cURLエラー: " . $error;
            error_log('AI Auto Poster 画像生成エラー: ' . $err);
            return null;
        }

        if ($httpCode !== 200) {
            $err = "HTTPエラー {$httpCode}: " . $response;
            error_log('AI Auto Poster 画像生成APIエラー: ' . $err);
            return null;
        }

        // JSONをデコード
        $result = json_decode($response, true);
        if (!isset($result['data'][0]['url'])) {
            $err = "画像URLが見つかりません: " . $response;
            error_log('AI Auto Poster 画像生成エラー: ' . $err);
            return null;
        }

        // 画像をダウンロード
        $imageUrl = $result['data'][0]['url'];
                            $this->add_debug_log('🔗 画像URL: ' . $imageUrl);

        $image = file_get_contents($imageUrl);
        if ($image === false) {
            $err = "画像のダウンロードに失敗しました";
            error_log('AI Auto Poster 画像ダウンロードエラー: ' . $err);
            return null;
        }

        return base64_encode($image);
    }

    private function download_image_binary($url,&$err=''){
        $r=wp_remote_get($url,array('timeout'=>300));
        if(is_wp_error($r)){ $err=$r->get_error_message(); return null; }
        $code=wp_remote_retrieve_response_code($r);
        if($code!==200){ $err='HTTP '.$code; return null; }
        return wp_remote_retrieve_body($r);
    }

             private function build_image_prompt($title, $angle, $hint=''){
        $pieces = array_filter([
            "Web記事のヒーロー画像。テーマはAGA（男性型脱毛）。横長の構図。",
            "タイトル: {$title}",
            "切り口: {$angle}",
            "内容を象徴する抽象的・医療系のビジュアル（頭皮/毛髪イメージ、分子・図形、清潔感のある背景）。",
            "人物の顔の再現やテキスト埋め込みは不要。ロゴ不要。高級感・清潔感・信頼感。",
            "画像は横長のレイアウトで、医療系Webサイトのヘッダーイメージとして最適化。",
            $hint ? "雰囲気のヒント: {$hint}" : ""
        ]);
        return implode(' ', $pieces);
    }

    private function save_base64_image_as_attachment($b64, $title, $post_id, &$msg='') {
        $this->add_debug_log('💾 画像保存開始: title=' . $title . ', post_id=' . $post_id);

        // 必要なファイルの読み込み
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // base64デコード
        $data = base64_decode($b64);
        if (!$data) {
            $msg = 'base64デコード失敗';
            $this->add_debug_log('⚠️ ' . $msg);
            return 0;
        }

        $this->add_debug_log('📦 画像データサイズ: ' . strlen($data) . ' bytes');

        // MIME推定
        $ext = 'jpg';
        $mime = 'image/jpeg';
        if (function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($data);
            if (!empty($info['mime'])) {
                $mime = $info['mime'];
                if ($mime === 'image/png') $ext = 'png';
                elseif ($mime === 'image/webp') $ext = 'webp';
                elseif ($mime === 'image/jpeg') $ext = 'jpg';
            }
            $this->add_debug_log('📋 画像情報: ' . $mime . ', ' . $info[0] . 'x' . $info[1]);
        }

        // ファイル名を生成（sanitize_file_name を使用）
        $filename = sanitize_file_name(sanitize_title($title) . '-' . time() . '.' . $ext);

        // wp_upload_bits でファイルを保存（WordPressの標準関数を使用）
        $upload = wp_upload_bits($filename, null, $data);
        if ($upload['error']) {
            $msg = 'ファイル保存エラー: ' . $upload['error'];
            $this->add_debug_log('⚠️ ' . $msg);
            return 0;
        }

        $this->add_debug_log('✅ ファイル保存完了:');
        $this->add_debug_log('   ファイル: ' . $upload['file']);
        $this->add_debug_log('   URL: ' . $upload['url']);

        // 添付ファイルとして登録
        $attachment = array(
            'post_mime_type' => $mime,
            'post_title' => $title,
            'post_content' => '',
            'post_status' => 'inherit',
            'guid' => $upload['url']
        );

        $att_id = wp_insert_attachment($attachment, $upload['file'], $post_id);
        if (is_wp_error($att_id) || !$att_id) {
            $msg = '添付登録に失敗: ' . (is_wp_error($att_id) ? $att_id->get_error_message() : 'unknown error');
            $this->add_debug_log('⚠️ ' . $msg);
            return 0;
        }

        $this->add_debug_log('🔢 添付ID: ' . $att_id);

        // メタデータを生成（この時点で _wp_attached_file は自動的に設定される）
        $metadata = wp_generate_attachment_metadata($att_id, $upload['file']);
        if (is_wp_error($metadata)) {
            $this->add_debug_log('⚠️ メタデータ生成エラー: ' . $metadata->get_error_message());
        } else {
            wp_update_attachment_metadata($att_id, $metadata);
            $this->add_debug_log('✅ メタデータ生成完了');
            if (!empty($metadata['sizes'])) {
                $this->add_debug_log('📐 生成されたサイズ: ' . implode(', ', array_keys($metadata['sizes'])));
            }
        }

        // 保存された情報を確認
        $attached_file = get_post_meta($att_id, '_wp_attached_file', true);
        $this->add_debug_log('📂 添付ファイル情報:');
        $this->add_debug_log('   _wp_attached_file: ' . $attached_file);
        $this->add_debug_log('   実ファイルパス: ' . $upload['file']);
        $this->add_debug_log('   URL: ' . $upload['url']);

        $this->add_debug_log('🎉 画像保存完了: att_id=' . $att_id);
        return $att_id;
    }

    /* ========== JSON→Gutenberg ========== */
    private function clean_bullet_items($items){
        $out=array();
        foreach($items as $i){
            $s=(string)$i;
            $s=preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00A0}]/u','',$s);
            $s=trim(wp_strip_all_tags($s));
            $s=preg_replace('/^\s*[・\-\*•●◦･｡]+/u','',$s);
            $s=trim($s);
            if($s!=='' && !preg_match('/^[・\-\*•●◦･｡\s]+$/u',$s)){
                if(!in_array($s,$out,true)) $out[]=$s;
            }
        }
        return $out;
    }

    private function render_sections($sections){
        $out=array(); $last_h2='';
        foreach($sections as $sec){
            $type=isset($sec['type'])?$sec['type']:'';
            switch($type){
                case 'heading': {
                    $level = isset($sec['level']) ? intval($sec['level']) : 2;
                    $level = max(2, min(4, $level));
                    $raw_text = isset($sec['text'])?(string)$sec['text']:'';
                    if($level===3){
                        $t=trim($raw_text);
                        if($t==='' || preg_match('/^(導入のh3|intro|introduction|placeholder|※)/iu',$t)){
                            $raw_text = ($this->current_angle!=='') ? $this->current_angle : 'AGAのポイント';
                        }
                    }
                    $out[]=$this->blk_heading($level,$raw_text);
                    if($level===2) $last_h2=trim($raw_text);
                    break;
                }
                case 'paragraph': {
                    $txt = isset($sec['text'])?(string)$sec['text']:'';
                    if(!empty($sec['emphasis']) && is_array($sec['emphasis'])){
                        foreach($sec['emphasis'] as $emp){
                            $t = isset($emp['text'])?$emp['text']:'';
                            $style = isset($emp['style'])?$emp['style']:'';
                            if(!$t) continue;
                            if($style==='underline_yellow'){
                                $rep="<strong><span class=\"under-line-yellow\">".esc_html($t)."</span></strong>";
                            } elseif($style==='underline_pink'){
                                $rep="<strong><span class=\"under-line-pink\">".esc_html($t)."</span></strong>";
                            } else {
                                $rep="<strong>".esc_html($t)."</strong>";
                            }
                            $txt=str_replace($t,$rep,$txt);
                        }
                    }
                    $out[]=$this->blk_para($txt);
                    break;
                }
                case 'list': {
                    $items=$this->clean_bullet_items(isset($sec['items'])?(array)$sec['items']:array());
                    if(!empty($items)) $out[]=$this->blk_list($items);
                    break;
                }
                case 'box': {
                    $style_in = isset($sec['style'])?$sec['style']:'';
                    $style = in_array($style_in, array('box_001','box_002','point-box'), true) ? $style_in : 'box_001';
                    $title = trim(isset($sec['title'])?$sec['title']:'');
                    $purpose = isset($sec['purpose'])?$sec['purpose']:'';
                    $items=$this->clean_bullet_items(isset($sec['items'])?(array)$sec['items']:array());

                    // items_html を 7.3互換で作成
                    $items_html = '';
                    if(!empty($items)){
                        $tmp = array();
                        foreach($items as $i){
                            $tmp[] = "・".esc_html($i);
                        }
                        $items_html = implode("<br>\n", $tmp);
                    }

                                         // 自動タイトル
                     if($title==='' && $last_h2!==''){
                         if($purpose==='actions' || $style==='box_002')      $title=$last_h2.'のポイント';
                         elseif($purpose==='signs' || $style==='box_001')    $title=$last_h2.'の主な症状';
                         else                                                $title=$last_h2;
                     }

                    if($style==='point-box'){
                        $html="<div class='point-box'><div class='point-box-title'>".esc_html($title)."</div><div class='point-box-content'><p>{$items_html}</p></div></div>";
                    } elseif($style==='box_002'){
                        $html="<div class='box_002'><p>{$items_html}</p></div>";
                    } else {
                        $html="<div class='box_001'><div class='box_001-title'>".esc_html($title)."</div><div class='box_001-content'><p>{$items_html}</p></div></div>";
                    }
                    $out[]=$this->blk_html($html);
                    break;
                }
            }
        }
        return implode("\n\n",$out);
    }

    private function blk_heading($level, $text){
        $level=max(2,min(4,intval($level)));
        $text=esc_html(trim($text));
        return "<!-- wp:heading {\"level\":$level} --><h{$level} class='wp-block-heading'>{$text}</h{$level}><!-- /wp:heading -->";
    }
    private function blk_para($html){
        return "<!-- wp:paragraph --><p>".wp_kses_post($html)."</p><!-- /wp:paragraph -->";
    }
    private function blk_list($items){
        $lis = array();
        foreach($items as $t){
            $lis[] = "<li>".esc_html($t)."</li>";
        }
        return "<!-- wp:list --><ul>".implode('', $lis)."</ul><!-- /wp:list -->";
    }
    private function blk_html($raw){
        return "<!-- wp:html -->$raw<!-- /wp:html -->";
    }
}
new AIAP_Lite_Box();
