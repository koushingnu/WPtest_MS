<?php
// エラー表示を有効化（デバッグ用）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// WordPress環境のロード（絶対パスで指定）
require_once('/www/yk-test/media/wp-load.php');

// セキュリティチェック
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from CLI.');
}

// プラグインのメインクラスをロード（絶対パスで指定）
require_once('/www/yk-test/media/wp-content/plugins/ai-auto-poster/ai-auto-poster.php');

function generate_scheduled_post() {
    try {
        // 実行開始ログ
        error_log('AI Auto Poster Cron: 処理を開始します');
        
        $settings = get_option('aiap_lite_settings', array());
        
        // 自動投稿が有効かチェック
        if (!isset($settings['auto_post_enabled']) || $settings['auto_post_enabled'] !== '1') {
            error_log('AI Auto Poster Cron: 自動投稿が無効です');
            return;
        }

        // プラグインのインスタンスを作成
        $plugin = new AIAP_Lite_Box();
        
        // ロック取得を試行
        if ($plugin->is_locked()) {
            error_log('AI Auto Poster Cron: 前回の処理が完了していないため、スキップします');
            return;
        }

        // プラグインの記事生成処理を実行
        $plugin->handle_daily_post();

    } catch (Exception $e) {
        error_log('AI Auto Poster Cron エラー: ' . $e->getMessage());
        error_log('AI Auto Poster Cron エラー詳細: ' . print_r($e->getTraceAsString(), true));
    }
}

// 実行
generate_scheduled_post();