<?php
/**
 * 公共辅助函数
 */

/**
 * 获取反馈状态中文名
 */
function status_label($status)
{
    $map = [
        'pending'  => '待跟进',
        'replied'  => '已回复',
        'resolved' => '已解决',
        'planned'  => '已加入开发计划',
        'closed'   => '已关闭',
    ];
    return isset($map[$status]) ? $map[$status] : $status;
}

/**
 * 获取反馈类型中文名
 */
function type_label($type)
{
    $map = [
        'bug'     => '缺陷',
        'suggest' => '建议',
        'other'   => '其他',
    ];
    return isset($map[$type]) ? $map[$type] : $type;
}

/**
 * 获取优先级中文名
 */
function priority_label($priority)
{
    $map = [
        'high'   => '高',
        'normal' => '普通',
        'low'    => '低',
    ];
    return isset($map[$priority]) ? $map[$priority] : $priority;
}

/**
 * 格式化时间（YYYY-MM-DD HH:MM）
 */
function fmt_time($ts)
{
    return date('Y-m-d H:i', (int)$ts);
}

/**
 * 完整反馈编号（带 # 前缀），如 #FB-202610-0001
 */
function full_no($no)
{
    return '#FB-' . ltrim($no, '#');
}

/**
 * HTML 转义
 */
function e($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * 生成头像图片地址（通过本地代理，绕过防盗链）
 * 若已是本站相对路径则直接返回；否则用 /avatar.php?url= 代理
 */
function avatar_url($url, $site = '')
{
    $url = (string)$url;
    if ($url === '') {
        return '';
    }
    // 本地相对路径直接返回
    if (strpos($url, '/') === 0) {
        return $url;
    }
    // 已指向本站代理则直接返回
    if (strpos($url, 'avatar.php') !== false) {
        return $url;
    }
    $site = rtrim($site, '/');
    return $site . '/avatar.php?url=' . urlencode($url);
}

/**
 * 从指令消息文本中提取反馈内容
 * 指令消息 content.text 形如 "/反馈 反馈内容" 或 "反馈 反馈内容" 或 "/反馈"
 * 返回去除触发词部分后的内容；若只有触发词本身则返回空字符串
 */
function extract_feedback_content($rawText, $trigger)
{
    $text = trim((string)$rawText);
    $trigger = trim((string)$trigger);
    $trigger = ltrim($trigger, '/#'); // 去掉触发词自带的斜杠，统一匹配

    if ($trigger === '') {
        return $text;
    }

    // 匹配 "/反馈"、"反馈"、"#反馈" 等前缀（可带斜杠）
    $pattern = '#^/?\s*' . preg_quote($trigger, '#') . '(?:\s+|$)#u';
    if (preg_match($pattern, $text)) {
        $rest = preg_replace($pattern, '', $text, 1);
        return trim($rest);
    }

    // 若整条消息就是触发词本身
    if ($text === $trigger || $text === '/' . $trigger || $text === '#' . $trigger) {
        return '';
    }

    // 兜底：直接返回整条（去掉可能的斜杠）
    return trim(ltrim($text, '/#'));
}

/* ==================== 站点外观配置（后台可视化设置） ==================== */

/**
 * 站点名称（导航左上角文字）；未配置时回退 "AI 反馈"
 */
function site_brand_name($settings)
{
    $name = trim((string)$settings->get('site_name', ''));
    return $name !== '' ? $name : 'AI 反馈';
}

/**
 * 站点 Logo 图片地址（可直接放进 img src / link href）
 * 优先级：后台配置（上传文件或外链） > 根目录 logo.png 等旧方式 > 空（用内置 SVG）
 * 上传的本地文件会自动追加文件时间戳，避免替换后浏览器缓存旧图
 */
function site_logo_url($settings)
{
    $logo = trim((string)$settings->get('site_logo', ''));
    if ($logo !== '') {
        if (strpos($logo, '/uploads/site-logo.') === 0) {
            $file = dirname(__DIR__) . '/uploads/' . basename($logo);
            if (is_file($file)) {
                return $logo . '?v=' . filemtime($file);
            }
            // 上传文件已丢失 → 忽略失效配置，继续走兜底
        } else {
            return $logo; // 外链 URL 或自定义站内路径
        }
    }

    // 兼容旧部署方式：项目根目录直接放 logo.png / logo.jpg / ...
    $root = dirname(__DIR__);
    foreach (['logo.png', 'logo.jpg', 'logo.jpeg', 'logo.webp', 'logo.gif'] as $f) {
        if (is_file($root . '/' . $f)) {
            return '/' . $f . '?v=' . filemtime($root . '/' . $f);
        }
    }
    return '';
}

/**
 * 校验并规范化后台填写的 Logo 地址
 * 允许：http(s) 外链、以单个 / 开头的站内路径；其余（含 javascript:、//、控制字符）一律拒绝
 * @return string|null 规范化后的值；null 表示非法
 */
function normalize_site_logo_url($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $raw)) {
        return null;
    }
    if (preg_match('#^https?://\S+$#i', $raw)) {
        return $raw;
    }
    if ($raw[0] === '/' && substr($raw, 0, 2) !== '//') {
        if (strpos($raw, '..') !== false) {
            return null;
        }
        return $raw;
    }
    return null;
}

/* ==================== 主题色 ==================== */

/**
 * 校验主题色：#rgb / #rrggbb（可省略 #）
 * @return string|null 规范化成小写 #rrggbb；空字符串原样返回；null 表示非法
 */
function normalize_theme_color($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    if ($raw[0] !== '#') {
        $raw = '#' . $raw;
    }
    if (preg_match('/^#([0-9a-fA-F]{3})$/', $raw, $m)) {
        $c = $m[1];
        return '#' . $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
    }
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $raw)) {
        return strtolower($raw);
    }
    return null;
}

/**
 * #rrggbb → [r, g, b]
 */
function theme_hex_to_rgb($hex)
{
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return [79, 107, 254]; // 兜底：默认蓝
    }
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * 与目标色按比例混合（$ratio=0 取原色，1 取目标色）
 */
function theme_mix($rgb, $target, $ratio)
{
    $ratio = max(0, min(1, (float)$ratio));
    $out = [];
    for ($i = 0; $i < 3; $i++) {
        $out[$i] = (int)round($rgb[$i] + ($target[$i] - $rgb[$i]) * $ratio);
    }
    return $out;
}

/**
 * 相对亮度 0~1（用于判断主色上的文字该用黑还是白）
 */
function theme_luma($rgb)
{
    return (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]) / 255;
}

/**
 * 由主色推导一整套主题变量
 * @return array{accent:string,strong:string,soft:string,rgb:string,fg:string,luma:float}
 */
function theme_palette($hex)
{
    $rgb    = theme_hex_to_rgb($hex);
    $luma   = theme_luma($rgb);
    $light  = $luma > 0.62;                            // 很亮的主色：文字转深、浅底加深一档
    $accent = sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    $strong = theme_mix($rgb, [0, 0, 0], $light ? 0.22 : 0.14);
    $soft   = theme_mix($rgb, [255, 255, 255], $light ? 0.84 : 0.92);
    $fg     = $light ? '#1f2329' : '#ffffff';
    // 作为文字色时，浅色主色（如亮黄）压深一档，避免白底上看不清
    $textRgb = $luma > 0.5 ? theme_mix($rgb, [0, 0, 0], 0.55) : $rgb;
    return [
        'accent' => $accent,
        'strong' => sprintf('#%02x%02x%02x', $strong[0], $strong[1], $strong[2]),
        'soft'   => sprintf('#%02x%02x%02x', $soft[0], $soft[1], $soft[2]),
        'text'   => sprintf('#%02x%02x%02x', $textRgb[0], $textRgb[1], $textRgb[2]),
        'rgb'    => $rgb[0] . ', ' . $rgb[1] . ', ' . $rgb[2],
        'fg'     => $fg,
        'luma'   => $luma,
    ];
}

/**
 * 输出覆盖 style.css 的 <style> 片段（未配置主题色时返回空串）
 * 必须放在 style.css 之后、页面内容之前
 */
function theme_color_style($settings)
{
    $hex = normalize_theme_color($settings->get('theme_color', ''));
    if ($hex === null || $hex === '') {
        return '';
    }
    $p = theme_palette($hex);
    return '<style>:root{'
        . '--accent:' . $p['accent'] . ';'
        . '--accent-strong:' . $p['strong'] . ';'
        . '--accent-soft:' . $p['soft'] . ';'
        . '--accent-rgb:' . $p['rgb'] . ';'
        . '--accent-fg:' . $p['fg'] . ';'
        . '--accent-text:' . $p['text'] . ';'
        . '}</style>';
}

/**
 * 在 uploads 目录写入防护文件：禁止列目录、禁止访问非图片文件
 * （Nginx 下靠固定图片扩展名兜底，Apache 下由 .htaccess 拦截）
 */
function ensure_uploads_guard($dir)
{
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        $rule = "Options -Indexes\nRewriteEngine On\n"
            . 'RewriteRule !\.(png|jpe?g|gif|webp|ico|html)$ - [F,L]' . "\n";
        @file_put_contents($ht, $rule);
    }
    $idx = $dir . '/index.html';
    if (!file_exists($idx)) {
        @file_put_contents($idx, "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>-</title></head><body></body></html>\n");
    }
}

/**
 * 删除后台上传的 Logo 文件（恢复默认时用）
 */
function clear_site_logo_file()
{
    foreach (glob(dirname(__DIR__) . '/uploads/site-logo.*') as $f) {
        @unlink($f);
    }
}

/**
 * 处理后台上传的 Logo 图片
 * 严格校验：真实图片类型（getimagesize）+ 大小 ≤ 2MB + 固定文件名，禁止 SVG / 脚本文件
 * @return array {ok:bool, changed:bool, message:string}
 */
function handle_site_logo_upload($settings, $file)
{
    $none = ['ok' => true, 'changed' => false, 'message' => ''];
    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
        return $none;
    }
    if ((int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return $none;
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'changed' => false, 'message' => '上传失败（错误码 ' . (int)$file['error'] . '）'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 2 * 1024 * 1024) {
        return ['ok' => false, 'changed' => false, 'message' => 'Logo 图片不能超过 2MB'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'changed' => false, 'message' => '上传文件无效'];
    }

    $info = @getimagesize($tmp);
    // getimagesize() 索引 2 是 IMAGETYPE_* 常量，这里用数字避免老版本 PHP 缺少 WEBP 常量
    $types = [1 => 'gif', 2 => 'jpg', 3 => 'png', 18 => 'webp'];
    $type = is_array($info) ? (int)($info[2] ?? 0) : 0;
    if (!isset($types[$type])) {
        return ['ok' => false, 'changed' => false, 'message' => '仅支持 PNG / JPG / GIF / WebP 图片'];
    }

    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'changed' => false, 'message' => 'uploads 目录创建失败，请检查网站根目录写权限'];
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'changed' => false, 'message' => 'uploads 目录不可写，请检查权限'];
    }
    ensure_uploads_guard($dir);

    $ext = $types[$type];
    clear_site_logo_file(); // 清掉旧扩展名的文件，避免残留
    $target = $dir . '/site-logo.' . $ext;
    if (!@move_uploaded_file($tmp, $target)) {
        return ['ok' => false, 'changed' => false, 'message' => 'Logo 保存失败，请检查 uploads 目录权限'];
    }
    @chmod($target, 0644);
    $settings->set('site_logo', '/uploads/site-logo.' . $ext);
    return ['ok' => true, 'changed' => true, 'message' => 'Logo 已上传'];
}
