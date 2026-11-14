<?php
/**
 * 云湖机器人 SDK（轻量版）
 * 仅封装发送消息等必要接口
 */

class YunhuBot
{
    /** @var string 机器人 Token */
    private $token;

    /** @var string 云湖开放接口根地址 */
    private $apiBase = 'https://chat-go.jwzhd.com/open-apis/v1';

    public function __construct($token)
    {
        $this->token = $token;
    }

    /**
     * 发送文本消息
     *
     * @param string $recvId  接收对象ID（用户ID或群ID）
     * @param string $recvType 接收对象类型：user / group
     * @param string $text    消息文本内容
     * @param string $parentId 可选，回复某条消息的 msgId
     * @return array 云湖接口响应
     */
    public function sendText($recvId, $recvType, $text, $parentId = '')
    {
        return $this->request('/bot/send', [
            'recvId'      => (string)$recvId,
            'recvType'    => $recvType,
            'contentType' => 'text',
            'content'     => ['text' => $text],
            'parentId'    => $parentId,
        ]);
    }

    /**
     * 发送 Markdown 消息
     */
    public function sendMarkdown($recvId, $recvType, $text, $parentId = '')
    {
        return $this->request('/bot/send', [
            'recvId'      => (string)$recvId,
            'recvType'    => $recvType,
            'contentType' => 'markdown',
            'content'     => ['text' => $text],
            'parentId'    => $parentId,
        ]);
    }

    /**
     * 发送群聊文本消息并 @ 指定用户
     *
     * @param string $groupId 群ID
     * @param string $text 消息文本
     * @param array $atUserIds 被 @ 的用户ID列表（userId，非昵称）
     */
    public function sendTextAtGroup($groupId, $text, array $atUserIds = [])
    {
        $content = ['text' => $text];
        if (!empty($atUserIds)) {
            $content['at'] = array_values(array_map('strval', $atUserIds));
        }
        return $this->request('/bot/send', [
            'recvId'      => (string)$groupId,
            'recvType'    => 'group',
            'contentType' => 'text',
            'content'     => $content,
        ]);
    }

    /**
     * 发送群聊 Markdown 消息并 @ 指定用户
     *
     * @param string $groupId 群ID
     * @param string $text Markdown 文本
     * @param array $atUserIds 被 @ 的用户ID列表
     */
    public function sendMarkdownAtGroup($groupId, $text, array $atUserIds = [])
    {
        $content = ['text' => $text];
        if (!empty($atUserIds)) {
            $content['at'] = array_values(array_map('strval', $atUserIds));
        }
        return $this->request('/bot/send', [
            'recvId'      => (string)$groupId,
            'recvType'    => 'group',
            'contentType' => 'markdown',
            'content'     => $content,
        ]);
    }

    /**
     * 底层 HTTP 请求
     */
    private function request($path, array $payload)
    {
        $url = $this->apiBase . $path . '?token=' . urlencode($this->token);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['code' => 0, 'msg' => 'curl error: ' . $err, '_http' => $httpCode];
        }
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            $decoded['_http'] = $httpCode;
            return $decoded;
        }
        return ['code' => 0, 'msg' => $response, '_http' => $httpCode];
    }
}
