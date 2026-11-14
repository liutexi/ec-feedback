<?php
/**
 * 云湖 OAuth2 客户端（第一版：授权码模式 + PKCE S256）
 * 服务地址：https://oauth2.jwzhd.com
 * 参考：https://www.yhchat.com/c/p/45805
 */

class YunhuOAuth
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $authBase;   // 授权服务地址
    private $dataDir;    // 会话存储目录

    public function __construct($config)
    {
        $this->clientId     = $config['oauth_client_id'] ?? '';
        $this->clientSecret = $config['oauth_client_secret'] ?? '';
        $this->redirectUri  = $config['oauth_redirect_uri'] ?? '';
        $this->authBase     = 'https://oauth2.jwzhd.com';
        $this->dataDir      = $config['data_dir'];
    }

    /**
     * 生成 PKCE code_verifier
     */
    private function generateCodeVerifier()
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
        $len = 64;
        $verifier = '';
        for ($i = 0; $i < $len; $i++) {
            $verifier .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $verifier;
    }

    /**
     * 根据 code_verifier 生成 code_challenge (BASE64URL(SHA256(verifier)))
     */
    private function generateCodeChallenge($verifier)
    {
        $hash = hash('sha256', $verifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * 生成随机 state
     */
    private function generateState()
    {
        return bin2hex(random_bytes(24)); // 48 字符
    }

    /**
     * 生成授权 URL，并把 state/verifier 保存到会话文件
     * 返回授权 URL，供跳转
     */
    public function buildAuthorizeUrl($returnTo = '/')
    {
        $state    = $this->generateState();
        $verifier = $this->generateCodeVerifier();
        $challenge = $this->generateCodeChallenge($verifier);

        // 保存待处理授权记录（带有效期）
        $this->savePending($state, [
            'code_verifier' => $verifier,
            'return_to'     => $returnTo,
            'created_at'    => time(),
        ]);

        $params = http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->clientId,
            'redirect_uri'          => $this->redirectUri,
            'scope'                 => 'openid profile',
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return $this->authBase . '/oauth/authorize?' . $params;
    }

    /**
     * 处理回调：校验 state、兑换 token、获取用户信息
     * 返回用户信息数组 ['user_id'=>, 'nickname'=>, 'avatar_url'=>]，失败返回 null 并设置 $error
     */
    public function handleCallback($state, $code, &$error)
    {
        // 1. 校验 state
        $pending = $this->getPending($state);
        if (!$pending) {
            $error = '授权会话已过期或不存在，请重新登录';
            return null;
        }

        // 2. 消费记录，避免重复处理
        $this->consumePending($state);

        // 3. 兑换 token
        $token = $this->exchangeToken($code, $pending['code_verifier']);
        if (!$token || empty($token['access_token'])) {
            $error = '令牌兑换失败';
            return null;
        }

        // 4. 获取用户信息
        $user = $this->getUserInfo($token['access_token']);
        if (!$user || empty($user['user_id'])) {
            $error = '获取用户信息失败';
            return null;
        }

        // 附带 token 信息（供后续刷新）
        $user['_token'] = $token;
        return $user;
    }

    /**
     * 用授权码兑换 token
     */
    private function exchangeToken($code, $codeVerifier)
    {
        $post = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code_verifier' => $codeVerifier,
        ];
        $res = $this->httpPost('/oauth/token', $post);
        return $res;
    }

    /**
     * 获取用户信息
     */
    public function getUserInfo($accessToken)
    {
        $res = $this->httpGet('/api/userinfo', ['Authorization: Bearer ' . $accessToken]);
        return $res;
    }

    /**
     * 刷新令牌
     */
    public function refreshToken($refreshToken)
    {
        $post = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
        return $this->httpPost('/oauth/token', $post);
    }

    /* ---------- HTTP 请求 ---------- */

    private function httpPost($path, array $formData)
    {
        $ch = curl_init($this->authBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($formData),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function httpGet($path, array $headers = [])
    {
        $ch = curl_init($this->authBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /* ---------- 待处理授权记录的持久化（文件会话） ---------- */

    private function pendingDir()
    {
        $dir = rtrim($this->dataDir, '/\\') . '/oauth_pending';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private function savePending($state, array $data)
    {
        $file = $this->pendingDir() . '/' . $state . '.json';
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function getPending($state)
    {
        $file = $this->pendingDir() . '/' . $state . '.json';
        if (!file_exists($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }
        // 有效期 10 分钟
        if (time() - ($data['created_at'] ?? 0) > 600) {
            @unlink($file);
            return null;
        }
        return $data;
    }

    private function consumePending($state)
    {
        $file = $this->pendingDir() . '/' . $state . '.json';
        @unlink($file);
    }
}
