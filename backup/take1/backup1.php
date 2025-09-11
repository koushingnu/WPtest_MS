<?php
/**
 * Plugin Name: AI Auto Poster Lite (Box)
 * Description: 最小構成。OpenAIのJSON出力をGutenbergブロックへ変換（見出し/段落/リスト/ボックス対応）
 * Version: 0.1.0
 * Author: You
 */
if (!defined('ABSPATH')) exit;

class AIAP_Lite_Box {
    const OPT_KEY   = 'aiap_lite_settings';
    const ACTION_RUN = 'aiap_lite_run';

    function __construct(){
        add_action('admin_menu', [$this,'menu']);
        add_action('admin_init', [$this,'register']);
        add_action('admin_post_'.self::ACTION_RUN, [$this,'handle_run']);
        add_action('wp_enqueue_scripts', [$this,'styles']);
    }

    /* ========== 管理画面 ========== */
    function menu(){
        add_options_page('AI Auto Poster Lite','AI Auto Poster Lite','manage_options','aiap-lite',[$this,'page']);
    }
    function register(){
        register_setting('aiap_lite_group', self::OPT_KEY);
        add_settings_section('main','基本設定','__return_false','aiap-lite');
        add_settings_field('api_key','OpenAI API Key', function(){
            $o=get_option(self::OPT_KEY,[]);
            printf('<input type="password" name="%s[api_key]" value="%s" class="regular-text" />',
                esc_attr(self::OPT_KEY), esc_attr($o['api_key']??''));
        }, 'aiap-lite','main');
        add_settings_field('post_status','投稿ステータス', function(){
            $o=get_option(self::OPT_KEY,[]); $v=$o['post_status']??'draft'; ?>
            <select name="<?php echo esc_attr(self::OPT_KEY); ?>[post_status]">
                <option value="publish" <?php selected($v,'publish'); ?>>公開</option>
                <option value="draft"   <?php selected($v,'draft');   ?>>下書き</option>
            </select>
        <?php }, 'aiap-lite','main');
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

    /* ========== 実行 ========== */
    function handle_run(){
        if(!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::ACTION_RUN);

        $o=get_option(self::OPT_KEY,[]);
        $api_key=trim($o['api_key']??'');
        if(!$api_key){ wp_die('APIキーが未設定です。設定画面で保存してください。'); }

        // --- モデルへの指示（JSONスキーマ：見出し/段落/リスト/ボックス）---
        $system = 'あなたは医療系SEOに精通した日本語ライター。必ず有効なJSONのみを返す。Markdown/HTMLを返さない。';
        $prompt = <<<EOT
ヘアクリニック向けブログ。テーマは「AGAは進行性：期間ごとの変化と早期対策」。
出力は次のJSONスキーマに厳密準拠（**JSON以外禁止**）：

{
  "title": "32字前後のタイトル（句点なし）",
  "sections": [
    { "type": "heading", "level": 3, "text": "導入h3（例：～半年後、1年後、5年後の髪は？～）" },
    { "type": "paragraph", "text": "導入文。読者の悩みに共感する2-3段落程度" },

    { "type": "heading", "level": 2, "text": "そもそも進行性とは？" },
    { "type": "paragraph", "text": "進行性の説明。", "emphasis":[
        {"text":"時間が経つごとに広がる","style":"strong"}
    ]},

    { "type": "heading", "level": 2, "text": "発症初期〜中期の目安" },
    { "type": "heading", "level": 4, "text": "● 発症初期（0〜3ヶ月）" },
    { "type": "list", "items": ["抜け毛が少し増える","髪が細くなる","枕の抜け毛が増える"] },
    { "type": "paragraph", "text": "生活習慣や頭皮ケアで進行を遅らせる可能性" },

    { "type": "heading", "level": 4, "text": "● 進行初期（3ヶ月〜半年）" },
    { "type": "list", "items": ["生え際後退やつむじの透け感","密度低下でスタイリング困難","家族に指摘される"] },

    { "type": "heading", "level": 4, "text": "● 中期（半年〜1年）" },
    { "type": "list", "items": ["範囲拡大で地肌が目立つ","全体がペタッとする","写真で薄毛を自覚"] },

    { "type": "heading", "level": 2, "text": "始まりのサイン：見逃し注意" },
    { "type": "box", "style":"box_001", "title":"AGA危険信号", "items":[
      "生え際がじわじわ後退","つむじの地肌が目立つ","ハリやコシが低下","抜け毛が細く短い","髪型が決まりにくい"
    ]},

    { "type": "heading", "level": 2, "text": "進行に個人差がある理由" },
    { "type": "box", "style":"box_002", "title":"進行スピードに影響する要因", "items":[
      "遺伝（親族の薄毛）","ホルモン（DHT）","ストレス・生活習慣","ケアの有無"
    ]},

    { "type": "heading", "level": 2, "text": "早期に始めるメリット" },
    { "type": "paragraph", "text": "早期治療で進行を抑え改善を目指せる可能性。" },

    { "type": "heading", "level": 2, "text": "まとめ" },
    { "type": "box", "style":"point-box", "title":"重要ポイント", "items":[
      "AGAは進行性で自然寛解は稀","初期サインを見逃さない","早めの受診で選択肢が広がる"
    ]},
    { "type": "paragraph", "text": "気づいた今が最も効果的に動けるタイミング。無料カウンセリングの活用も検討を。"}
  ]
}

注意:
- 「断定」ではなく「可能性があります」「〜と言われています」等の表現を適宜使用。
- emphasisは {"text","style"} で、styleは "strong" | "underline_yellow" | "underline_pink" のみ。
EOT;

        // --- OpenAI 呼び出し ---
        $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 120,
            'body' => wp_json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role'=>'system','content'=>$system],
                    ['role'=>'user','content'=>$prompt],
                ],
                'response_format' => ['type'=>'json_object'],
                'temperature' => 0.5,
                'max_tokens' => 2000
            ]),
        ]);
        if(is_wp_error($resp)) wp_die('APIエラー: '.$resp->get_error_message());
        if(wp_remote_retrieve_response_code($resp)!==200){
            wp_die('APIレスポンス異常: '.wp_remote_retrieve_body($resp));
        }

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        $content = $body['choices'][0]['message']['content'] ?? '';
        // フェンス除去＆{}抽出（保険）
        $json = trim($content);
        if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s',$json,$m)) $json = $m[1];
        $p1=strpos($json,'{'); $p2=strrpos($json,'}');
        if($p1!==false && $p2!==false && $p2>$p1) $json=substr($json,$p1,$p2-$p1+1);

        $data = json_decode($json, true);
        if(!is_array($data) || empty($data['sections'])) wp_die('JSON解析失敗 or sections無し');

        // タイトル
        $title = sanitize_text_field($data['title'] ?? 'AGAの基礎と早期対策');

        // セクション→ブロックHTML
        $post_content = $this->render_sections($data['sections']);

        // 最終シリアライズ（任意）
        if(function_exists('parse_blocks') && function_exists('serialize_blocks')){
            $blocks = parse_blocks($post_content);
            $blocks = array_values(array_filter($blocks, fn($b)=>trim($b['innerHTML']??'')!=='' || !empty($b['innerBlocks'])));
            $post_content = serialize_blocks($blocks);
        }

        // 投稿
        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $post_content,
            'post_status'  => $o['post_status'] ?? 'draft',
            'post_author'  => get_current_user_id() ?: 1,
            'post_type'    => 'post',
        ], true);

        if(is_wp_error($post_id)) wp_die('投稿失敗: '.$post_id->get_error_message());

        wp_safe_redirect(get_edit_post_link($post_id,''));
        exit;
    }

    /* ========== JSON → Gutenberg ========== */
    private function render_sections(array $sections): string {
        $out=[];
        foreach($sections as $sec){
            $type = $sec['type'] ?? '';
            switch ($type) {
                case 'heading':
                    $level = max(2, min(4, intval($sec['level'] ?? 2)));
                    $out[] = $this->blk_heading($level, (string)($sec['text'] ?? ''));
                    break;

                case 'paragraph':
                    $txt = (string)($sec['text'] ?? '');
                    foreach ((array)($sec['emphasis'] ?? []) as $emp) {
                        $t = $emp['text'] ?? ''; $style = $emp['style'] ?? '';
                        if(!$t) continue;
                        if ($style==='underline_yellow') {
                            $txt = str_replace($t, "<strong><span class=\"under-line-yellow\">".esc_html($t)."</span></strong>", $txt);
                        } elseif ($style==='underline_pink') {
                            $txt = str_replace($t, "<strong><span class=\"under-line-pink\">".esc_html($t)."</span></strong>", $txt);
                        } else {
                            $txt = str_replace($t, "<strong>".esc_html($t)."</strong>", $txt);
                        }
                    }
                    $out[] = $this->blk_para($txt);
                    break;

                case 'list':
                    $items = array_map(fn($i)=> (string)$i, (array)($sec['items'] ?? []));
                    if ($items) $out[] = $this->blk_list($items);
                    break;

                case 'box':
                    $style = in_array($sec['style']??'', ['box_001','box_002','point-box'], true) ? $sec['style'] : 'box_001';
                    $title = (string)($sec['title'] ?? '');
                    $items = array_map(fn($i)=> '・'.trim((string)$i, "・ \t\n\r"), (array)($sec['items'] ?? []));
                    $items_html = implode("<br>\n", array_map('esc_html', $items));
                    if ($style==='point-box') {
                        $html = "<div class=\"point-box\"><div class=\"point-box-title\">".esc_html($title)."</div><div class=\"point-box-content\"><p>{$items_html}</p></div></div>";
                    } elseif ($style==='box_002') {
                        $html = "<div class=\"box_002\"><p>{$items_html}</p></div>";
                    } else {
                        $html = "<div class=\"box_001\"><div class=\"box_001-title\">".esc_html($title)."</div><div class=\"box_001-content\"><p>{$items_html}</p></div></div>";
                    }
                    $out[] = $this->blk_html($html);
                    break;
            }
        }
        return implode("\n\n", $out);
    }

    /* ========== Gutenberg block helpers ========== */
    private function blk_heading(int $level, string $text): string {
        $text = esc_html(trim($text));
        if ($level===4) return "<!-- wp:heading {\"level\":4} -->\n<h4 class=\"wp-block-heading\">{$text}</h4>\n<!-- /wp:heading -->";
        if ($level===3) return "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">{$text}</h3>\n<!-- /wp:heading -->";
        return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">{$text}</h2>\n<!-- /wp:heading -->";
    }
    private function blk_para(string $html): string {
        return "<!-- wp:paragraph -->\n<p>".wp_kses_post($html)."</p>\n<!-- /wp:paragraph -->";
    }
    private function blk_list(array $items): string {
        $lis = array_map(fn($t)=>'<li>'.esc_html($t).'</li>', $items);
        return "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n".implode("\n",$lis)."\n</ul>\n<!-- /wp:list -->";
    }
    private function blk_html(string $raw): string {
        return "<!-- wp:html -->\n{$raw}\n<!-- /wp:html -->";
    }
}
new AIAP_Lite_Box();
