<?php
/**
 * 前端反馈页面入口
 * 路由：
 *   /feedback          -> 反馈列表页
 *   /feedback/{编号}   -> 反馈详情页
 *   /feedback/{编号}/like -> 点赞（AJAX）
 *
 * 通过 .htaccess 将 /feedback 重写到本文件（见 .htaccess）
 */

require __DIR__ . '/inc/FeedbackStore.php';
require __DIR__ . '/inc/functions.php';

$config = require __DIR__ . '/config.php';
$store = new FeedbackStore($config['data_dir']);

// 解析请求路径（兼容 Apache 的 .htaccess 重写与 Nginx 的 try_files 转发）
// Nginx try_files 转发后 REQUEST_URI 可能被改写，需优先取原始路径
$requestPath = '/';
if (isset($_SERVER['REDIRECT_URL'])) {
    // Nginx 内部重定向时保留的原始 URL
    $requestPath = $_SERVER['REDIRECT_URL'];
} elseif (isset($_SERVER['REQUEST_URI'])) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
}
// 去掉可能的前缀（例如子目录部署时）
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
if ($base !== '' && $base !== '/' && strpos($requestPath, $base) === 0) {
    $requestPath = substr($requestPath, strlen($base));
}
$requestPath = '/' . ltrim($requestPath, '/');

// 确定编号与动作
$no = null;
$action = 'list';

// 方式一：?no=编号 或 ?no=编号&like=1 参数形式（方案B，推荐，兼容 Nginx/Apache）
if (isset($_GET['no']) && $_GET['no'] !== '') {
    $no = trim($_GET['no']);
    $action = isset($_GET['like']) ? 'like' : 'detail';
}
// 方式二：伪静态路径 /feedback/xxx 或 /feedback/xxx/like（方案A，需配置重写）
elseif (preg_match('#^/feedback/([A-Za-z0-9\-]+)(?:/like)?/?$#', $requestPath, $m)) {
    $no = $m[1];
    $action = (strpos($requestPath, '/like') !== false) ? 'like' : 'detail';
} elseif (rtrim($requestPath, '/') === '/feedback') {
    $action = 'list';
}

// 点赞处理
if ($action === 'like' && $no) {
    header('Content-Type: application/json; charset=utf-8');
    $store->like($no);
    $item = $store->find($no);
    if ($item) {
        echo json_encode(['ok' => true, 'likes' => $item['likes']]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

// 详情处理
if ($action === 'detail' && $no) {
    $item = $store->find($no);
    if (!$item) {
        http_response_code(404);
        $title = '反馈不存在';
        $body = '<p style="text-align:center;padding:80px 0;color:#999;">该反馈不存在或已被删除</p>';
        render_page($title, $body);
        exit;
    }
    render_detail($item, $config);
    exit;
}

// 列表处理
$list = $store->all();
render_list($list, $config);
exit;

/* ------------------------- 渲染函数 ------------------------- */

function render_page($title, $bodyHtml)
{
    $t = e($title);
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . $t . '</title>'
        . '<link rel="stylesheet" href="/assets/style.css">'
        . '</head><body>' . $bodyHtml . '</body></html>';
}

function render_list(array $list, array $config)
{
    $site = rtrim($config['site_url'], '/');
    $html = '<div class="container">';
    $html .= '<header class="page-head"><h1>AI 反馈</h1>'
        . '<div class="sub">共 ' . count($list) . ' 条反馈</div></header>';

    if (empty($list)) {
        $html .= '<div class="empty">暂无反馈</div>';
    } else {
        $html .= '<ul class="fb-list">';
        foreach ($list as $item) {
            $no = full_no($item['no']);
            $status = status_label($item['status']);
            $type = type_label($item['type']);
            $priority = priority_label($item['priority']);
            $nick = e($item['senderNickname'] ?: '匿名');
            $content = e(mb_substr($item['content'], 0, 80));
            if (mb_strlen($item['content']) > 80) {
                $content .= '…';
            }
            $html .= '<li class="fb-item">'
                . '<a class="fb-link" href="' . $site . '/feedback.php?no=' . $item['no'] . '">'
                . '<div class="fb-top"><span class="no">' . $no . '</span>'
                . '<span class="status st-' . e($item['status']) . '">' . $status . '</span></div>'
                . '<div class="fb-content">' . $content . '</div>'
                . '<div class="fb-meta"><span class="type">' . $type . '</span>'
                . '<span class="priority">' . $priority . '</span>'
                . '<span class="nick">' . $nick . '</span>'
                . '<span class="time">' . fmt_time($item['created_at']) . '</span>'
                . '<span class="likes">♡' . (int)$item['likes'] . '</span></div>'
                . '</a></li>';
        }
        $html .= '</ul>';
    }

    $html .= '<footer class="page-foot">反馈由云湖机器人自动收集 · <a href="' . $site . '/fb-admin.php">管理后台</a></footer>';
    $html .= '</div>';

    render_page('AI 反馈', $html);
}

function render_detail(array $item, array $config)
{
    $site = rtrim($config['site_url'], '/');
    $no = full_no($item['no']);
    $status = status_label($item['status']);
    $type = type_label($item['type']);
    $priority = priority_label($item['priority']);
    $nick = e($item['senderNickname'] ?: '匿名');
    $senderId = e($item['senderId']);
    $content = nl2br(e($item['content']));

    $html = '<div class="container">';
    $html .= '<header class="page-head"><a class="back" href="' . $site . '/feedback.php">← 返回列表</a>'
        . '<h1>反馈详情 · ' . $no . '</h1></header>';

    $html .= '<div class="detail-card">'
        . '<div class="detail-head">'
        . '<span class="status st-' . e($item['status']) . '">' . $status . '</span>'
        . '<span class="type">' . $type . '</span>'
        . '<span class="priority">' . $priority . '</span>'
        . '</div>'
        . '<div class="detail-info">反馈人：<b>' . $nick . '</b>（' . $senderId . '）'
        . '<span class="sep">·</span>提交：' . fmt_time($item['created_at'])
        . '<span class="sep">·</span>更新：' . fmt_time($item['updated_at']) . '</div>'
        . '<div class="detail-content">' . $content . '</div>'
        . '<div class="detail-actions"><button class="like-btn" data-no="' . e($item['no']) . '">♡ <span class="like-count">' . (int)$item['likes'] . '</span> 赞</button></div>'
        . '</div>';

    $html .= '</div>';
    $html .= '<script>
        document.querySelector(".like-btn").addEventListener("click", function(){
            var btn = this;
            var no = btn.getAttribute("data-no");
            fetch("' . $site . '/feedback.php?no=" + no + "&like=1")
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d.ok) { btn.querySelector(".like-count").textContent = d.likes; btn.classList.add("liked"); }
                });
        });
    </script>';

    render_page('反馈详情 · ' . $no, $html);
}
