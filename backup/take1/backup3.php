<?php
/**
 * Plugin Name: AI Auto Poster Lite (Box)
 * Description: 最小構成。OpenAIのJSONをGutenbergブロックへ変換（見出し/段落/リスト/ボックス対応）+ テーマ・タイトルを毎回生成してバリエーション確保。実行後は管理画面に完了ログを表示。
 * Version: 0.2.0
 * Author: You
 */
if (!defined('ABSPATH')) exit;

class AIAP_Lite_Box {
    const OPT_KEY       = 'aiap_lite_settings';
    const ACTION_RUN    = 'aiap_lite_run';
    const NOTICE_KEY    = 'aiap_lite_notice';

    function __construct(){
        add_action('admin_menu',         [$this,'menu']);
        add_action('admin_init',         [$this,'register']);
        add_action('admin_post_'.self::ACTION_RUN, [$this,'handle_run']);
        add_action('wp_enqueue_scripts', [$this,'styles']);
        add_action('admin_notices',      [$this,'admin_notices']);
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

    /* ========== 実行完了ログ（管理画面の上に表示） ========== */
    function admin_notices(){
        if (!current_user_can('manage_options')) return;
        $notice = get_transient(self::NOTICE_KEY);
        if (!$notice) return;

        delete_transient(self::NOTICE_KEY);
        $class = $notice['type']==='error' ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
        echo '<div class="'.esc_attr($class).'"><p>';
        echo wp_kses_post($notice['message']);
        if (!empty($notice['edit_link'])) {
            echo ' <a target="_blank" href="'.esc_url($notice['edit_link']).'">編集画面を開く</a>';
        }
        echo '</p></div>';
    }
    private function set_notice($type, $message, $edit_link=''){
        set_transient(self::NOTICE_KEY, [
            'type'      => $type,
            'message'   => $message,
            'edit_link' => $edit_link
        ], 60); // 1分で消える（表示後は即 delete）
    }

    /* ========== スタイル ========== */
    function styles(){
        wp_add_inline_style('wp-block-library', '
            .under-line-yellow{background:linear-gradient(transparent 60%,#fff799 60%)}
            .under-line-pink{background:linear-gradient(transparent 60%,#ffcece 60%)}
            .box_001{border:2px solid #95ccff;border-radius:8px;margin:2em 0}
            .box_001-title{background:#95ccff;color:#fff;padding:.8em;border-radius:8px 8px 0 0;text-align:center;font-weight:700}
            .box_001-content{padding:1.2em}
            .box_002{border:2px solid #95ccff;background:#f9fbff;border-radius:8px;margin:2em 0;padding:1.2em;border-radius:8px}
            .point-box{border:2px solid #ffd700;background:#fffef4;border-radius:8px;margin:2em 0}
            .point-box-title{background:#ffd700;padding:.8em;border-radius:8px 8px 0 0;text-align:center;font-weight:700}
            .point-box-content{padding:1.2em}
        ');
    }

    /* ========== 実行本体 ========== */
    function handle_run(){
        if(!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::ACTION_RUN);

        $o=get_option(self::OPT_KEY,[]);
        $api_key=trim($o['api_key']??'');
        if(!$api_key){
            $this->set_notice('error','APIキーが未設定です。設定画面で保存してください。');
            wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
        }

        // バリエーション用の軽い乱数（プロンプトに混ぜるだけ）
        $seed = wp_generate_uuid4(); // ばらつき付与用

        // === (1) 題材・タイトルを先に生成 ===
        $system1 = 'あなたはAGA専門の日本語ライター。必ずJSONのみ返す。Markdown/HTMLは返さない。';
        $prompt1 = <<<EOT
根本テーマはつねに「AGA」。ただし毎回、焦点（切り口）を変え、**前回と同じ趣旨を繰り返さない**題材を1つだけ提案してください。
例）生活習慣/睡眠/食事/ストレス/運動/シャンプー/季節/年代/女性の薄毛と鑑別/自己流ケアの落とし穴/医療と市販の役割/通院の続け方 など。

制約:
- 出力は**JSONのみ**。
- "title" は32字以内・句点なし・キャッチーで、本文は必ずこのタイトルに沿うこと。
- "angle" は「このタイトルの焦点」を短い日本語で。
- "outline" は本文の大見出し(H2)候補の配列（4〜6個）。重複不可。
- 同じような「半年後/1年後〜」などのテンプレは避ける。
- バリエーションのためのランダム種: {$seed}

出力スキーマ:
{
  "title": "タイトル（句点なし）",
  "angle": "切り口のひとこと",
  "outline": ["H2見出し1","H2見出し2","H2見出し3","H2見出し4"]
}
EOT;

        $topic = $this->openai_json($api_key, $system1, $prompt1, 1200, 0.8);
        if(!$topic || empty($topic['title'])){
            $this->set_notice('error','題材生成に失敗しました。');
            wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
        }
        $title   = sanitize_text_field($topic['title']);
        $angle   = sanitize_text_field($topic['angle'] ?? 'AGAの基礎');
        $outline = array_values(array_filter(array_map('sanitize_text_field',(array)($topic['outline'] ?? []))));
        if (count($outline) < 3) {
            $outline = array_values(array_unique(array_merge($outline, ['原因と仕組み','セルフチェック','受診と治療','日常ケア'])));
            $outline = array_slice($outline, 0, 5);
        }

        // === (2) 本文を生成（スキーマは固定だが中身は題材/アウトライン連動） ===
        $system2 = 'あなたは医療系SEOに精通した日本語ライター。必ず有効なJSONのみを返す。Markdown/HTMLは返さない。';
        // アウトラインをJSON文字列化
        $outline_json = json_encode($outline, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        $prompt2 = <<<EOT
ヘアクリニック向けブログの記事を作成します。
必ずタイトルの内容に沿い、題材の切り口（angle）とアウトライン（outline）を反映してください。
- title: {$title}
- angle: {$angle}
- outline(H2候補): {$outline_json}

分量: 1600〜2300字（日本語）。断定しすぎず「可能性があります」「〜と言われています」等も適宜使用。

出力は**次のJSONスキーマのみ**（JSON以外禁止）：

{
  "title": "{$title}",
  "sections": [
    { "type": "heading", "level": 3, "text": "導入のh3（angleを短く要約）" },
    { "type": "paragraph", "text": "導入文。読者の悩みに寄り添い、この記事で分かることを2段落程度で説明" },

    { "type": "heading", "level": 2, "text": "H2：outlineの1番目をベースに調整" },
    { "type": "paragraph", "text": "内容説明1", "emphasis":[
        {"text":"重要な結論や注意点を一部強調","style":"strong"}
    ]},
    { "type": "list", "items": ["ポイント1","ポイント2","ポイント3"] },

    { "type": "heading", "level": 2, "text": "H2：outlineの2番目をベースに調整" },
    { "type": "paragraph", "text": "内容説明2（データや期間の目安なども適宜）" },

    { "type": "heading", "level": 2, "text": "H2：outlineの3番目をベースに調整" },
    { "type": "box", "style":"box_001", "title":"注意したいサイン", "items":[
      "・から始めない短文で、重複しない項目を4〜6個"
    ]},

    { "type": "heading", "level": 2, "text": "H2：outlineの4番目をベースに調整" },
    { "type": "box", "style":"box_002", "title":"セルフケアのヒント", "items":[
      "重複しない項目を3〜5個（半角・全角記号の混在は避ける）"
    ]},

    { "type": "heading", "level": 2, "text": "まとめ（本記事の主眼に沿って）" },
    { "type": "box", "style":"point-box", "title":"重要ポイント", "items":[
      "本文の要点を3〜5個で端的に"
    ]},
    { "type": "paragraph", "text": "次のアクション（例：無料カウンセリング相談）を自然に案内" }
  ]
}

ルール:
- emphasisは {"text","style"} の配列。styleは "strong" | "underline_yellow" | "underline_pink" のみ。
- 箇条書き items は各要素をテキストのみで。先頭に「・」は**書かない**（レンダリング時に付与）。
- 同じ見出しや文言を使い回さない。{$seed} を参考に表現を変える。
EOT;

        try{
            $data = $this->openai_json($api_key, $system2, $prompt2, 2200, 0.65);
            if (!is_array($data) || empty($data['sections'])) {
                throw new Exception('JSON解析失敗 or sections無し');
            }

            // タイトル（モデル出力があれば優先）
            $final_title = sanitize_text_field($data['title'] ?? $title);

            // JSON → ブロックHTML
            $post_content = $this->render_sections($data['sections']);

            // 最終シリアライズ（空ブロック除去）
            if(function_exists('parse_blocks') && function_exists('serialize_blocks')){
                $blocks = parse_blocks($post_content);
                $blocks = array_values(array_filter($blocks, fn($b)=>trim($b['innerHTML']??'')!=='' || !empty($b['innerBlocks'])));
                $post_content = serialize_blocks($blocks);
            }

            // 投稿
            $post_id = wp_insert_post([
                'post_title'   => $final_title,
                'post_content' => $post_content,
                'post_status'  => $o['post_status'] ?? 'draft',
                'post_author'  => get_current_user_id() ?: 1,
                'post_type'    => 'post',
            ], true);

            if(is_wp_error($post_id)) throw new Exception($post_id->get_error_message());

            // 成功：管理画面に完了ログ
            $this->set_notice('success', '記事の生成が完了しました。', get_edit_post_link($post_id,''));
            wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;

        } catch(Exception $e){
            $this->set_notice('error','記事生成に失敗: '.esc_html($e->getMessage()));
            wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
        }
    }

    /* ========== OpenAI（JSON専用ヘルパ） ========== */
    private function openai_json($api_key, $system, $prompt, $max_tokens=1800, $temperature=0.5){
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
                'temperature' => $temperature,
                'max_tokens' => $max_tokens,
            ]),
        ]);
        if(is_wp_error($resp)) throw new Exception('APIエラー: '.$resp->get_error_message());
        if(wp_remote_retrieve_response_code($resp)!==200){
            throw new Exception('APIレスポンス異常: '.wp_remote_retrieve_body($resp));
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        $content = $body['choices'][0]['message']['content'] ?? '';
        // フェンス除去＆{}抽出（保険）
        $json = trim($content);
        if (preg_match('/^```(?:json)?\s*(.+?))\s*```$/s',$json,$m)) $json = $m[1];
        $p1=strpos($json,'{'); $p2=strrpos($json,'}');
        if($p1!==false && $p2!==false && $p2>$p1) $json=substr($json,$p1,$p2-$p1+1);

        $data = json_decode($json, true);
        if(!is_array($data)) throw new Exception('JSON解析失敗: '.json_last_error_msg());
        return $data;
    }

    /* ========== JSON → Gutenberg ========== */

    // 箇条書きアイテムのクレンジング: 空/「・」のみ/不可視文字のみを除去し内容を整える
    private function clean_bullet_items(array $items): array {
        $out = [];
        foreach ($items as $i) {
            $s = (string)$i;

            // ゼロ幅/不可視系を削除
            $s = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00A0}]/u', '', $s);

            // タグ除去 & 前後空白除去
            $s = trim(wp_strip_all_tags($s));

            // 先頭の箇条書き記号（全角/半角）を除去
            $s = preg_replace('/^\s*[・\-\*•●◦･｡]+/u', '', $s);
            $s = trim($s);

            // 記号しかない/空はスキップ
            if ($s === '' || preg_match('/^[・\-\*•●◦･｡\s]+$/u', $s)) continue;

            $out[] = $s;
        }
        // 重複除去
        $out = array_values(array_unique($out));
        return $out;
    }

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
                    $items_in = array_map(fn($i)=> (string)$i, (array)($sec['items'] ?? []));
                    $items    = $this->clean_bullet_items($items_in);
                    if (!empty($items)) $out[] = $this->blk_list($items);
                    break;

                case 'box':
                    $style = in_array($sec['style']??'', ['box_001','box_002','point-box'], true) ? $sec['style'] : 'box_001';
                    $title = (string)($sec['title'] ?? '');

                    // 箇条書きアイテムをクレンジング
                    $items = $this->clean_bullet_items((array)($sec['items'] ?? []));
                    $items_html = '';
                    if (!empty($items)) {
                        // 表示時に先頭へ「・」を付けて <br> で結合
                        $items_html = implode("<br>\n", array_map(fn($i)=>"・".esc_html($i), $items));
                    }

                    // 何もなければスキップ（タイトルのみ表示したい場合は条件を調整）
                    if ($items_html === '' && $title === '') break;

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
        $items = $this->clean_bullet_items($items);
        if (empty($items)) return '';
        $lis = array_map(fn($t)=>'<li>'.esc_html($t).'</li>', $items);
        return "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n".implode("\n",$lis)."\n</ul>\n<!-- /wp:list -->";
    }
    private function blk_html(string $raw): string {
        return "<!-- wp:html -->\n{$raw}\n<!-- /wp:html -->";
    }
}
new AIAP_Lite_Box();
