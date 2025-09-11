<?php
/**
 * ログ管理クラス
 */
class AIAP_Logger {
    private $logs = array();

    /**
     * ログを追加
     */
    public function log($message) {
        $this->logs[] = $message;
    }

    /**
     * ログを取得
     */
    public function get_logs() {
        return $this->logs;
    }

    /**
     * ログをフォーマット
     */
    public function format_logs() {
        if (empty($this->logs)) {
            return '';
        }
        return '<hr style="margin: 20px 0;">📝 処理ログ:<br>' . implode('<br>', array_map('esc_html', $this->logs));
    }
}
