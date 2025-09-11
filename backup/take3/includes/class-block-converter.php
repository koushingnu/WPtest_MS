<?php
/**
 * JSONをGutenbergブロックに変換するクラス
 */
class AIAP_Block_Converter {
    private $current_angle = '';
    private $last_h2 = '';

    public function __construct($current_angle = '') {
        $this->current_angle = $current_angle;
    }

    /**
     * セクションをブロックに変換
     */
    public function convert_sections($sections) {
        $out = array();
        foreach($sections as $sec) {
            $type = isset($sec['type']) ? $sec['type'] : '';
            switch($type) {
                case 'heading':
                    $out[] = $this->convert_heading($sec);
                    break;
                case 'paragraph':
                    $out[] = $this->convert_paragraph($sec);
                    break;
                case 'list':
                    $out[] = $this->convert_list($sec);
                    break;
                case 'box':
                    $out[] = $this->convert_box($sec);
                    break;
            }
        }
        return implode("\n\n", $out);
    }

    /**
     * 見出しを変換
     */
    private function convert_heading($sec) {
        $level = isset($sec['level']) ? intval($sec['level']) : 2;
        $level = max(2, min(4, $level));
        $raw_text = isset($sec['text']) ? (string)$sec['text'] : '';
        
        if($level === 3) {
            $t = trim($raw_text);
            if($t === '' || preg_match('/^(導入のh3|intro|introduction|placeholder|※)/iu', $t)) {
                $raw_text = ($this->current_angle !== '') ? $this->current_angle : 'AGAのポイント';
            }
        }
        
        if($level === 2) {
            $this->last_h2 = trim($raw_text);
        }
        
        return $this->create_heading_block($level, $raw_text);
    }

    /**
     * 段落を変換
     */
    private function convert_paragraph($sec) {
        $txt = isset($sec['text']) ? (string)$sec['text'] : '';
        if(!empty($sec['emphasis']) && is_array($sec['emphasis'])) {
            foreach($sec['emphasis'] as $emp) {
                $t = isset($emp['text']) ? $emp['text'] : '';
                $style = isset($emp['style']) ? $emp['style'] : '';
                if(!$t) continue;
                
                if($style === 'underline_yellow') {
                    $rep = "<strong><span class=\"under-line-yellow\">" . esc_html($t) . "</span></strong>";
                } elseif($style === 'underline_pink') {
                    $rep = "<strong><span class=\"under-line-pink\">" . esc_html($t) . "</span></strong>";
                } else {
                    $rep = "<strong>" . esc_html($t) . "</strong>";
                }
                $txt = str_replace($t, $rep, $txt);
            }
        }
        return $this->create_paragraph_block($txt);
    }

    /**
     * リストを変換
     */
    private function convert_list($sec) {
        $items = $this->clean_bullet_items(isset($sec['items']) ? (array)$sec['items'] : array());
        if(!empty($items)) {
            return $this->create_list_block($items);
        }
        return '';
    }

    /**
     * ボックスを変換
     */
    private function convert_box($sec) {
        $style_in = isset($sec['style']) ? $sec['style'] : '';
        $style = in_array($style_in, array('box_001','box_002','point-box'), true) ? $style_in : 'box_001';
        $title = trim(isset($sec['title']) ? $sec['title'] : '');
        $purpose = isset($sec['purpose']) ? $sec['purpose'] : '';
        $items = $this->clean_bullet_items(isset($sec['items']) ? (array)$sec['items'] : array());

        $items_html = '';
        if(!empty($items)) {
            $tmp = array();
            foreach($items as $i) {
                $tmp[] = "・" . esc_html($i);
            }
            $items_html = implode("<br>\n", $tmp);
        }

        if($title === '' && $this->last_h2 !== '') {
            if($purpose === 'actions' || $style === 'box_002') {
                $title = $this->last_h2 . 'のポイント';
            } elseif($purpose === 'signs' || $style === 'box_001') {
                $title = $this->last_h2 . 'の主な症状';
            } else {
                $title = $this->last_h2;
            }
        }

        if($style === 'point-box') {
            $html = "<div class='point-box'><div class='point-box-title'>" . esc_html($title) . "</div><div class='point-box-content'><p>{$items_html}</p></div></div>";
        } elseif($style === 'box_002') {
            $html = "<div class='box_002'><p>{$items_html}</p></div>";
        } else {
            $html = "<div class='box_001'><div class='box_001-title'>" . esc_html($title) . "</div><div class='box_001-content'><p>{$items_html}</p></div></div>";
        }

        return $this->create_html_block($html);
    }

    /**
     * 箇条書きアイテムをクリーンアップ
     */
    private function clean_bullet_items($items) {
        $out = array();
        foreach($items as $i) {
            $s = (string)$i;
            $s = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00A0}]/u', '', $s);
            $s = trim(wp_strip_all_tags($s));
            $s = preg_replace('/^\s*[・\-\*•●◦･｡]+/u', '', $s);
            $s = trim($s);
            if($s !== '' && !preg_match('/^[・\-\*•●◦･｡\s]+$/u', $s)) {
                if(!in_array($s, $out, true)) $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * ブロック生成メソッド
     */
    private function create_heading_block($level, $text) {
        $level = max(2, min(4, intval($level)));
        $text = esc_html(trim($text));
        return "<!-- wp:heading {\"level\":$level} --><h{$level} class='wp-block-heading'>{$text}</h{$level}><!-- /wp:heading -->";
    }

    private function create_paragraph_block($html) {
        return "<!-- wp:paragraph --><p>" . wp_kses_post($html) . "</p><!-- /wp:paragraph -->";
    }

    private function create_list_block($items) {
        $lis = array();
        foreach($items as $t) {
            $lis[] = "<li>" . esc_html($t) . "</li>";
        }
        return "<!-- wp:list --><ul>" . implode('', $lis) . "</ul><!-- /wp:list -->";
    }

    private function create_html_block($raw) {
        return "<!-- wp:html -->" . $raw . "<!-- /wp:html -->";
    }
}
