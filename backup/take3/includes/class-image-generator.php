<?php
/**
 * 画像生成と添付ファイル処理を担当するクラス
 */
class AIAP_Image_Generator {
    private $logger;

    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * プロンプトを構築
     */
    public function build_prompt($title, $angle, $hint='') {
        // プロンプトテンプレートを取得
        $o = get_option('aiap_lite_settings', array());
        
        // テーマと詳細を取得
        $theme = isset($o['theme']) && !empty($o['theme']) ? $o['theme'] : 'AGA（男性型脱毛）';
        $detail = isset($o['detail']) && !empty($o['detail']) 
            ? $o['detail'] 
            : '抽象的・医療系のビジュアル（頭皮/毛髪イメージ、分子・図形、清潔感のある背景）';

        $template = "Web記事のヒーロー画像。テーマは{theme}。横長の構図。\n"
            . "内容を象徴する{detail}\n"
            . "人物の顔の再現やテキスト埋め込みは不要。ロゴ不要。高級感・清潔感・信頼感。\n"
            . "画像は横長のレイアウトで、Webサイトのヘッダーイメージとして最適化。";

        // 変数を置換
        $replacements = array(
            '{theme}' => $theme,
            '{detail}' => $detail
        );

        $prompt = str_replace(array_keys($replacements), array_values($replacements), $template);
        
        // 空行を削除して整形
        $lines = array_filter(array_map('trim', explode("\n", $prompt)));
        return implode(' ', $lines);
    }

    /**
     * base64画像データを添付ファイルとして保存
     */
    public function save_as_attachment($b64, $title, $post_id, &$msg='') {
        $this->logger->log('💾 画像保存開始: title=' . $title . ', post_id=' . $post_id);

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $data = base64_decode($b64);
        if (!$data) {
            $msg = 'base64デコード失敗';
            $this->logger->log('⚠️ ' . $msg);
            return 0;
        }

        $this->logger->log('📦 画像データサイズ: ' . strlen($data) . ' bytes');

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
            $this->logger->log('📋 画像情報: ' . $mime . ', ' . $info[0] . 'x' . $info[1]);
        }

        $filename = sanitize_file_name(sanitize_title($title) . '-' . time() . '.' . $ext);
        $upload = wp_upload_bits($filename, null, $data);
        
        if ($upload['error']) {
            $msg = 'ファイル保存エラー: ' . $upload['error'];
            $this->logger->log('⚠️ ' . $msg);
            return 0;
        }

        $this->logger->log('✅ ファイル保存完了:');
        $this->logger->log('   ファイル: ' . $upload['file']);
        $this->logger->log('   URL: ' . $upload['url']);

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
            $this->logger->log('⚠️ ' . $msg);
            return 0;
        }

        $this->logger->log('🔢 添付ID: ' . $att_id);

        $metadata = wp_generate_attachment_metadata($att_id, $upload['file']);
        if (is_wp_error($metadata)) {
            $this->logger->log('⚠️ メタデータ生成エラー: ' . $metadata->get_error_message());
        } else {
            wp_update_attachment_metadata($att_id, $metadata);
            $this->logger->log('✅ メタデータ生成完了');
            if (!empty($metadata['sizes'])) {
                $this->logger->log('📐 生成されたサイズ: ' . implode(', ', array_keys($metadata['sizes'])));
            }
        }

        $attached_file = get_post_meta($att_id, '_wp_attached_file', true);
        $this->logger->log('📂 添付ファイル情報:');
        $this->logger->log('   _wp_attached_file: ' . $attached_file);
        $this->logger->log('   実ファイルパス: ' . $upload['file']);
        $this->logger->log('   URL: ' . $upload['url']);

        $this->logger->log('🎉 画像保存完了: att_id=' . $att_id);
        return $att_id;
    }

    /**
     * アイキャッチ画像を設定
     */
    public function set_featured_image($post_id, $att_id) {
        $this->logger->log('🔄 アイキャッチ画像設定開始');

        clean_post_cache($post_id);
        clean_attachment_cache($att_id);
        wp_cache_delete($post_id, 'post_meta');

        if (!set_post_thumbnail($post_id, $att_id)) {
            throw new Exception('アイキャッチ画像の設定に失敗しました');
        }

        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id != $att_id) {
            throw new Exception('アイキャッチ画像の設定に失敗。ID不一致: ' . $att_id . ' vs ' . $thumbnail_id);
        }

        $image_url = wp_get_attachment_url($att_id);
        $this->logger->log('🖼 アイキャッチ画像URL: ' . $image_url);
        $this->logger->log('✅ アイキャッチ画像設定完了');

        wp_update_post(array(
            'ID' => $post_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1)
        ));
    }
}
