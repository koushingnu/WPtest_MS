<?php
/**
 * OpenAI APIクライアントクラス
 */
class AIAP_OpenAI_Client {
    /**
     * OpenAI APIからJSONレスポンスを取得
     */
    public function get_json($api_key, $system, $prompt, $max_tokens=1800, $temperature=0.5) {
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
     * GPT Image 1.5で画像を生成（DALL-E 3の後継モデル）
     */
    public function generate_image($api_key, $prompt, $size='1792x1024', &$err='') {
        $err = '';

        $data = [
            'model' => 'gpt-image-1.5',
            'prompt' => $prompt,
            'size' => $size,
            'n' => 1,
            'quality' => 'high',
            'output_format' => 'png'
        ];

        $ch = curl_init('https://api.openai.com/v1/images/generations');
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

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

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

        $result = json_decode($response, true);
        
        // GPT Imageモデルはb64_jsonで直接base64データを返す
        if (!isset($result['data'][0]['b64_json'])) {
            $err = "画像データが見つかりません: " . $response;
            error_log('AI Auto Poster 画像生成エラー: ' . $err);
            return null;
        }

        // すでにbase64エンコードされているので、そのまま返す
        return $result['data'][0]['b64_json'];
    }
}
