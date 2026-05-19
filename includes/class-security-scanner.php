<?php
/**
 * セキュリティスキャン機能を提供するクラス
 */
class AIAP_Security_Scanner {
    private $logger;

    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * セキュリティスキャンを実行
     */
    public function run_security_scan() {
        $results = array(
            'uploads_php_check' => $this->check_uploads_php_execution(),
            'base64_output_check' => $this->check_base64_output(),
            'external_scripts_check' => $this->check_external_scripts(),
            'malware_check' => $this->check_malware_patterns(),
            'medical_content_check' => $this->check_medical_content()
        );

        return $results;
    }

    /**
     * uploadsディレクトリでのPHP実行チェック
     */
    private function check_uploads_php_execution() {
        $uploads_dir = wp_upload_dir();
        $htaccess_file = $uploads_dir['basedir'] . '/.htaccess';
        
        if (!file_exists($htaccess_file)) {
            return array(
                'status' => 'warning',
                'message' => 'uploadsディレクトリに.htaccessファイルがありません',
                'recommendation' => 'uploadsディレクトリに.htaccessファイルを作成してPHP実行を禁止してください'
            );
        }

        $htaccess_content = file_get_contents($htaccess_file);
        if (strpos($htaccess_content, 'Deny from all') === false || strpos($htaccess_content, '\.(php|php5|php7|phtml)') === false) {
            return array(
                'status' => 'warning',
                'message' => 'uploadsディレクトリの.htaccessにPHP実行禁止設定が不十分です',
                'recommendation' => 'PHP実行禁止の設定を追加してください'
            );
        }

        return array(
            'status' => 'ok',
            'message' => 'uploadsディレクトリのPHP実行禁止設定は適切です'
        );
    }

    /**
     * base64文字列の直接出力チェック
     */
    private function check_base64_output() {
        global $wpdb;
        
        // 最近の投稿でbase64文字列が含まれていないかチェック
        $posts = $wpdb->get_results("
            SELECT ID, post_title, post_content 
            FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND post_content LIKE '%data:image%' 
            AND post_date > DATE_SUB(NOW(), INTERVAL 7 DAY)
            LIMIT 10
        ");

        if (!empty($posts)) {
            return array(
                'status' => 'warning',
                'message' => '最近の投稿にbase64文字列が含まれている可能性があります',
                'posts' => $posts,
                'recommendation' => '該当投稿を確認し、base64文字列を削除してください'
            );
        }

        return array(
            'status' => 'ok',
            'message' => 'base64文字列の直接出力は検出されませんでした'
        );
    }

    /**
     * 外部スクリプトの呼び出しチェック
     */
    private function check_external_scripts() {
        $external_domains = array(
            'cdn.jsdelivr.net',
            'unpkg.com',
            'cdnjs.cloudflare.com',
            'googleapis.com',
            'gstatic.com'
        );

        $suspicious_files = array();
        
        // テーマファイルをチェック
        $theme_dir = get_template_directory();
        $this->scan_directory_for_external_scripts($theme_dir, $external_domains, $suspicious_files);
        
        // プラグインディレクトリをチェック
        $plugin_dir = WP_PLUGIN_DIR;
        $this->scan_directory_for_external_scripts($plugin_dir, $external_domains, $suspicious_files);

        if (!empty($suspicious_files)) {
            return array(
                'status' => 'warning',
                'message' => '外部スクリプトの呼び出しが検出されました',
                'files' => $suspicious_files,
                'recommendation' => '不要な外部スクリプトを削除してください'
            );
        }

        return array(
            'status' => 'ok',
            'message' => '外部スクリプトの呼び出しは検出されませんでした'
        );
    }

    /**
     * マルウェアパターンのチェック
     */
    private function check_malware_patterns() {
        $malware_patterns = array(
            'base64_decode',
            'eval(',
            'gzinflate',
            'str_rot13',
            'create_function',
            'preg_replace.*\/e'
        );

        $suspicious_files = array();
        
        // wp-contentディレクトリをスキャン
        $wp_content_dir = WP_CONTENT_DIR;
        $this->scan_directory_for_malware($wp_content_dir, $malware_patterns, $suspicious_files);

        if (!empty($suspicious_files)) {
            return array(
                'status' => 'critical',
                'message' => 'マルウェアの疑いがあるコードが検出されました',
                'files' => $suspicious_files,
                'recommendation' => '即座に該当ファイルを確認し、必要に応じて削除してください'
            );
        }

        return array(
            'status' => 'ok',
            'message' => 'マルウェアパターンは検出されませんでした'
        );
    }

    /**
     * 医療コンテンツのチェック
     */
    private function check_medical_content() {
        $medical_keywords = array(
            'AGA', '男性型脱毛', '治療', '薬', '医師', '診断', '症状'
        );

        $posts_with_medical_content = array();
        
        foreach ($medical_keywords as $keyword) {
            $posts = get_posts(array(
                's' => $keyword,
                'post_status' => 'publish',
                'numberposts' => 5,
                'meta_query' => array(
                    array(
                        'key' => '_yoast_wpseo_meta-robots-noindex',
                        'compare' => 'NOT EXISTS'
                    )
                )
            ));

            foreach ($posts as $post) {
                if (strpos($post->post_content, '免責事項') === false) {
                    $posts_with_medical_content[] = $post;
                }
            }
        }

        if (!empty($posts_with_medical_content)) {
            return array(
                'status' => 'warning',
                'message' => '医療関連コンテンツに免責事項が不足している投稿があります',
                'posts' => $posts_with_medical_content,
                'recommendation' => '医療関連投稿には免責事項を追加してください'
            );
        }

        return array(
            'status' => 'ok',
            'message' => '医療関連コンテンツの免責事項は適切です'
        );
    }

    /**
     * ディレクトリをスキャンして外部スクリプトを検索
     */
    private function scan_directory_for_external_scripts($dir, $domains, &$suspicious_files) {
        if (!is_dir($dir)) return;

        $files = glob($dir . '/*.{php,js}', GLOB_BRACE);
        foreach ($files as $file) {
            if (is_file($file)) {
                $content = file_get_contents($file);
                foreach ($domains as $domain) {
                    if (strpos($content, $domain) !== false) {
                        $suspicious_files[] = array(
                            'file' => $file,
                            'domain' => $domain,
                            'line' => $this->find_line_number($file, $domain)
                        );
                    }
                }
            }
        }

        // サブディレクトリも再帰的にスキャン
        $subdirs = glob($dir . '/*', GLOB_ONLYDIR);
        foreach ($subdirs as $subdir) {
            $this->scan_directory_for_external_scripts($subdir, $domains, $suspicious_files);
        }
    }

    /**
     * ディレクトリをスキャンしてマルウェアパターンを検索
     */
    private function scan_directory_for_malware($dir, $patterns, &$suspicious_files) {
        if (!is_dir($dir)) return;

        $files = glob($dir . '/*.php');
        foreach ($files as $file) {
            if (is_file($file)) {
                $content = file_get_contents($file);
                foreach ($patterns as $pattern) {
                    if (preg_match('/' . $pattern . '/', $content)) {
                        $suspicious_files[] = array(
                            'file' => $file,
                            'pattern' => $pattern,
                            'line' => $this->find_line_number($file, $pattern)
                        );
                    }
                }
            }
        }

        // サブディレクトリも再帰的にスキャン
        $subdirs = glob($dir . '/*', GLOB_ONLYDIR);
        foreach ($subdirs as $subdir) {
            $this->scan_directory_for_malware($subdir, $patterns, $suspicious_files);
        }
    }

    /**
     * ファイル内で文字列の行番号を検索
     */
    private function find_line_number($file, $search) {
        $lines = file($file);
        foreach ($lines as $line_num => $line) {
            if (strpos($line, $search) !== false) {
                return $line_num + 1;
            }
        }
        return null;
    }

    /**
     * スキャン結果をHTMLで出力
     */
    public function format_scan_results($results) {
        $html = '<div class="aiap-security-scan-results">';
        $html .= '<h3>🔒 セキュリティスキャン結果</h3>';
        
        foreach ($results as $check_name => $result) {
            $status_class = '';
            $status_icon = '';
            
            switch ($result['status']) {
                case 'ok':
                    $status_class = 'notice-success';
                    $status_icon = '✅';
                    break;
                case 'warning':
                    $status_class = 'notice-warning';
                    $status_icon = '⚠️';
                    break;
                case 'critical':
                    $status_class = 'notice-error';
                    $status_icon = '🚨';
                    break;
            }
            
            $html .= '<div class="notice ' . $status_class . ' inline">';
            $html .= '<p><strong>' . $status_icon . ' ' . $this->get_check_name($check_name) . '</strong></p>';
            $html .= '<p>' . esc_html($result['message']) . '</p>';
            
            if (isset($result['recommendation'])) {
                $html .= '<p><strong>推奨対策:</strong> ' . esc_html($result['recommendation']) . '</p>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '</div>';
        return $html;
    }

    /**
     * チェック名を取得
     */
    private function get_check_name($check_name) {
        $names = array(
            'uploads_php_check' => 'uploadsディレクトリのPHP実行チェック',
            'base64_output_check' => 'base64文字列の直接出力チェック',
            'external_scripts_check' => '外部スクリプトの呼び出しチェック',
            'malware_check' => 'マルウェアパターンチェック',
            'medical_content_check' => '医療コンテンツの免責事項チェック'
        );
        
        return isset($names[$check_name]) ? $names[$check_name] : $check_name;
    }
}

