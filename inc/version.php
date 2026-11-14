<?php
/**
 * 版本信息与更新检查
 *
 * 发版时只需要改下面的 FB_VERSION（与 GitHub Release 的 tag 对应，不带 V 前缀）
 * 例如 Release tag 是 V0.0.2，这里就写 '0.0.2'
 */

define('FB_VERSION', '0.0.1');                 // 当前版本（语义化，不带 V）
define('FB_VERSION_TAG', 'V' . FB_VERSION);    // 展示用，如 V0.0.1
define('FB_APP_NAME', '云湖反馈机器人');
define('FB_REPO', 'liutexi/ec-feedback');      // GitHub 仓库，检查更新用
define('FB_UPDATE_CACHE_TTL', 600);            // 检查结果缓存时间（秒）

/**
 * 解析版本号：V0.0.1 / 0.0.1 / V0.0.1Dev9 / V0.0.1_Dev7
 * 返回 ['nums' => [0,0,1], 'pre' => 'dev9'|'']
 */
function fb_version_parse($tag)
{
    $s = trim((string)$tag);
    $s = preg_replace('/^[vV]/', '', $s);
    if (preg_match('/^(\d+(?:\.\d+)*)(.*)$/', $s, $m)) {
        $nums = array_map('intval', explode('.', $m[1]));
        $pre  = strtolower(trim($m[2], " .-_+"));
        return ['nums' => $nums, 'pre' => $pre];
    }
    return ['nums' => [0], 'pre' => strtolower($s)];
}

/**
 * 比较两个版本号（支持 tag 形式）
 * @return int -1（a 更旧）/ 0（相同）/ 1（a 更新）
 */
function fb_version_compare($a, $b)
{
    $pa = fb_version_parse($a);
    $pb = fb_version_parse($b);

    $len = max(count($pa['nums']), count($pb['nums']));
    for ($i = 0; $i < $len; $i++) {
        $x = isset($pa['nums'][$i]) ? $pa['nums'][$i] : 0;
        $y = isset($pb['nums'][$i]) ? $pb['nums'][$i] : 0;
        if ($x !== $y) {
            return $x < $y ? -1 : 1;
        }
    }
    // 数字相同：带预发布后缀的更低（V0.0.1Dev9 < V0.0.1）
    if ($pa['pre'] === '' && $pb['pre'] !== '') {
        return 1;
    }
    if ($pa['pre'] !== '' && $pb['pre'] === '') {
        return -1;
    }
    $c = strcmp($pa['pre'], $pb['pre']);
    return $c === 0 ? 0 : ($c < 0 ? -1 : 1);
}

/**
 * GET 一个 JSON 接口（带 UA、超时、限流提示）
 * @return array ['ok'=>bool, 'data'=>array|null, 'message'=>string]
 */
function fb_http_get_json($url, $timeout = 8, $proxy = '')
{
    $headers = [
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $ua = 'ec-feedback/' . FB_VERSION . ' (+https://github.com/' . FB_REPO . ')';

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => '服务器未安装 cURL 扩展，无法检查更新'];
    }

    $request = function ($verifyPeer) use ($url, $timeout, $proxy, $headers, $ua) {
        $ch  = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => $verifyPeer,
            CURLOPT_SSL_VERIFYHOST => $verifyPeer ? 2 : 0,
        ];
        if ($proxy !== '') {
            $opt[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($ch, $opt);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$body, $code, $err];
    };

    list($body, $code, $err) = $request(true);
    // 部分服务器 PHP 缺少 CA 证书库，导致校验失败；此时降级重试（只读取公开数据，无敏感信息）
    if ($body === false && stripos((string)$err, 'certificate') !== false) {
        list($body, $code, $err) = $request(false);
    }

    if ($body === false) {
        return ['ok' => false, 'message' => '无法连接 GitHub：' . ($err !== '' ? $err : '网络不可达')];
    }
    if ($code === 403 || $code === 429) {
        return ['ok' => false, 'message' => 'GitHub API 请求过于频繁（已限流），请稍后重试'];
    }
    if ($code !== 200) {
        return ['ok' => false, 'message' => 'GitHub 返回 HTTP ' . $code];
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'GitHub 返回内容无法解析'];
    }
    return ['ok' => true, 'data' => $data];
}

/**
 * 检查更新：读取 GitHub 最新 Release 并与当前版本比较
 *
 * @param array $config 全局配置（用到 data_dir / 可选 http_proxy）
 * @param bool  $force  是否跳过缓存强制联网
 * @return array
 */
function update_check($config, $force = false)
{
    $cacheFile = rtrim($config['data_dir'], '/\\') . '/update_check.json';

    $cached = null;
    if (is_file($cacheFile)) {
        $tmp = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($tmp) && isset($tmp['data']) && is_array($tmp['data'])) {
            $cached = $tmp;
        }
    }

    // 缓存未过期：直接用
    if (!$force && $cached !== null && (time() - (int)($cached['checked_at'] ?? 0)) < FB_UPDATE_CACHE_TTL) {
        return $cached['data'] + ['ok' => true, 'cached' => true];
    }

    $proxy = trim((string)($config['http_proxy'] ?? ''));
    $res = fb_http_get_json('https://api.github.com/repos/' . FB_REPO . '/releases?per_page=30', 8, $proxy);

    if (!$res['ok']) {
        // 联网失败但有旧结果：返回旧结果并标注
        if ($cached !== null) {
            return $cached['data'] + [
                'ok'      => true,
                'cached'  => true,
                'stale'   => true,
                'warning' => $res['message'],
            ];
        }
        return [
            'ok'       => false,
            'local'    => FB_VERSION_TAG,
            'message'  => $res['message'],
            'repo_url' => 'https://github.com/' . FB_REPO . '/releases',
        ];
    }

    // 取「最新发布」的 Release：按发布时间倒序，跳过草稿
    // （直接以 GitHub Release 上的版本号为准，不自己做版本排序）
    $best     = null;
    $bestTs   = -1;
    $maxTag   = '';
    foreach ((array)$res['data'] as $r) {
        if (!empty($r['draft']) || empty($r['tag_name'])) {
            continue;
        }
        $ts = strtotime((string)($r['published_at'] ?? $r['created_at'] ?? ''));
        if ($ts === false) {
            $ts = 0;
        }
        if ($best === null || $ts > $bestTs) {
            $best   = $r;
            $bestTs = $ts;
        }
        if ($maxTag === '' || fb_version_compare($r['tag_name'], $maxTag) > 0) {
            $maxTag = (string)$r['tag_name'];
        }
    }
    if ($best === null) {
        return [
            'ok'       => false,
            'local'    => FB_VERSION_TAG,
            'message'  => '仓库中还没有可用的发布版本',
            'repo_url' => 'https://github.com/' . FB_REPO . '/releases',
        ];
    }

    $notes = trim((string)($best['body'] ?? ''));
    if ($notes !== '') {
        $notes = preg_replace("/\r\n?/", "\n", $notes);
        $notes = function_exists('mb_substr') ? mb_substr($notes, 0, 600) : substr($notes, 0, 600);
    }

    $data = [
        'ok'           => true,
        'local'        => FB_VERSION_TAG,
        'local_version'=> FB_VERSION,
        'latest'       => (string)$best['tag_name'],
        'latest_name'  => (string)($best['name'] ?? $best['tag_name']),
        'max_version'  => $maxTag,
        'has_update'   => fb_version_compare($best['tag_name'], FB_VERSION) > 0,
        'prerelease'   => !empty($best['prerelease']),
        'url'          => (string)($best['html_url'] ?? ''),
        'published_at' => (string)($best['published_at'] ?? ''),
        'notes'        => $notes,
        'repo_url'     => 'https://github.com/' . FB_REPO . '/releases',
        'checked_at'   => time(),
        'zip_url'      => '',
        'zip_name'     => '',
    ];

    // 取第一个附件（按约定是 <版本号>.zip）
    foreach ((array)($best['assets'] ?? []) as $a) {
        if (!empty($a['browser_download_url'])) {
            $data['zip_url']  = (string)$a['browser_download_url'];
            $data['zip_name'] = (string)($a['name'] ?? '');
            break;
        }
    }

    @file_put_contents(
        $cacheFile,
        json_encode(['checked_at' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    return $data + ['cached' => false];
}
