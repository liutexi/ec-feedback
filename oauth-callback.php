<?php
/**
 * 云湖 OAuth2 授权回调
 * 云湖授权完成后跳转回本地址，附带的 code、state 在这里处理。
 * 需在云湖 OAuth2 授权机器人登记本地址作为完整回调 URL。
 */

declare(strict_types=1);

require __DIR__ . '/inc/YunhuOAuth.php';
require __DIR__ . '/inc/functions.php';

$config = require __DIR__ . '/config.php';

session_start();

$siteUrl = rtrim($config['site_url'], '/');
$oauth = new YunhuOAuth($config);

$state = $_GET['state'] ?? '';
$code  = $_GET['code'] ?? '';
$error = $_GET['error'] ?? '';

// 用户拒绝授权
if ($error === 'access_denied') {
    header('Location: ' . $siteUrl . '/fb-admin.php?login_error=' . urlencode('您已取消授权'));
    exit;
}

// 缺少 code 或 state
if ($code === '' || $state === '') {
    header('Location: ' . $siteUrl . '/fb-admin.php?login_error=' . urlencode('授权参数缺失'));
    exit;
}

// 处理回调：校验 state、兑换 token、获取用户信息
$oauthError = '';
$user = $oauth->handleCallback($state, $code, $oauthError);

if (!$user) {
    header('Location: ' . $siteUrl . '/fb-admin.php?login_error=' . urlencode($oauthError ?: '登录失败'));
    exit;
}

// 调试：记录获取到的用户信息（含 avatar_url），排查头像问题
@file_put_contents(__DIR__ . '/data/oauth_user_debug.log',
    '[' . date('Y-m-d H:i:s') . '] ' . json_encode($user, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND);

// 建立登录会话
$_SESSION['yh_user'] = [
    'user_id'     => $user['user_id'],
    'nickname'    => $user['nickname'] ?? '',
    'avatar_url'  => $user['avatar_url'] ?? '',
    'access_token'  => $user['_token']['access_token'] ?? '',
    'refresh_token' => $user['_token']['refresh_token'] ?? '',
    'expires_at'    => time() + (int)($user['_token']['expires_in'] ?? 7200),
];

// 判断是否管理员
$adminIds = $config['admin_user_ids'] ?? [];
$isAdmin = in_array((string)$user['user_id'], array_map('strval', $adminIds), true);
$_SESSION['yh_user']['is_admin'] = $isAdmin;

// 跳转回来源
$returnTo = $_SESSION['oauth_return_to'] ?? '/fb-admin.php';
unset($_SESSION['oauth_return_to']);
if ($returnTo === '' || strpos($returnTo, '//') !== false) {
    $returnTo = '/fb-admin.php';
}
header('Location: ' . $siteUrl . $returnTo);
exit;
