<?php
/**
 * コンテンツ生成を担当するクラス
 */
class AIAP_Content_Generator {
    private $openai_client;
    private $logger;
    private $current_angle = '';
    private $current_category_id = 0;

    public function __construct($openai_client, $logger) {
        $this->openai_client = $openai_client;
        $this->logger = $logger;
    }

    /**
     * 題材を生成
     */
    public function generate_topic($api_key) {
        $seed = wp_generate_uuid4();
        
        // 設定を取得
        $o = get_option('aiap_lite_settings', array());
        $main_topic = isset($o['main_topic']) ? trim($o['main_topic']) : 'AGA';
        
        // 有効なカテゴリを取得
        $topic_enabled = isset($o['topic_enabled']) ? $o['topic_enabled'] : array();
        
        // 有効なカテゴリのIDを抽出
        $enabled_category_ids = array();
        foreach ($topic_enabled as $cat_id => $enabled) {
            if ($enabled === '1') {
                $enabled_category_ids[] = $cat_id;
            }
        }
        
        if (empty($enabled_category_ids)) {
            throw new Exception('有効なカテゴリが設定されていません。');
        }
        
        // ランダムにカテゴリを選択
        $selected_cat_id = $enabled_category_ids[array_rand($enabled_category_ids)];
        
        // 選択されたカテゴリの情報を取得
        $selected_category = get_category($selected_cat_id);
        if (!$selected_category) {
            throw new Exception('選択されたカテゴリの取得に失敗しました。');
        }
        
        // カテゴリの階層関係を取得
        $category_hierarchy = array();
        $current_cat = $selected_category;
        
        // 親カテゴリまで遡る
        while ($current_cat->parent) {
            $parent = get_category($current_cat->parent);
            if ($parent) {
                array_unshift($category_hierarchy, $parent->name);
            }
            $current_cat = $parent;
        }
        
        // 選択されたカテゴリ名を追加
        $category_hierarchy[] = $selected_category->name;
        
        // カテゴリの文脈を構築
        $selected_topic = implode(' > ', $category_hierarchy);
        
        // 選択されたカテゴリIDを保存（投稿時に使用）
        $this->current_category_id = $selected_cat_id;

        $system = "あなたは{$main_topic}専門の日本語ライター。必ずJSONのみ返す。Markdown/HTMLは返さない。";
        
        $prompt = <<<EOT
根本テーマは「{$main_topic}」の「{$selected_topic}」について。
EOT;

        $prompt .= <<<EOT
毎回、切り口を変え、同じ趣旨の繰り返しを避ける。

出力はJSON:
{
  "title": "タイトル（32字以内・句点なし）",
  "angle": "切り口をひとこと",
  "outline": ["H2見出し1","H2見出し2","H2見出し3","H2見出し4"]
}

ルール：
- タイトルは「{$selected_topic}」に関連する具体的な内容
- 切り口は読者の興味を引く視点
- アウトラインは論理的な流れで4つのセクション

ランダム種: {$seed}
EOT;

        $topic = $this->openai_client->get_json($api_key, $system, $prompt, 800, 0.8);
        if(!$topic || empty($topic['title'])){
            throw new Exception('題材生成に失敗しました。');
        }

        $title = sanitize_text_field($topic['title']);
        $angle = sanitize_text_field(isset($topic['angle'])?$topic['angle']:'');
        $outline_in = isset($topic['outline']) ? (array)$topic['outline'] : array();
        $outline = array();
        foreach ($outline_in as $h2) {
            $h2 = sanitize_text_field($h2);
            if ($h2 !== '') $outline[] = $h2;
        }

        $this->current_angle = $angle;
        return array($title, $angle, $outline);
    }

    /**
     * 本文を生成
     */
    public function generate_content($api_key, $title, $angle, $outline) {
        $system = 'あなたはインターネット回線とWiFiに精通した日本語ライター。必ず有効なJSONを返す。Markdown/HTMLは返さない。';
        $outline_json = json_encode($outline, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $seed = wp_generate_uuid4();
        
        $prompt = <<<EOT
インターネット回線の比較・解説ブログ。タイトルに沿い、angleとoutlineを反映。
- title: {$title}
- angle: {$angle}
- outline: {$outline_json}
分量: 1600〜2300字。

重要な注意点:
- 各セクションの内容は、必ずその直前の見出しに関連する内容のみを含める
- 各H2セクションには1-2個の小見出し（h4）を含める
- 各小見出しの後にはその内容に直接関連するボックスを配置
- ボックスは以下の3種類を状況に応じて使い分ける：
  * box_001：詳細な説明や仕組みを示す（タイトル付き）
  * box_002：具体的な手順やテクニックを示す（シンプルな箇条書き）
  * point-box：特に重要なポイントをまとめる（タイトル付き）

JSON形式で:
{
 "title": "{$title}",
 "sections":[
   {"type":"heading","level":3,"text":"※angleを短く言い換えた実文サブタイトル（プレースホルダ不可）"},
   {"type":"paragraph","text":"導入文。読者に寄り添い、2段落程度で概要"},

   {"type":"heading","level":2,"text":"outlineの1番目"},
   {"type":"paragraph","text":"本文1","emphasis":[{"text":"重要な部分を強調","style":"strong"}]},
   {"type":"heading","level":4,"text":"小見出し1-1（具体的な実文）"},
   {"type":"box","style":"box_001","title":"この小見出しに関連するタイトル","items":[
     "小見出し1-1の内容に直接関連する4-5個の具体的な項目"
   ]},

   {"type":"heading","level":2,"text":"outlineの2番目"},
   {"type":"paragraph","text":"本文2"},
   {"type":"heading","level":4,"text":"小見出し2-1（具体的な実文）"},
   {"type":"box","style":"box_002","items":[
     "小見出し2-1の内容に直接関連する具体的な手順や方法を4-5個"
   ]},
   {"type":"heading","level":4,"text":"小見出し2-2（具体的な実文）"},
   {"type":"box","style":"box_001","title":"この小見出しに関連するタイトル","items":[
     "小見出し2-2の内容に直接関連する4-5個の具体的な項目"
   ]},

   {"type":"heading","level":2,"text":"outlineの3番目"},
   {"type":"paragraph","text":"本文3"},
   {"type":"heading","level":4,"text":"小見出し3-1（具体的な実文）"},
   {"type":"box","style":"box_002","items":[
     "小見出し3-1の内容に直接関連する具体的な手順や方法を4-5個"
   ]},

   {"type":"heading","level":2,"text":"まとめ"},
   {"type":"heading","level":4,"text":"小見出し4-1（具体的な実文）"},
   {"type":"box","style":"point-box","title":"具体的なタイトル","items":[
     "この記事全体の重要ポイントを3-4個"
   ]},
   {"type":"paragraph","text":"次のアクションを自然に案内"}
 ]
}

ルール:
- すべての見出しは具体的な実文とし、プレースホルダ（「小見出し1-1」など）は禁止
- 各ボックスの内容は、直前の小見出し（h4）に密接に関連させる
- box_001とpoint-boxのタイトルは、内容を端的に表す具体的な文言にする
- 同じような表現や言い回しの連続を避ける（ランダム種: {$seed}）
- 箇条書きの項目は、その小見出しの内容に直接関連する具体的なものだけを含める
EOT;

        $data = $this->openai_client->get_json($api_key, $system, $prompt, 2200, 0.65);
        if (!is_array($data) || empty($data['sections'])) {
            throw new Exception('JSON解析失敗');
        }

        return $data;
    }

    public function get_current_angle() {
        return $this->current_angle;
    }

    public function get_current_category_id() {
        return $this->current_category_id;
    }
}
