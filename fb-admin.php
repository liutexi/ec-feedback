<?php
/**
 * 管理员后台
 * 访问路径：/fb-admin
 * 功能：登录、查看全部反馈、修改状态、删除反馈
 */

require __DIR__ . '/inc/FeedbackStore.php';
require __DIR__ . '/inc/functions.php';

$config = require __DIR__ . '/config.php';
$store = new FeedbackStore($config['data_dir']);

session_start();

$ADMIN_KEY = 'feedback_admin_logged_in';

/* ---------- 登录 ---------- */
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if ($action === 'logout') {
    unset($_SESSION[$ADMIN_KEY]);
    session_destroy();
    header('Location: ' . rtrim($config['site_url'], '/') . '/fb-admin.php');
    exit;
}

if ($action === 'login') {
    $pwd = $_POST['password'] ?? '';
    if ($pwd === $config['admin_password']) {
        $_SESSION[$ADMIN_KEY] = true;
        header('Location: ' . rtrim($config['site_url'], '/') . '/fb-admin.php');
        exit;
    }
    $loginError = '密码错误';
}

/* ---------- 操作处理 ---------- */
if (!empty($_SESSION[$ADMIN_KEY])) {
    if ($action === 'update_status') {
        $no = $_POST['no'] ?? '';
        $status = $_POST['status'] ?? '';
        $allowed = ['pending', 'processing', 'resolved'];
        if ($no && in_array($status, $allowed, true)) {
            $store->update($no, ['status' => $status]);
        }
        header('Location: ' . rtrim($config['site_url'], '/') . '/fb-admin.php');
        exit;
    }
    if ($action === 'delete') {
        $no = $_GET['no'] ?? ($_POST['no'] ?? '');
        if ($no) {
            $store->delete($no);
        }
        header('Location: ' . rtrim($config['site_url'], '/') . '/fb-admin.php');
        exit;
    }
}

/* ---------- 未登录，渲染登录页 ---------- */
if (empty($_SESSION[$ADMIN_KEY])) {
    $err = isset($loginError) ? '<div class="error">' . e($loginError) . '</div>' : '';
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>管理员登录</title>'
        . '<link rel="stylesheet" href="/assets/style.css">'
        . '<style>
            .login-box{max-width:360px;margin:80px auto;background:#fff;border:1px solid var(--border);border-radius:12px;padding:28px;}
            .login-box h1{font-size:20px;margin-bottom:16px;}
            .login-box input{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:12px;font-size:14px;}
            .login-box button{width:100%;padding:10px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:15px;cursor:pointer;}
            .error{color:#e5484d;font-size:13px;margin-bottom:10px;}
          </style></head><body>'
        . '<div class="login-box"><h1>管理员登录</h1>' . $err
        . '<form method="post">'
        . '<input type="hidden" name="action" value="login">'
        . '<input type="password" name="password" placeholder="请输入管理员密码" autofocus>'
        . '<button type="submit">登录</button>'
        . '</form></div></body></html>';
    exit;
}

/* ---------- 后台主界面 ---------- */
$list = $store->all();
$site = rtrim($config['site_url'], '/');

// 统计
$total = count($list);
$pending = 0; $processing = 0; $resolved = 0;
foreach ($list as $it) {
    if ($it['status'] === 'pending') $pending++;
    elseif ($it['status'] === 'processing') $processing++;
    elseif ($it['status'] === 'resolved') $resolved++;
}

$statusOptions = [
    'pending' => '待跟进',
    'processing' => '处理中',
    'resolved' => '已解决',
];

function status_select($no, $current)
{
    global $statusOptions;
    $html = '<select class="status-select" data-no="' . e($no) . '">';
    foreach ($statusOptions as $val => $label) {
        $sel = ($val === $current) ? ' selected' : '';
        $html .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
    }
    $html .= '</select>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>反馈管理后台</title>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .admin-bar { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
  .admin-bar .logout { color:var(--accent); text-decoration:none; font-size:14px; }
  .stats { display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
  .stat { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:12px 18px; text-align:center; min-width:90px; }
  .stat .num { font-size:22px; font-weight:700; }
  .stat .lab { font-size:12px; color:var(--muted); }
  table { width:100%; border-collapse:collapse; background:var(--card); border:1px solid var(--border); border-radius:12px; overflow:hidden; }
  th, td { padding:10px 12px; text-align:left; font-size:13px; border-bottom:1px solid var(--border); vertical-align:top; }
  th { background:#fafbfc; color:var(--muted); font-weight:600; }
  tr:last-child td { border-bottom:none; }
  .cell-content { max-width:340px; word-break:break-word; }
  .status-select { border:1px solid var(--border); border-radius:6px; padding:4px 8px; font-size:13px; background:#fff; }
  .del-btn { color:#e5484d; text-decoration:none; font-size:13px; }
  .del-btn:hover { text-decoration:underline; }
  .type-badge { color:var(--accent); }
</style>
</head>
<body>
<div class="container" style="max-width:1100px;">
  <header class="admin-bar">
    <h1>反馈管理后台</h1>
    <a class="logout" href="?action=logout">退出登录</a>
  </header>

  <div class="stats">
    <div class="stat"><div class="num"><?php echo $total; ?></div><div class="lab">全部</div></div>
    <div class="stat"><div class="num"><?php echo $pending; ?></div><div class="lab">待跟进</div></div>
    <div class="stat"><div class="num"><?php echo $processing; ?></div><div class="lab">处理中</div></div>
    <div class="stat"><div class="num"><?php echo $resolved; ?></div><div class="lab">已解决</div></div>
  </div>

  <?php if (empty($list)): ?>
    <div class="empty">暂无反馈</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>编号</th><th>内容</th><th>类型</th><th>提交者</th><th>时间</th><th>状态</th><th>操作</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($list as $item): ?>
      <tr>
        <td><a href="<?php echo $site; ?>/feedback.php?no=<?php echo e($item['no']); ?>" target="_blank"><?php echo full_no($item['no']); ?></a></td>
        <td class="cell-content"><?php echo e($item['content']); ?></td>
        <td><span class="type-badge"><?php echo type_label($item['type']); ?></span></td>
        <td><?php echo e($item['senderNickname'] ?: '匿名'); ?><br><span style="color:var(--muted)"><?php echo e($item['senderId']); ?></span></td>
        <td><?php echo fmt_time($item['created_at']); ?></td>
        <td><?php echo status_select($item['no'], $item['status']); ?></td>
        <td><a class="del-btn" href="?action=delete&no=<?php echo e($item['no']); ?>" onclick="return confirm('确定删除该反馈？');">删除</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<script>
// 状态下拉即时更新
document.querySelectorAll('.status-select').forEach(function(sel){
  sel.addEventListener('change', function(){
    var no = sel.getAttribute('data-no');
    var status = sel.value;
    var form = document.createElement('form');
    form.method = 'post';
    form.style.display = 'none';
    var f1 = document.createElement('input'); f1.name='action'; f1.value='update_status';
    var f2 = document.createElement('input'); f2.name='no'; f2.value=no;
    var f3 = document.createElement('input'); f3.name='status'; f3.value=status;
    form.appendChild(f1); form.appendChild(f2); form.appendChild(f3);
    document.body.appendChild(form);
    form.submit();
  });
});
</script>
</body>
</html>
