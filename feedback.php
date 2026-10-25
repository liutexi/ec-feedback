<?php
/**
 * 前端反馈页面入口
 * 支持：列表 / 详情 / 点赞 / 修改反馈 / 删除反馈 / 评论（增删改） / 我的反馈
 */

require __DIR__ . '/inc/FeedbackStore.php';
require __DIR__ . '/inc/YunhuOAuth.php';
require __DIR__ . '/inc/functions.php';

$config = require __DIR__ . '/config.php';
$store = new FeedbackStore($config['data_dir']);
$oauth = new YunhuOAuth($config);

session_start();

$site = rtrim($config['site_url'], '/');

// 处理登录/退出动作
$qAction = $_GET['action'] ?? '';
if ($qAction === 'login') {
    $_SESSION['oauth_return_to'] = '/feedback.php';
    header('Location: ' . $oauth->buildAuthorizeUrl('/feedback.php'));
    exit;
}
if ($qAction === 'logout') {
    unset($_SESSION['yh_user']);
    header('Location: ' . $site . '/feedback.php');
    exit;
}

$currentUser = $_SESSION['yh_user'] ?? null;
$currentUserId = $currentUser['user_id'] ?? '';

// 解析请求路径
$requestPath = '/';
if (isset($_SERVER['REDIRECT_URL'])) {
    $requestPath = $_SERVER['REDIRECT_URL'];
} elseif (isset($_SERVER['REQUEST_URI'])) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
}
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
if ($base !== '' && $base !== '/' && strpos($requestPath, $base) === 0) {
    $requestPath = substr($requestPath, strlen($base));
}
$requestPath = '/' . ltrim($requestPath, '/');

// 确定编号与动作
$no = null;
$action = 'list';

if (isset($_GET['no']) && $_GET['no'] !== '') {
    $no = trim($_GET['no']);
    $action = isset($_GET['like']) ? 'like' : 'detail';
} elseif (preg_match('#^/feedback/([A-Za-z0-9\-]+)(?:/like)?/?$#', $requestPath, $m)) {
    $no = $m[1];
    $action = (strpos($requestPath, '/like') !== false) ? 'like' : 'detail';
} elseif (rtrim($requestPath, '/') === '/feedback') {
    $action = 'list';
}

/* ==================== 登录态校验辅助 ==================== */
function require_login($currentUser)
{
    if (empty($currentUser['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => '请先登录']);
        exit;
    }
}

/* ==================== 点赞处理 ==================== */
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

/* ==================== 我的反馈 ==================== */
if ($qAction === 'mine') {
    if (empty($currentUserId)) {
        header('Location: ' . $site . '/feedback.php?action=login');
        exit;
    }
    $list = $store->findByOwner($currentUserId);
    render_list($list, $config, $currentUser, true, 'latest', $store);
    exit;
}

/* ==================== 修改反馈（POST） ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'update_feedback') {
    require_login($currentUser);
    $no = $_POST['no'] ?? '';
    $content = trim($_POST['content'] ?? '');
    $item = $store->find($no);
    if (!$item) {
        echo json_encode(['ok' => false, 'error' => '反馈不存在']);
        exit;
    }
    // 校验归属：只有本人或管理员能改
    $isAdmin = !empty($currentUser['is_admin']);
    if (!$isAdmin && ($item['owner_user_id'] ?? '') !== (string)$currentUserId) {
        echo json_encode(['ok' => false, 'error' => '无权修改该反馈']);
        exit;
    }
    if ($content === '') {
        echo json_encode(['ok' => false, 'error' => '内容不能为空']);
        exit;
    }
    $operator = [
        'user_id'  => $currentUserId,
        'nickname' => $currentUser['nickname'] ?? '',
    ];
    $store->update($no, ['content' => $content], $operator);
    echo json_encode(['ok' => true]);
    exit;
}

/* ==================== 删除反馈（POST） ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'delete_feedback') {
    require_login($currentUser);
    $no = $_POST['no'] ?? '';
    $item = $store->find($no);
    if (!$item) {
        echo json_encode(['ok' => false, 'error' => '反馈不存在']);
        exit;
    }
    $isAdmin = !empty($currentUser['is_admin']);
    if (!$isAdmin && ($item['owner_user_id'] ?? '') !== (string)$currentUserId) {
        echo json_encode(['ok' => false, 'error' => '无权删除该反馈']);
        exit;
    }
    $store->delete($no);
    echo json_encode(['ok' => true]);
    exit;
}

/* ==================== 发表评论（POST） ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'add_comment') {
    require_login($currentUser);
    $no = $_POST['no'] ?? '';
    $content = trim($_POST['content'] ?? '');
    if ($content === '') {
        echo json_encode(['ok' => false, 'error' => '评论内容不能为空']);
        exit;
    }
    $comment = $store->addComment($no, [
        'user_id'    => $currentUserId,
        'nickname'   => $currentUser['nickname'] ?? '',
        'avatar_url' => $currentUser['avatar_url'] ?? '',
        'content'    => $content,
    ]);
    if (!$comment) {
        echo json_encode(['ok' => false, 'error' => '反馈不存在']);
        exit;
    }
    echo json_encode(['ok' => true, 'comment' => $comment]);
    exit;
}

/* ==================== 修改评论（POST） ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'update_comment') {
    require_login($currentUser);
    $cid = $_POST['cid'] ?? '';
    $content = trim($_POST['content'] ?? '');
    $comment = $store->findComment($cid);
    if (!$comment) {
        echo json_encode(['ok' => false, 'error' => '评论不存在']);
        exit;
    }
    if ($comment['user_id'] !== (string)$currentUserId) {
        echo json_encode(['ok' => false, 'error' => '无权修改该评论']);
        exit;
    }
    if ($content === '') {
        echo json_encode(['ok' => false, 'error' => '内容不能为空']);
        exit;
    }
    $store->updateComment($cid, $content);
    echo json_encode(['ok' => true]);
    exit;
}

/* ==================== 删除评论（POST） ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'delete_comment') {
    require_login($currentUser);
    $cid = $_POST['cid'] ?? '';
    $comment = $store->findComment($cid);
    if (!$comment) {
        echo json_encode(['ok' => false, 'error' => '评论不存在']);
        exit;
    }
    $isAdmin = !empty($currentUser['is_admin']);
    if (!$isAdmin && $comment['user_id'] !== (string)$currentUserId) {
        echo json_encode(['ok' => false, 'error' => '无权删除该评论']);
        exit;
    }
    $store->deleteComment($cid);
    echo json_encode(['ok' => true]);
    exit;
}

/* ==================== 详情处理 ==================== */
if ($action === 'detail' && $no) {
    $item = $store->find($no);
    if (!$item) {
        http_response_code(404);
        render_page('反馈不存在', '<div class="container"><p style="text-align:center;padding:80px 0;color:#999;">该反馈不存在或已被删除</p></div>');
        exit;
    }
    $comments = $store->getComments($no);
    $history = $store->getHistory($no);
    render_detail($item, $comments, $history, $config, $currentUser, $store);
    exit;
}

/* ==================== 列表处理 ==================== */
$sort = $_GET['sort'] ?? 'latest'; // latest=最新, hot=热门
if ($sort === 'hot') {
    $list = $store->allByHot();
} else {
    $list = $store->all();
}
render_list($list, $config, $currentUser, false, $sort, $store);
exit;

/* ==================== 渲染函数 ==================== */

function render_page($title, $bodyHtml)
{
    $t = e($title);
    // CSS 版本号（文件修改时间戳），避免浏览器缓存旧样式
    $cssVer = file_exists(__DIR__ . '/assets/style.css') ? filemtime(__DIR__ . '/assets/style.css') : time();
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . $t . '</title>'
        . '<link rel="stylesheet" href="/assets/style.css?v=' . $cssVer . '">'
        . '</head><body>' . $bodyHtml
        // 骨架屏切换：DOM 加载完成后隐藏骨架、显示真实列表
        . '<script>window.addEventListener("DOMContentLoaded",function(){'
        . 'var s=document.getElementById("skeleton-list");var l=document.getElementById("fb-list");'
        . 'if(s&&l){s.style.display="none";l.style.display="";}'
        . '});</script>'
        . '</body></html>';
}

/**
 * 生成顶部导航栏（品牌 + Tab + 用户区）
 * @param array|null $currentUser 当前登录用户
 * @param string $site 站点根地址
 * @param string $active 当前激活的 tab：feedback / mine
 */
function render_nav($currentUser, $site, $active = 'feedback')
{
    $isLogin = $currentUser && !empty($currentUser['user_id']);

    // 检测目录下的 logo 图片（logo.jpg / logo.png / logo.jpeg），没有则用自带 SVG
    $logoImg = '';
    foreach (['logo.png', 'logo.jpg', 'logo.jpeg'] as $f) {
        if (file_exists(__DIR__ . '/' . $f)) {
            $logoImg = $site . '/' . $f . '?v=' . filemtime(__DIR__ . '/' . $f);
            break;
        }
    }

    $html = '<nav class="top-nav">'
        . '<div class="top-nav-inner">';

    // 品牌
    $html .= '<a class="brand" href="' . $site . '/feedback.php">';
    if ($logoImg !== '') {
        $html .= '<img class="brand-logo-img" src="' . e($logoImg) . '" alt="AI 反馈">';
    } else {
        $html .= '<span class="brand-logo">' . svg_icon('logo') . '</span>';
    }
    $html .= '<span class="brand-name">AI 反馈</span>'
        . '</a>';

    // Tab
    $html .= '<div class="nav-tabs">'
        . '<a class="nav-tab' . ($active === 'feedback' ? ' active' : '') . '" href="' . $site . '/feedback.php">反馈</a>'
        . ($isLogin ? '<a class="nav-tab' . ($active === 'mine' ? ' active' : '') . '" href="' . $site . '/feedback.php?action=mine">我的反馈</a>' : '')
        . '</div>';

    // 右侧用户区
    $html .= '<div class="nav-user">';
    if ($isLogin) {
        $avatar = $currentUser['avatar_url'] ?? '';
        $nick = e($currentUser['nickname'] ?: '用户');
        if ($avatar !== '') {
            $proxyAvatar = avatar_url($avatar, $site);
            $html .= '<img class="nav-avatar" src="' . e($proxyAvatar) . '" referrerpolicy="no-referrer" alt="' . $nick . '" onerror="this.onerror=null;this.style.display=\'none\';this.nextElementSibling.style.display=\'inline-flex\';">';
            $html .= '<span class="nav-avatar avatar-placeholder" style="display:none;">' . e(mb_substr($nick, 0, 1)) . '</span>';
        } else {
            $html .= '<span class="nav-avatar avatar-placeholder">' . e(mb_substr($nick, 0, 1)) . '</span>';
        }
        $html .= '<span class="nav-nick">' . $nick . '</span>';
        $html .= '<a class="nav-link" href="' . $site . '/feedback.php?action=logout" title="退出登录">' . svg_icon('logout') . '</a>';
    } else {
        $html .= '<a class="nav-login" href="' . $site . '/feedback.php?action=login">' . svg_icon('login') . ' 登录</a>';
    }
    $html .= '</div>';

    $html .= '</div></nav>';
    return $html;
}

/**
 * 生成内联 SVG 图标
 */
function svg_icon($name)
{
    $icons = [
        'logo'   => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'login'  => '<svg class="ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>',
        'logout' => '<svg class="ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        'user'   => '<svg class="ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'edit'   => '<svg class="ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
        'trash'  => '<svg class="ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        'heart'  => '<svg class="ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
    ];
    return $icons[$name] ?? '';
}

function render_list(array $list, array $config, $currentUser = null, $isMine = false, $sort = 'latest', $store = null)
{
    $site = rtrim($config['site_url'], '/');
    $currentUserId = $currentUser['user_id'] ?? '';
    $html = '<div class="container">';
    $html .= render_nav($currentUser, $site, $isMine ? 'mine' : 'feedback');
    $title = $isMine ? '我的反馈' : 'AI 反馈';
    $html .= '<header class="page-head"><h1>' . $title . '</h1>'
        . '<div class="sub">共 ' . count($list) . ' 条反馈</div></header>';

    // 排序标签切换（非"我的反馈"页显示）
    if (!$isMine) {
        $html .= '<div class="sort-tabs">'
            . '<a class="sort-tab' . ($sort !== 'hot' ? ' active' : '') . '" href="' . $site . '/feedback.php?sort=latest">最新</a>'
            . '<a class="sort-tab' . ($sort === 'hot' ? ' active' : '') . '" href="' . $site . '/feedback.php?sort=hot">🔥 热门</a>'
            . '</div>';
    }

    if (empty($list)) {
        $html .= '<div class="empty">' . ($isMine ? '你还没有提交过反馈' : '暂无反馈') . '</div>';
    } else {
        // 骨架屏（初始显示，JS 加载完成后切换到真实列表）
        $html .= '<div class="skeleton-list" id="skeleton-list">';
        $skeletonCount = min(count($list), 4);
        for ($i = 0; $i < $skeletonCount; $i++) {
            $html .= '<div class="skeleton-item">'
                . '<div class="skeleton-line w40 short"></div>'
                . '<div class="skeleton-line w80"></div>'
                . '<div class="skeleton-line w60 short"></div>'
                . '</div>';
        }
        $html .= '</div>';

        $html .= '<ul class="fb-list" id="fb-list" style="display:none;">';
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
            $commentCount = $store ? $store->commentCount($item['no']) : 0;
            $heat = (int)($item['likes'] ?? 0) + $commentCount;
            $mineTag = (!$isMine && $currentUserId !== '' && ($item['owner_user_id'] ?? '') === $currentUserId)
                ? '<span class="mine-tag">我的</span>' : '';
            $html .= '<li class="fb-item">'
                . '<a class="fb-link" href="' . $site . '/feedback.php?no=' . $item['no'] . '">'
                . '<div class="fb-top"><span class="no">' . $no . '</span>'
                . '<span class="fb-right">' . $mineTag
                . '<span class="status st-' . e($item['status']) . '">' . $status . '</span></span></div>'
                . '<div class="fb-content">' . $content . '</div>'
                . '<div class="fb-meta"><span class="type">' . $type . '</span>'
                . '<span class="priority">' . $priority . '</span>'
                . '<span class="nick">' . $nick . '</span>'
                . '<span class="time">' . fmt_time($item['created_at']) . '</span>'
                . '<span class="fb-stats">'
                . '<span class="likes">' . svg_icon('heart') . '<span class="likes-num">' . (int)$item['likes'] . '</span></span>'
                . '<span class="comment-cnt">💬' . $commentCount . '</span>'
                . '</span></div>'
                . '</a></li>';
        }
        $html .= '</ul>';
    }

    $html .= '<footer class="page-foot">反馈由云湖机器人自动收集 · <a href="' . $site . '/fb-admin.php">管理后台</a></footer>';
    $html .= '</div>';

    render_page($title, $html);
}

function render_detail(array $item, array $comments, array $history, array $config, $currentUser = null, $store = null)
{
    $site = rtrim($config['site_url'], '/');
    $no = full_no($item['no']);
    $status = status_label($item['status']);
    $type = type_label($item['type']);
    $priority = priority_label($item['priority']);
    $nick = e($item['senderNickname'] ?: '匿名');
    $senderId = e($item['senderId']);
    $content = nl2br(e($item['content']));

    $currentUserId = $currentUser['user_id'] ?? '';
    $isAdmin = !empty($currentUser['is_admin']);
    $isOwner = ($item['owner_user_id'] ?? '') === $currentUserId;
    $canManage = ($isOwner || $isAdmin);

    $html = '<div class="container">';
    $html .= render_nav($currentUser, $site, 'feedback');
    $html .= '<header class="page-head"><a class="back" href="' . $site . '/feedback.php">← 返回列表</a>'
        . '<h1>反馈详情 · ' . $no . '</h1></header>';

    $html .= '<div class="detail-card">'
        . '<div class="detail-head">'
        . '<span class="status st-' . e($item['status']) . '">' . $status . '</span>'
        . '<span class="type">' . $type . '</span>'
        . '<span class="priority">' . $priority . '</span>'
        . '</div>'
        . '<div class="detail-info">反馈人：<b>' . $nick . '</b>'
        . '<span class="uid-chip">ID ' . $senderId . '</span>'
        . '<span class="sep">·</span>提交：' . fmt_time($item['created_at'])
        . '<span class="sep">·</span>更新：' . fmt_time($item['updated_at']) . '</div>'
        . '<div class="detail-content" id="feedback-content">' . $content . '</div>';

    // 操作按钮区
    $html .= '<div class="detail-actions">'
        . '<button class="like-btn" data-no="' . e($item['no']) . '">' . svg_icon('heart') . ' <span class="like-count">' . (int)$item['likes'] . '</span> 赞</button>';
    if ($canManage) {
        $html .= '<button class="act-btn" id="btn-edit">' . svg_icon('edit') . ' 修改</button>'
            . '<button class="act-btn danger" id="btn-delete">' . svg_icon('trash') . ' 删除</button>';
    }
    $html .= '</div>';

    // 编辑表单（隐藏）
    if ($canManage) {
        $html .= '<div class="edit-box" id="edit-box" style="display:none;">'
            . '<textarea id="edit-content" rows="4">' . e($item['content']) . '</textarea>'
            . '<div class="edit-actions">'
            . '<button class="act-btn primary" id="btn-save-edit">保存</button>'
            . '<button class="act-btn" id="btn-cancel-edit">取消</button>'
            . '</div></div>';
    }
    $html .= '</div>';

    // 修改历史
    if (!empty($history)) {
        $html .= '<div class="history-section">'
            . '<h3 class="comment-title">修改历史（' . count($history) . '）</h3>'
            . '<ul class="history-list">';
        foreach ($history as $h) {
            $hNick = e($h['nickname'] ?: '用户');
            $hTime = fmt_time($h['created_at']);
            $oldText = e(mb_substr($h['old_content'], 0, 60));
            $newText = e(mb_substr($h['new_content'], 0, 60));
            if (mb_strlen($h['old_content']) > 60) $oldText .= '…';
            if (mb_strlen($h['new_content']) > 60) $newText .= '…';
            $html .= '<li class="history-item">'
                . '<div class="history-head"><span class="history-nick">' . $hNick . '</span>'
                . '<span class="history-time">' . $hTime . '</span></div>'
                . '<div class="history-diff">'
                . '<div class="history-old"><span class="history-label">旧</span>' . $oldText . '</div>'
                . '<div class="history-new"><span class="history-label">新</span>' . $newText . '</div>'
                . '</div></li>';
        }
        $html .= '</ul></div>';
    }

    // 评论区
    $html .= '<div class="comment-section">'
        . '<h3 class="comment-title">评论（' . count($comments) . '）</h3>';

    // 评论列表
    if (empty($comments)) {
        $html .= '<div class="comment-empty">还没有评论，来抢沙发～</div>';
    } else {
        $html .= '<ul class="comment-list">';
        foreach ($comments as $c) {
            $cNick = e($c['nickname'] ?: '用户');
            $cAvatar = $c['avatar_url'] ?? '';
            $cContent = nl2br(e($c['content']));
            $cTime = fmt_time($c['created_at']);
            $isMyComment = ($c['user_id'] === $currentUserId);
            $html .= '<li class="comment-item" data-cid="' . e($c['id']) . '">';
            if ($cAvatar !== '') {
                $proxyAvatar = avatar_url($cAvatar, $site);
                $html .= '<img class="avatar" src="' . e($proxyAvatar) . '" referrerpolicy="no-referrer" alt="" onerror="this.onerror=null;this.parentNode.innerHTML=\'<span class=&quot;avatar avatar-placeholder&quot;>' . e(mb_substr($cNick, 0, 1)) . '</span>\';">';
            } else {
                $html .= '<span class="avatar avatar-placeholder">' . e(mb_substr($cNick, 0, 1)) . '</span>';
            }
            $html .= '<div class="comment-body">'
                . '<div class="comment-head"><span class="comment-nick">' . $cNick . '</span>'
                . '<span class="comment-time">' . $cTime . '</span></div>'
                . '<div class="comment-content">' . $cContent . '</div>';
            if ($isMyComment || $isAdmin) {
                $html .= '<div class="comment-ops">'
                    . '<a href="javascript:;" class="op-link" onclick="editComment(\'' . e($c['id']) . '\')">修改</a>'
                    . '<a href="javascript:;" class="op-link danger" onclick="deleteComment(\'' . e($c['id']) . '\')">删除</a>'
                    . '</div>';
            }
            $html .= '</div></li>';
        }
        $html .= '</ul>';
    }

    // 发评论（登录后可见）
    if (!empty($currentUserId)) {
        $html .= '<div class="comment-input">'
            . '<textarea id="comment-text" rows="3" placeholder="写下你的评论…"></textarea>'
            . '<button class="act-btn primary" id="btn-comment">发表评论</button>'
            . '</div>';
    } else {
        $html .= '<div class="comment-login-tip">'
            . '<a href="' . $site . '/feedback.php?action=login">登录</a> 后即可发表评论'
            . '</div>';
    }

    $html .= '</div></div>';

    // 前端 JS
    $html .= '<script>
    var SITE = "' . $site . '";
    var NO = "' . e($item['no']) . '";
    var loggedIn = ' . (empty($currentUserId) ? 'false' : 'true') . ';

    function postForm(op, data, cb){
        var fd = new FormData();
        fd.append("op", op);
        for (var k in data) fd.append(k, data[k]);
        fetch("' . $site . '/feedback.php", { method: "POST", body: fd })
            .then(function(r){ return r.json(); })
            .then(cb);
    }

    // 点赞
    var likeBtn = document.querySelector(".like-btn");
    if (likeBtn) {
        likeBtn.addEventListener("click", function(){
            fetch(SITE + "/feedback.php?no=" + NO + "&like=1")
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d.ok) { likeBtn.querySelector(".like-count").textContent = d.likes; likeBtn.classList.add("liked"); }
                });
        });
    }

    // 修改反馈
    var editBox = document.getElementById("edit-box");
    var btnEdit = document.getElementById("btn-edit");
    var btnCancelEdit = document.getElementById("btn-cancel-edit");
    var btnSaveEdit = document.getElementById("btn-save-edit");
    if (btnEdit) {
        btnEdit.addEventListener("click", function(){ editBox.style.display = "block"; });
        btnCancelEdit.addEventListener("click", function(){ editBox.style.display = "none"; });
        btnSaveEdit.addEventListener("click", function(){
            var content = document.getElementById("edit-content").value.trim();
            if (!content) { alert("内容不能为空"); return; }
            postForm("update_feedback", { no: NO, content: content }, function(d){
                if (d.ok) { location.reload(); }
                else { alert(d.error || "修改失败"); }
            });
        });
    }

    // 删除反馈
    var btnDelete = document.getElementById("btn-delete");
    if (btnDelete) {
        btnDelete.addEventListener("click", function(){
            if (!confirm("确定删除这条反馈吗？删除后不可恢复")) return;
            postForm("delete_feedback", { no: NO }, function(d){
                if (d.ok) { location.href = SITE + "/feedback.php"; }
                else { alert(d.error || "删除失败"); }
            });
        });
    }

    // 发表评论
    var btnComment = document.getElementById("btn-comment");
    if (btnComment) {
        btnComment.addEventListener("click", function(){
            var content = document.getElementById("comment-text").value.trim();
            if (!content) { alert("评论内容不能为空"); return; }
            postForm("add_comment", { no: NO, content: content }, function(d){
                if (d.ok) { location.reload(); }
                else { alert(d.error || "评论失败"); }
            });
        });
    }

    // 修改评论（内联）
    window.editComment = function(cid){
        var item = document.querySelector(".comment-item[data-cid=\"" + cid + "\"]");
        if (!item) return;
        var contentEl = item.querySelector(".comment-content");
        var old = contentEl.textContent;
        var newContent = prompt("修改评论：", old);
        if (newContent === null) return;
        newContent = newContent.trim();
        if (!newContent) { alert("内容不能为空"); return; }
        postForm("update_comment", { cid: cid, content: newContent }, function(d){
            if (d.ok) { location.reload(); }
            else { alert(d.error || "修改失败"); }
        });
    };

    // 删除评论
    window.deleteComment = function(cid){
        if (!confirm("确定删除这条评论吗？")) return;
        postForm("delete_comment", { cid: cid }, function(d){
            if (d.ok) { location.reload(); }
            else { alert(d.error || "删除失败"); }
        });
    };
    </script>';

    render_page('反馈详情 · ' . $no, $html);
}
