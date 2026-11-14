<?php
/**
 * 前端反馈页面入口
 * 支持：列表 / 详情 / 点赞 / 修改反馈 / 删除反馈 / 评论（增删改） / 我的反馈
 */

require __DIR__ . '/inc/FeedbackStore.php';
require __DIR__ . '/inc/store.php';
require __DIR__ . '/inc/Settings.php';
require __DIR__ . '/inc/YunhuOAuth.php';
require __DIR__ . '/inc/functions.php';

$config = require __DIR__ . '/config.php';
$settings = new Settings($config['data_dir']);
$store = create_store($config, $settings);
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

// 短编号解析：若编号不含 "-" 且为纯数字，且后台已启用短编号，则按短编号查找
if ($no !== null && strpos($no, '-') === false && ctype_digit($no) && $settings->shortNoEnabled()) {
    $found = $store->findByShortNo($no);
    if ($found) {
        $no = $found['no'];
    }
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
    // 需登录才能点赞
    if (empty($currentUserId)) {
        echo json_encode(['ok' => false, 'error' => '请先登录', 'login' => true]);
        exit;
    }
    $result = $store->toggleLike($no, $currentUserId);
    if ($result) {
        echo json_encode(['ok' => true, 'liked' => $result['liked'], 'likes' => $result['likes']]);
    } else {
        echo json_encode(['ok' => false, 'error' => '反馈不存在']);
    }
    exit;
}

/* ==================== 我的（个人中心） ==================== */
if ($qAction === 'mine') {
    if (empty($currentUserId)) {
        header('Location: ' . $site . '/feedback.php?action=login');
        exit;
    }
    $stats = $store->statsByOwner($currentUserId);
    $mineList = $store->findByOwner($currentUserId);
    $likedList = $store->findLikedBy($currentUserId);
    render_profile($currentUser, $stats, $mineList, $likedList, $config, $store);
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
        render_page('反馈不存在', render_nav($currentUser, $site, '')
            . '<div class="container" style="text-align:center;padding:80px 20px;">'
            . '<div style="font-size:52px;margin-bottom:16px;">🔍</div>'
            . '<h2 style="margin:0 0 8px;">该反馈不存在或已被删除</h2>'
            . '<p style="color:var(--muted);margin:0 0 24px;">可能是编号有误，或这条反馈已被移除</p>'
            . '<a class="act-btn primary" href="' . $site . '/feedback.php">返回反馈列表</a>'
            . '</div>');
        exit;
    }
    $comments = $store->getComments($no);
    $history = $store->getHistory($no);
    $processLog = $store->getProcessLog($no);
    render_detail($item, $comments, $history, $processLog, $config, $currentUser, $store);
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
    global $settings;
    $t = e($title);
    // CSS 版本号（文件修改时间戳），避免浏览器缓存旧样式
    $cssVer = file_exists(__DIR__ . '/assets/style.css') ? filemtime(__DIR__ . '/assets/style.css') : time();
    // 后台配置的 Logo 同时用作浏览器标签图标
    $logo = site_logo_url($settings);
    $favicon = $logo !== '' ? '<link rel="icon" href="' . e($logo) . '">' : '';
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . $t . '</title>'
        . $favicon
        . '<link rel="stylesheet" href="/assets/style.css?v=' . $cssVer . '">'
        . theme_color_style($settings)
        . '</head><body>' . $bodyHtml
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
    global $settings;
    $isLogin = $currentUser && !empty($currentUser['user_id']);

    // Logo 与站点名来自后台「站点设置」；没有配置时自动检测根目录 logo.*，再没有就用内置 SVG
    $logoImg = site_logo_url($settings);
    $brandName = e(site_brand_name($settings));

    $html = '<nav class="top-nav">'
        . '<div class="top-nav-inner">';

    // 品牌
    $html .= '<a class="brand" href="' . $site . '/feedback.php">';
    if ($logoImg !== '') {
        $html .= '<img class="brand-logo-img" src="' . e($logoImg) . '" alt="' . $brandName . '">';
    } else {
        $html .= '<span class="brand-logo">' . svg_icon('logo') . '</span>';
    }
    $html .= '<span class="brand-name">' . $brandName . '</span>'
        . '</a>';

    // Tab
    $html .= '<div class="nav-tabs">'
        . '<a class="nav-tab' . ($active === 'feedback' ? ' active' : '') . '" href="' . $site . '/feedback.php">反馈</a>'
        . '</div>';

    // 右侧用户区
    $html .= '<div class="nav-user">';
    if ($isLogin) {
        $avatar = $currentUser['avatar_url'] ?? '';
        $nick = e($currentUser['nickname'] ?: '用户');
        $isAdmin = !empty($currentUser['is_admin']);
        // 头像 + 昵称整体点击进入「我的」
        $html .= '<a class="nav-me' . ($active === 'mine' ? ' active' : '') . '" href="' . $site . '/feedback.php?action=mine" title="我的">';
        if ($avatar !== '') {
            $proxyAvatar = avatar_url($avatar, $site);
            $html .= '<img class="nav-avatar" src="' . e($proxyAvatar) . '" referrerpolicy="no-referrer" alt="' . $nick . '" onerror="this.onerror=null;this.style.display=\'none\';this.nextElementSibling.style.display=\'inline-flex\';">';
            $html .= '<span class="nav-avatar avatar-placeholder" style="display:none;">' . e(mb_substr($nick, 0, 1)) . '</span>';
        } else {
            $html .= '<span class="nav-avatar avatar-placeholder">' . e(mb_substr($nick, 0, 1)) . '</span>';
        }
        $html .= '<span class="nav-nick">' . $nick . '</span>';
        $html .= '</a>';
        // 管理员专属：管理后台入口
        if ($isAdmin) {
            $html .= '<a class="nav-admin" href="' . $site . '/fb-admin.php" title="管理后台">' . svg_icon('admin') . '</a>';
        }
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
        'admin'  => '<svg class="ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"/><path d="M9 12l2 2 4-4"/></svg>',
        // 处理记录节点图标
        'p-create' => '<svg class="ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'p-reply'  => '<svg class="ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'p-status' => '<svg class="ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
        'p-edit'   => '<svg class="ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
        'fire'     => '<svg class="ico" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2c1 3 4 4.5 4 8a4 4 0 0 1-8 0c0-1 .2-1.8.6-2.5C8 9 8.5 10.5 10 11c-.5-2.5.5-6.5 2-9z"/><path d="M12 22a5 5 0 0 0 5-5c0-2-1-3.5-2-4.5 0 2-1 3-3 4"/></svg>',
    ];
    return $icons[$name] ?? '';
}

/**
 * 渲染反馈卡片列表（供列表页和个人中心复用）
 */
function render_fb_items(array $list, $site, $currentUserId, $store, $isMine = false)
{
    $html = '';
    if (empty($list)) {
        return '<div class="empty">' . ($isMine ? '你还没有提交过反馈' : '暂无反馈') . '</div>';
    }
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
        $commentCount = $store ? $store->commentCount($item['no']) : 0;
        $mineTag = (!$isMine && $currentUserId !== '' && ($item['owner_user_id'] ?? '') === $currentUserId)
            ? '<span class="mine-tag">我的</span>' : '';
        $html .= '<li class="fb-item">'
            . '<a class="fb-link" href="' . $site . '/feedback/' . $item['no'] . '">'
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
    return $html;
}

/**
 * 个人中心页
 */
function render_profile($currentUser, array $stats, array $mineList, array $likedList, array $config, $store = null)
{
    $site = rtrim($config['site_url'], '/');
    $currentUserId = $currentUser['user_id'] ?? '';
    $nick = e($currentUser['nickname'] ?: '用户');
    $uid = e($currentUser['user_id'] ?? '');

    $html = render_nav($currentUser, $site, 'mine');
    $html .= '<div class="container">';

    // 个人信息卡
    $html .= '<div class="profile-card">';
    $html .= '<div class="profile-head">';
    $avatar = $currentUser['avatar_url'] ?? '';
    if ($avatar !== '') {
        $proxyAvatar = avatar_url($avatar, $site);
        $html .= '<img class="profile-avatar" src="' . e($proxyAvatar) . '" referrerpolicy="no-referrer" alt="' . $nick . '" onerror="this.onerror=null;this.style.display=\'none\';this.nextElementSibling.style.display=\'inline-flex\';">';
        $html .= '<span class="profile-avatar avatar-placeholder" style="display:none;">' . e(mb_substr($nick, 0, 1)) . '</span>';
    } else {
        $html .= '<span class="profile-avatar avatar-placeholder">' . e(mb_substr($nick, 0, 1)) . '</span>';
    }
    $html .= '<div class="profile-info">'
        . '<div class="profile-nick">' . $nick . '</div>'
        . '<div class="profile-uid">ID: ' . $uid . '</div>'
        . '</div>';
    // 管理员专属：进入管理后台
    if (!empty($currentUser['is_admin'])) {
        $html .= '<a class="profile-admin-btn" href="' . $site . '/fb-admin.php">' . svg_icon('admin') . ' 管理后台</a>';
    }
    $html .= '</div>';

    // 统计
    $html .= '<div class="profile-stats">'
        . '<div class="pstat"><div class="pstat-num">' . (int)$stats['total'] . '</div><div class="pstat-lab">提交反馈</div></div>'
        . '<div class="pstat"><div class="pstat-num">' . (int)$stats['likes'] . '</div><div class="pstat-lab">获得赞</div></div>'
        . '<div class="pstat"><div class="pstat-num">' . (int)$stats['comments'] . '</div><div class="pstat-lab">我的评论</div></div>'
        . '<div class="pstat"><div class="pstat-num">' . (int)$stats['resolved'] . '</div><div class="pstat-lab">已解决</div></div>'
        . '</div>';
    $html .= '</div>';

    // 分段切换（我提交的 / 我赞过的）
    $html .= '<div class="profile-tabs">'
        . '<button class="profile-tab active" data-panel="mine">我提交的 <span class="tab-count">' . count($mineList) . '</span></button>'
        . '<button class="profile-tab" data-panel="liked">我赞过的 <span class="tab-count">' . count($likedList) . '</span></button>'
        . '</div>';

    // 我提交的
    $html .= '<div class="profile-panel" id="panel-mine">'
        . render_fb_items($mineList, $site, $currentUserId, $store, true)
        . '</div>';
    // 我赞过的
    $html .= '<div class="profile-panel" id="panel-liked" style="display:none;">'
        . (empty($likedList) ? '<div class="empty">你还没有赞过任何反馈</div>' : render_fb_items($likedList, $site, $currentUserId, $store, false))
        . '</div>';

    $html .= '<footer class="page-foot">反馈由云湖机器人自动收集' . (!empty($currentUser['is_admin']) ? ' · <a href="' . $site . '/fb-admin.php">管理后台</a>' : '') . '</footer>';
    $html .= '</div>';

    // 面板切换脚本
    $html .= '<script>'
        . 'document.querySelectorAll(".profile-tab").forEach(function(t){'
        . 't.addEventListener("click",function(){'
        . 'document.querySelectorAll(".profile-tab").forEach(function(x){x.classList.remove("active");});'
        . 't.classList.add("active");'
        . 'document.querySelectorAll(".profile-panel").forEach(function(p){p.style.display="none";});'
        . 'document.getElementById("panel-"+t.getAttribute("data-panel")).style.display="";'
        . '});});'
        . '</script>';

    render_page('我的', $html);
}

function render_list(array $list, array $config, $currentUser = null, $isMine = false, $sort = 'latest', $store = null)
{
    global $settings;
    $site = rtrim($config['site_url'], '/');
    $currentUserId = $currentUser['user_id'] ?? '';
    $html = render_nav($currentUser, $site, $isMine ? 'mine' : 'feedback');
    $html .= '<div class="container">';
    $title = $isMine ? '我的反馈' : site_brand_name($settings);
    $html .= '<header class="page-head"><h1>' . e($title) . '</h1>'
        . '<div class="sub">共 ' . count($list) . ' 条反馈</div></header>';

    // 排序标签切换（非"我的反馈"页显示）
    if (!$isMine) {
        $html .= '<div class="sort-tabs">'
            . '<a class="sort-tab' . ($sort !== 'hot' ? ' active' : '') . '" data-sort="latest" href="' . $site . '/feedback.php?sort=latest">最新</a>'
            . '<a class="sort-tab' . ($sort === 'hot' ? ' active' : '') . '" data-sort="hot" href="' . $site . '/feedback.php?sort=hot">' . svg_icon('fire') . ' 热门</a>'
            . '</div>';
    }

    if (empty($list)) {
        $html .= '<div class="empty" id="fb-list-empty">' . ($isMine ? '你还没有提交过反馈' : '暂无反馈') . '</div>';
    } else {
        $html .= '<div id="fb-list">'
            . render_fb_items($list, $site, $currentUserId, $store, $isMine)
            . '</div>';
    }

    $html .= '<footer class="page-foot">反馈由云湖机器人自动收集' . (!empty($currentUser['is_admin']) ? ' · <a href="' . $site . '/fb-admin.php">管理后台</a>' : '') . '</footer>';
    $html .= '</div>';

    // 排序标签无刷新切换
    if (!$isMine) {
        $html .= '<script>
        document.querySelectorAll(".sort-tab").forEach(function(tab){
            tab.addEventListener("click", function(e){
                e.preventDefault();
                if (tab.classList.contains("active")) return;
                var sort = tab.getAttribute("data-sort");
                var listBox = document.getElementById("fb-list");
                var emptyBox = document.getElementById("fb-list-empty");
                if (listBox) listBox.style.opacity = "0.4";
                fetch("' . $site . '/feedback.php?sort=" + sort + "&partial=1")
                    .then(function(r){ return r.text(); })
                    .then(function(html){
                        // 用临时容器解析返回的完整页面，取出 #fb-list
                        var tmp = document.createElement("div");
                        tmp.innerHTML = html;
                        var newList = tmp.querySelector("#fb-list");
                        var newEmpty = tmp.querySelector("#fb-list-empty");
                        if (listBox) listBox.remove();
                        if (emptyBox) emptyBox.remove();
                        var anchor = document.querySelector(".sort-tabs");
                        if (newList) {
                            newList.style.animation = "item-in .25s ease-out";
                            anchor.parentNode.insertBefore(newList, anchor.nextSibling);
                        } else if (newEmpty) {
                            anchor.parentNode.insertBefore(newEmpty, anchor.nextSibling);
                        }
                        document.querySelectorAll(".sort-tab").forEach(function(x){ x.classList.remove("active"); });
                        tab.classList.add("active");
                        history.replaceState(null, "", "' . $site . '/feedback.php?sort=" + sort);
                    });
            });
        });
        </script>';
    }

    render_page($title, $html);
}

function render_detail(array $item, array $comments, array $history, array $processLog, array $config, $currentUser = null, $store = null)
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

    $html = render_nav($currentUser, $site, 'feedback');
    $html .= '<div class="container">';
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
    $liked = $store ? $store->hasLiked($item['no'], $currentUserId) : false;
    $html .= '<div class="detail-actions">'
        . '<button class="like-btn' . ($liked ? ' liked' : '') . '" data-no="' . e($item['no']) . '">' . svg_icon('heart') . ' <span class="like-count">' . (int)$item['likes'] . '</span> <span class="like-text">' . ($liked ? '已赞' : '赞') . '</span></button>';
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

    // 处理记录时间线（工单风格）
    if (!empty($processLog)) {
        $html .= '<div class="process-section">'
            . '<h3 class="comment-title">处理记录</h3>'
            . '<div class="process-timeline">';
        foreach ($processLog as $p) {
            $act = $p['action'] ?? '';
            $op = $p['operator'] ?? [];
            $opUserId = $op['user_id'] ?? '';
            $opNick = $op['nickname'] ?? '';
            $pTime = fmt_time($p['created_at'] ?? 0);
            $detail = $p['detail'] ?? [];

            // 节点：动作名 + 操作人
            $nodeIconName = 'p-create'; $nodeCls = 'p-submit';
            $actionName = '创建';
            $operatorText = '系统';

            if ($act === 'submit') {
                // 创建节点：显示"系统"+提交人ID
                $nodeIconName = 'p-create'; $nodeCls = 'p-submit';
                $actionName = '创建';
                $operatorText = '系统';
                if ($opUserId !== '') {
                    $operatorText .= '（' . e($opUserId) . '）';
                }
            } elseif ($act === 'reply') {
                $nodeIconName = 'p-reply'; $nodeCls = 'p-reply';
                $actionName = '回复';
                $operatorText = '管理员 ' . ($opNick !== '' ? e($opNick) : 'admin');
            } elseif ($act === 'status') {
                $nodeIconName = 'p-status'; $nodeCls = 'p-status';
                $to = $detail['to'] ?? '';
                $actionName = status_label($to);  // 直接用目标状态名，如"已加入开发计划"
                $operatorText = '管理员 ' . ($opNick !== '' ? e($opNick) : 'admin');
            } elseif ($act === 'edit') {
                $nodeIconName = 'p-edit'; $nodeCls = 'p-edit';
                $actionName = '内容修改';
                $operatorText = $opNick !== '' ? e($opNick) : '用户';
            }

            $html .= '<div class="process-item ' . $nodeCls . '">'
                . '<div class="process-dot">' . svg_icon($nodeIconName) . '</div>'
                . '<div class="process-body">'
                . '<div class="process-head">'
                . '<span class="process-action">' . $actionName . '</span>'
                . '<span class="process-op">' . $operatorText . '</span>'
                . '<span class="process-time">' . $pTime . '</span>'
                . '</div>';

            // 详情内容
            if ($act === 'submit') {
                // 创建：显示提交内容摘要
                $html .= '<div class="process-desc">' . nl2br(e(mb_substr($detail['content'] ?? '', 0, 120))) . '</div>';
            } elseif ($act === 'status') {
                $from = status_label($detail['from'] ?? '');
                $to = status_label($detail['to'] ?? '');
                $html .= '<div class="process-desc">状态由「' . $from . '」变更为「' . $to . '」</div>';
            } elseif ($act === 'reply') {
                $sendTo = ($detail['send_to'] ?? '') === 'group' ? '群聊' : (($detail['send_to'] ?? '') === 'both' ? '私信+群聊' : '私信');
                $html .= '<div class="process-desc">' . nl2br(e(mb_substr($detail['content'] ?? '', 0, 200))) . '</div>';
            } elseif ($act === 'edit') {
                $html .= '<div class="process-desc process-diff">'
                    . '<div class="proc-old">旧：' . nl2br(e(mb_substr($detail['old'] ?? '', 0, 80))) . '</div>'
                    . '<div class="proc-new">新：' . nl2br(e(mb_substr($detail['new'] ?? '', 0, 80))) . '</div>'
                    . '</div>';
            }

            $html .= '</div></div>';
        }
        $html .= '</div></div>';
    }
    // 兜底：旧数据没有 process_log，但有 history 时，仍展示修改历史
    elseif (!empty($history)) {
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
        $html .= '<div class="comment-empty" id="comment-empty">还没有评论，来抢沙发～</div>';
    } else {
        $html .= '<ul class="comment-list" id="comment-list">';
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
    $html .= '<script>window.__SITE__=' . json_encode($site) . ';window.__NO__=' . json_encode($item['no']) . ';window.__LOGGED__=' . (empty($currentUserId) ? 'false' : 'true') . ';</script>';
    $html .= '<script>';
    $html .= <<<JS
    var SITE = window.__SITE__;
    var NO = window.__NO__;
    var loggedIn = window.__LOGGED__;

    function postForm(op, data, cb){
        var fd = new FormData();
        fd.append("op", op);
        for (var k in data) fd.append(k, data[k]);
        fetch(SITE + "/feedback.php", { method: "POST", body: fd })
            .then(function(r){ return r.json(); })
            .then(cb);
    }

    // 点赞（toggle，需登录）
    var likeBtn = document.querySelector(".like-btn");
    if (likeBtn) {
        likeBtn.addEventListener("click", function(){
            fetch(SITE + "/feedback.php?no=" + NO + "&like=1")
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d.ok) {
                        likeBtn.querySelector(".like-count").textContent = d.likes;
                        if (d.liked) {
                            likeBtn.classList.add("liked");
                            likeBtn.querySelector(".like-text").textContent = "已赞";
                        } else {
                            likeBtn.classList.remove("liked");
                            likeBtn.querySelector(".like-text").textContent = "赞";
                        }
                    } else if (d.login) {
                        location.href = SITE + "/feedback.php?action=login";
                    } else {
                        alert(d.error || "操作失败");
                    }
                });
        });
    }

    // 修改反馈（局部更新，不刷新页面）
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
                if (d.ok) {
                    var target = document.getElementById("feedback-content");
                    if (target) target.innerHTML = escHtml(content).replace(/\\n/g, "<br>");
                    editBox.style.display = "none";
                    toast("已保存");
                } else { alert(d.error || "修改失败"); }
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

    // 发表评论（局部追加，不刷新页面）
    var btnComment = document.getElementById("btn-comment");
    if (btnComment) {
        btnComment.addEventListener("click", function(){
            var input = document.getElementById("comment-text");
            var content = input.value.trim();
            if (!content) { alert("评论内容不能为空"); return; }
            btnComment.disabled = true;
            postForm("add_comment", { no: NO, content: content }, function(d){
                btnComment.disabled = false;
                if (d.ok) {
                    input.value = "";
                    appendComment(d.comment);
                    toast("评论成功");
                } else { alert(d.error || "评论失败"); }
            });
        });
    }

    function escHtml(s){
        return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");
    }

    // 追加一条评论到列表
    function appendComment(c){
        var ul = document.getElementById("comment-list");
        var emptyTip = document.getElementById("comment-empty");
        if (emptyTip) emptyTip.remove();
        if (!ul) {
            ul = document.createElement("ul");
            ul.className = "comment-list";
            ul.id = "comment-list";
            var section = document.querySelector(".comment-section");
            var title = section.querySelector(".comment-title");
            title.parentNode.insertBefore(ul, title.nextSibling);
        }
        var nick = c.nickname || "用户";
        var li = document.createElement("li");
        li.className = "comment-item";
        li.setAttribute("data-cid", c.id);
        var avatarHtml = c.avatar_url
            ? '<img class="avatar" src="' + SITE + '/avatar.php?url=' + encodeURIComponent(c.avatar_url) + '" referrerpolicy="no-referrer" alt="">'
            : '<span class="avatar avatar-placeholder">' + escHtml(nick.charAt(0)) + '</span>';
        li.innerHTML = avatarHtml +
            '<div class="comment-body">' +
            '<div class="comment-head"><span class="comment-nick">' + escHtml(nick) + '</span>' +
            '<span class="comment-time">刚刚</span></div>' +
            '<div class="comment-content">' + escHtml(c.content).replace(/\\n/g, "<br>") + '</div>' +
            '<div class="comment-ops">' +
            '<a href="javascript:;" class="op-link" onclick="editComment(\'' + c.id + '\')">修改</a>' +
            '<a href="javascript:;" class="op-link danger" onclick="deleteComment(\'' + c.id + '\')">删除</a>' +
            '</div></div>';
        ul.appendChild(li);
        var title = document.querySelector(".comment-title");
        if (title) {
            var m = title.textContent.match(/评论（(\\d+)）/);
            if (m) title.textContent = "评论（" + (parseInt(m[1],10) + 1) + "）";
        }
        li.scrollIntoView({behavior:"smooth", block:"center"});
    }

    // 轻提示
    function toast(msg){
        var t = document.createElement("div");
        t.className = "toast";
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function(){ t.classList.add("show"); }, 10);
        setTimeout(function(){ t.classList.remove("show"); setTimeout(function(){ t.remove(); }, 250); }, 1600);
    }

    // 修改评论（局部更新）
    window.editComment = function(cid){
        var item = document.querySelector(".comment-item[data-cid='" + cid + "']");
        if (!item) return;
        var contentEl = item.querySelector(".comment-content");
        var old = contentEl.textContent;
        var newContent = prompt("修改评论：", old);
        if (newContent === null) return;
        newContent = newContent.trim();
        if (!newContent) { alert("内容不能为空"); return; }
        postForm("update_comment", { cid: cid, content: newContent }, function(d){
            if (d.ok) {
                contentEl.innerHTML = escHtml(newContent).replace(/\\n/g, "<br>");
                toast("已保存");
            } else { alert(d.error || "修改失败"); }
        });
    };

    // 删除评论（局部移除）
    window.deleteComment = function(cid){
        if (!confirm("确定删除这条评论吗？")) return;
        postForm("delete_comment", { cid: cid }, function(d){
            if (d.ok) {
                var item = document.querySelector(".comment-item[data-cid='" + cid + "']");
                if (item) item.remove();
                var title = document.querySelector(".comment-title");
                if (title) {
                    var m = title.textContent.match(/评论（(\\d+)）/);
                    if (m) title.textContent = "评论（" + Math.max(0, parseInt(m[1],10) - 1) + "）";
                }
                toast("已删除");
            } else { alert(d.error || "删除失败"); }
        });
    };
JS;
    $html .= '</script>';

    render_page('反馈详情 · ' . $no, $html);
}
