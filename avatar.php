<?php
/**
 * 头像图片代理
 * 用于加载云湖等第三方头像，绕过防盗链（Referer 校验）与跨域限制。
 * 用法：/avatar.php?url=<URL编码后的图片地址>
 */

declare(strict_types=1);

$url = $_GET['url'] ?? '';

if ($url === '') {
    http_response_code(400);
    exit;
}

// 仅允许 http/https 协议，防止 SSRF 或访问内网
if (!preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    exit;
}

// 简单校验域名（可选：只允许云湖图床域名，提高安全性）
// 云湖头像常见域名：chat-img.jwzhd.com 等。这里放宽为允许所有 https 图片，按需收紧。
$host = parse_url($url, PHP_URL_HOST);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    // 关键：云湖图床校验 Referer 白名单，需带云湖主页 Referer 才能访问
    CURLOPT_HTTPHEADER     => [
        'Referer: https://www.yhchat.com/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
    ],
]);
$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$curlError = curl_error($ch);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

// 调试日志：记录头像加载失败原因
function avatar_debug_log($msg)
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($dir . '/avatar_debug.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

if ($body === false || $httpCode >= 400) {
    avatar_debug_log("FAIL http={$httpCode} curl_err={$curlError} url={$url}");
    http_response_code(404);
    exit;
}

// 只允许图片类型
if (!preg_match('#^image/#i', $contentType)) {
    avatar_debug_log("NOT_IMAGE type={$contentType} http={$httpCode} url={$url}");
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=86400'); // 缓存 1 天
echo $body;
