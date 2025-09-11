<?php
/**
 * Plugin Name: AI Auto Poster Lite (Box)
 * Description: 最小構成。OpenAIのJSONをGutenbergブロックへ変換（見出し/段落/リスト/ボックス対応）+ タイトル/題材のバリエーション + 実行後は完了ログ表示 + アイキャッチ自動生成(DALL·E 2)
 * Version: 0.4.0
 * Author: You
 */
if (!defined('ABSPATH')) exit;

class AIAP_Lite_Box {
    const OPT_KEY    = 'aiap_lite_settings';
    const ACTION_RUN = 'aiap_lite_run';
    const NOTICE_KEY = 'aiap_lite_notice';

    function __construct(){
        add_action('after_setup_theme',  [$this,'ensure_thumbnails']);
        add_action('admin_menu',         [$this,'menu']);
        add_action('admin_init',         [$this,'register']);
        add_action('admin_post_'.self::ACTION_RUN, [$this,'handle_run']);
        add_action('wp_enqueue_scripts', [$this,'styles']);
        add_action('admin_notices',      [$this,'admin_notices']);
    }

    function ensure_thumbnails(){
        if (!current_theme_supports('post-thumbnails')) add_theme_support('post-thumbnails');
        add_post_type_support('post','thumbnail');
    }

    /* ========== 管理UI ========== */
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

        add_settings_field('gen_featured','アイキャッチ自動生成（DALL·E 2）', function(){
            $o=get_option(self::OPT_KEY,[]);
            $checked = !empty($o['gen_featured']) ? 'checked' : '';
            printf('<label><input type="checkbox" name="%s[gen_featured]" value="1" %s> 生成する</label><br/>', esc_attr(self::OPT_KEY), $checked);
            printf('<input type="text" name="%s[image_hint]" value="%s" class="regular-text" placeholder="例: 清潔感, 医療系ブログ向け, 青系トーン, シンプル"/>',
                esc_attr(self::OPT_KEY), esc_attr($o['image_hint']??''));
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
        ], 90);
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

    /* ========== 実行メイン ========== */
    function handle_run(){
        if(!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer(self::ACTION_RUN);

        $o=get_option(self::OPT_KEY,[]);
        $api_key=trim($o['api_key']??'');
        if(!$api_key){
            $this->set_notice('error','APIキーが未設定です。設定画面で保存してください。');
            wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
        }

        $seed = wp_generate_uuid4();

        /* (1) 題材生成 */
        $system1 = 'あなたはAGA専門の日本語ライター。必ずJSONのみ返す。';
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
            $this->set_notice('error','題材生成に失敗しました。'); wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
        }
        $title   = sanitize_text_field($topic['title']);
        $angle   = sanitize_text_field($topic['angle'] ?? '');
        $outline = array_values(array_filter(array_map('sanitize_text_field',(array)($topic['outline'] ?? []))));

        /* (2) 本文生成 */
        $system2 = 'あなたは医療系SEOに精通した日本語ライター。必ず有効なJSONを返す。';
        $outline_json = json_encode($outline, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $prompt2 = <<<EOT
ヘアクリニック向けブログ。タイトルに沿い、angleとoutlineを反映。
- title: {$title}
- angle: {$angle}
- outline: {$outline_json}
分量: 1600〜2300字。

JSON形式で:
{
 "title": "{$title}",
 "sections":[
   {"type":"heading","level":3,"text":"導入のh3"},
   {"type":"paragraph","text":"導入文。読者に寄り添い、2段落程度で概要"},

   {"type":"heading","level":2,"text":"outlineの1番目"},
   {"type":"paragraph","text":"本文1","emphasis":[{"text":"重要な部分を強調","style":"strong"}]},
   {"type":"list","items":["ポイント1","ポイント2","ポイント3"]},

   {"type":"heading","level":2,"text":"outlineの2番目"},
   {"type":"paragraph","text":"本文2"},

   {"type":"heading","level":2,"text":"outlineの3番目"},
   {"type":"box","style":"box_001","purpose":"signs","title":"","items":[
     "直前H2に関連する兆候を4〜6個"
   ]},

   {"type":"heading","level":2,"text":"outlineの4番目"},
   {"type":"box","style":"box_002","purpose":"actions","title":"","items":[
     "直前H2に関連する改善行動を3〜5個"
   ]},

   {"type":"heading","level":2,"text":"まとめ"},
   {"type":"box","style":"point-box","title":"重要ポイント","items":["本文要点を3〜5個"]},
   {"type":"paragraph","text":"次のアクションを自然に案内"}
 ]
}
ルール:
- 箇条書きitemsは兆候と行動を混在させない。
- "purpose":"signs"なら兆候、"actions"なら行動を書く。
- ボックスの内容は必ず直前H2に関連。
EOT;

        try{
            $data = $this->openai_json($api_key, $system2, $prompt2, 2200, 0.65);
            if (!is_array($data) || empty($data['sections'])) throw new \Exception('JSON解析失敗');

            $final_title = sanitize_text_field($data['title'] ?? $title);
            $post_content = $this->render_sections($data['sections']);
            if(function_exists('parse_blocks') && function_exists('serialize_blocks')){
                $post_content = serialize_blocks(array_filter(parse_blocks($post_content)));
            }

            $post_id = wp_insert_post([
                'post_title'=>$final_title,
                'post_content'=>$post_content,
                'post_status'=>$o['post_status']??'draft',
                'post_author'=>get_current_user_id()?:1
            ],true);
            if(is_wp_error($post_id)) throw new \Exception($post_id->get_error_message());

            /* (3) アイキャッチ生成 */
            if(!empty($o['gen_featured'])){
                $hint=trim($o['image_hint']??'');
                $img_prompt=$this->build_image_prompt($final_title,$angle,$hint);
                $b64=$this->openai_image($api_key,$img_prompt,'1024x1024',$err);
                if($b64){
                    $att_id=$this->save_base64_image_as_attachment($b64,$final_title,$post_id,$msg);
                    if($att_id) set_post_thumbnail($post_id,$att_id);
                } else {
                    $this->set_notice('error','記事は作成、ただしアイキャッチ未設定: '.$err,get_edit_post_link($post_id,''));
                    wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
                }
            }

            $this->set_notice('success','記事生成が完了しました。',get_edit_post_link($post_id,'')); 
            wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
        }catch(\Exception $e){
            $this->set_notice('error','記事生成に失敗: '.$e->getMessage());
            wp_safe_redirect(admin_url('options-general.php?page=aiap-lite')); exit;
        }
    }

    /* ========== OpenAI API ========== */
    private function openai_json($api_key,$system,$prompt,$max_tokens=1800,$temperature=0.5){
        $resp=wp_remote_post('https://api.openai.com/v1/chat/completions',[
            'headers'=>['Authorization'=>'Bearer '.$api_key,'Content-Type'=>'application/json'],
            'timeout'=>300,
            'body'=>wp_json_encode([
                'model'=>'gpt-4o-mini',
                'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$prompt]],
                'response_format'=>['type'=>'json_object'],
                'temperature'=>$temperature,
                'max_tokens'=>$max_tokens
            ]),
        ]);
        if(is_wp_error($resp)) throw new \Exception($resp->get_error_message());
        if(wp_remote_retrieve_response_code($resp)!==200) throw new \Exception(wp_remote_retrieve_body($resp));
        $body=json_decode(wp_remote_retrieve_body($resp),true);
        $json=trim($body['choices'][0]['message']['content']??'');
        $p1=strpos($json,'{');$p2=strrpos($json,'}');
        if($p1!==false&&$p2!==false)$json=substr($json,$p1,$p2-$p1+1);
        return json_decode($json,true);
    }

    private function openai_image($api_key,$prompt,$size,&$err=''){
        $resp=wp_remote_post('https://api.openai.com/v1/images/generations',[
            'headers'=>['Authorization'=>'Bearer '.$api_key,'Content-Type'=>'application/json'],
            'timeout'=>300,
            'body'=>wp_json_encode(['model'=>'dall-e-2','prompt'=>$prompt,'size'=>$size,'n'=>1]),
        ]);
        if(is_wp_error($resp)){ $err=$resp->get_error_message(); return null; }
        if(wp_remote_retrieve_response_code($resp)!==200){ $err=wp_remote_retrieve_body($resp); return null; }
        $j=json_decode(wp_remote_retrieve_body($resp),true);
        if(!empty($j['data'][0]['url'])){
            $bin=$this->download_image_binary($j['data'][0]['url'],$err);
            return $bin?base64_encode($bin):null;
        }
        return $j['data'][0]['b64_json']??null;
    }
    private function download_image_binary($url,&$err=''){
        $r=wp_remote_get($url,['timeout'=>300]); if(is_wp_error($r)){ $err=$r->get_error_message(); return null; }
        if(wp_remote_retrieve_response_code($r)!==200){ $err='HTTP '.wp_remote_retrieve_response_code($r); return null; }
        return wp_remote_retrieve_body($r);
    }

    private function build_image_prompt($title,$angle,$hint=''){
        return "Web記事用ヒーロー画像。AGAテーマ。{$title} {$angle} 抽象的・医療系のビジュアル。清潔感・信頼感。".$hint;
    }

    private function save_base64_image_as_attachment($b64,$title,$post_id,&$msg=''){
        $upload=wp_upload_dir(); if(!empty($upload['error'])){ $msg=$upload['error']; return 0; }
        $data=base64_decode($b64); if(!$data){ $msg='base64 decode失敗'; return 0; }
        $fname=sanitize_title($title).'-'.time().'.jpg';
        $filepath=$upload['path'].'/'.$fname;
        file_put_contents($filepath,$data);
        $fileurl=$upload['url'].'/'.$fname;
        $att_id=wp_insert_attachment([
            'post_mime_type'=>'image/jpeg',
            'post_title'=>$title,
            'post_status'=>'inherit',
            'guid'=>$fileurl
        ],$filepath,$post_id);
        require_once ABSPATH.'wp-admin/includes/image.php';
        wp_update_attachment_metadata($att_id,wp_generate_attachment_metadata($att_id,$filepath));
        return $att_id;
    }

    /* ========== JSON→Gutenberg ========== */
    private function clean_bullet_items(array $items):array{
        $out=[]; foreach($items as $i){ $s=trim(wp_strip_all_tags((string)$i));
            $s=preg_replace('/^\s*[・\-\*•●◦･｡]+/u','',$s);
            if($s!=='') $out[]=$s; }
        return array_values(array_unique($out));
    }
    private function render_sections(array $sections):string{
        $out=[];$last_h2='';
        foreach($sections as $sec){
            $type=$sec['type']??'';
            switch($type){
                case 'heading':
                    $level=max(2,min(4,intval($sec['level']??2)));
                    $out[]=$this->blk_heading($level,(string)($sec['text']??''));
                    if($level===2)$last_h2=trim((string)($sec['text']??''));
                    break;
                case 'paragraph':
                    $txt=(string)($sec['text']??'');
                    $out[]=$this->blk_para($txt);
                    break;
                case 'list':
                    $items=$this->clean_bullet_items((array)($sec['items']??[]));
                    if($items) $out[]=$this->blk_list($items);
                    break;
                case 'box':
                    $style=in_array($sec['style']??'',['box_001','box_002','point-box'])?$sec['style']:'box_001';
                    $title=trim((string)($sec['title']??''));
                    $purpose=(string)($sec['purpose']??'');
                    $items=$this->clean_bullet_items((array)($sec['items']??[]));
                    $items_html=$items?implode("<br>",array_map(fn($i)=>"・".$i,$items)):'';
                    if($title===''&&$last_h2!==''){
                        if($purpose==='actions'||$style==='box_002')$title=$last_h2.'のセルフケア';
                        elseif($purpose==='signs'||$style==='box_001')$title=$last_h2.'で注意したいサイン';
                    }
                    if($style==='point-box'){
                        $html="<div class='point-box'><div class='point-box-title'>".esc_html($title)."</div><div class='point-box-content'><p>{$items_html}</p></div></div>";
                    }elseif($style==='box_002'){
                        $html="<div class='box_002'><p>{$items_html}</p></div>";
                    }else{
                        $html="<div class='box_001'><div class='box_001-title'>".esc_html($title)."</div><div class='box_001-content'><p>{$items_html}</p></div></div>";
                    }
                    $out[]=$this->blk_html($html);
                    break;
            }
        }
        return implode("\n",$out);
    }
    private function blk_heading(int $level,string $text):string{
        $text=esc_html($text);
        return "<!-- wp:heading {\"level\":$level} --><h{$level} class='wp-block-heading'>{$text}</h{$level}><!-- /wp:heading -->";
    }
    private function blk_para(string $html):string{
        return "<!-- wp:paragraph --><p>".wp_kses_post($html)."</p><!-- /wp:paragraph -->";
    }
    private function blk_list(array $items):string{
        $lis=array_map(fn($t)=>"<li>".esc_html($t)."</li>",$items);
        return "<!-- wp:list --><ul>".implode('',$lis)."</ul><!-- /wp:list -->";
    }
    private function blk_html(string $raw):string{
        return "<!-- wp:html -->$raw<!-- /wp:html -->";
    }
}
new AIAP_Lite_Box();
