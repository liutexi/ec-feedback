<?php
/**
 * 管理员后台
 * 访问路径：/fb-admin.php
 * 功能：云湖 OAuth2 登录（白名单校验）、查看全部反馈、修改状态、删除反馈
 */

require __DIR__ . '/inc/FeedbackStore.php';
require __DIR__ . '/inc/YunhuOAuth.php';
require __DIR__ . '/inc/YunhuBot.php';
require __DIR__ . '/inc/functions.php';

$config = require __DIR__ . '/config.php';
$store = new FeedbackStore($config['data_dir']);
$oauth = new YunhuOAuth($config);
$bot = new YunhuBot($config['bot_token']);

session_start();

$ADMIN_KEY = 'feedback_admin_logged_in';

/* ---------- 退出 ---------- */
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if ($action === 'logout') {
    unset($_SESSION[$ADMIN_KEY]);
    unset($_SESSION['yh_user']);
    session_destroy();
    header('Location: ' . rtrim($config['site_url'], '/') . '/fb-admin.php');
    exit;
}

/* ---------- 云湖登录发起 ---------- */
if ($action === 'oauth_login') {
    $_SESSION['oauth_return_to'] = '/fb-admin.php';
    $url = $oauth->buildAuthorizeUrl('/fb-admin.php');
    header('Location: ' . $url);
    exit;
}

/* ---------- 密码登录（兜底） ---------- */
if ($action === 'login') {
    $pwd = $_POST['password'] ?? '';
    if ($pwd === $config['admin_password']) {
        $_SESSION[$ADMIN_KEY] = true;
        header('Location: ' . rtrim($config['site_url'], '/') . '/fb-admin.php');
        exit;
    }
    $loginError = '密码错误';
}

/* ---------- 判断是否已登录（云湖白名单 或 密码） ---------- */
$isAdmin = false;
if (!empty($_SESSION[$ADMIN_KEY])) {
    $isAdmin = true; // 密码登录
} elseif (!empty($_SESSION['yh_user']['is_admin'])) {
    $isAdmin = true; // 云湖白名单登录
}

/* ---------- 操作处理 ---------- */
if ($isAdmin) {
    if ($action === 'update_status') {
        $no = $_POST['no'] ?? '';
        $status = $_POST['status'] ?? '';
        $allowed = ['pending', 'replied', 'resolved', 'planned', 'closed'];
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
    if ($action === 'batch_delete') {
        $nos = $_POST['nos'] ?? [];
        if (is_array($nos) && !empty($nos)) {
            foreach ($nos as $no) {
                $store->delete($no);
            }
        }
        header('Location: ' . rtrim($config['site_url'], '/') . '/fb-admin.php');
        exit;
    }
    if ($action === 'reply') {
        $no = $_POST['no'] ?? '';
        $replyContent = trim($_POST['reply_content'] ?? '');
        $sendTo = $_POST['send_to'] ?? 'user'; // user / group / both
        $item = $store->find($no);
        if (!$item || $replyContent === '') {
            header('Location: ' . rtrim($config['site_url'], '/') . '/fb-admin.php?reply_error=' . urlencode('反馈不存在或回复内容为空'));
            exit;
        }

        $senderId = $item['senderId'] ?? '';
        $chatId = $item['chatId'] ?? '';
        $chatType = $item['chatType'] ?? 'bot';
        $noFull = full_no($item['no']);
        $senderNickname = $item['senderNickname'] ?? '';
        $originContent = $item['content'] ?? '';
        $statusLabel = status_label($item['status'] ?? 'pending');

        // 构造 Markdown 回复模板
        // ## 💬 反馈 #FB-编号 有新回复
        // **反馈人**：昵称
        // **状态**：已回复
        // > 原反馈内容
        // 回复内容
        $replyMarkdown = "## 💬 反馈 {$noFull} 有新回复\n";
        if ($senderNickname !== '') {
            $replyMarkdown .= "**反馈人**：{$senderNickname}\n";
        }
        $replyMarkdown .= "**状态**：{$statusLabel}\n";
        if ($originContent !== '') {
            $replyMarkdown .= "> {$originContent}\n";
        }
        $replyMarkdown .= $replyContent;

        $sent = [];
        $errors = [];

        // 私信提交者（Markdown）
        if ($sendTo === 'user' || $sendTo === 'both') {
            if ($senderId !== '') {
                $r = $bot->sendMarkdown($senderId, 'user', $replyMarkdown);
                if (($r['code'] ?? 0) == 1) {
                    $sent[] = '私信';
                } else {
                    $errors[] = '私信失败: ' . ($r['msg'] ?? '未知');
                }
            } else {
                $errors[] = '缺少提交者ID，无法私信';
            }
        }

        // 发到原群聊（Markdown + @提交者）
        if ($sendTo === 'group' || $sendTo === 'both') {
            if ($chatType === 'group' && $chatId !== '') {
                $atList = $senderId !== '' ? [$senderId] : [];
                $r = $bot->sendMarkdownAtGroup($chatId, $replyMarkdown, $atList);
                if (($r['code'] ?? 0) == 1) {
                    $sent[] = '群聊';
                } else {
                    $errors[] = '群聊失败: ' . ($r['msg'] ?? '未知');
                }
            } else {
                $errors[] = '该反馈非群聊来源，无法发群';
            }
        }

        // 记录回复历史
        $replyHistory = $item['replies'] ?? [];
        $replyHistory[] = [
            'content'    => $replyContent,
            'send_to'    => $sendTo,
            'sent'       => $sent,
            'admin'      => $_SESSION['yh_user']['nickname'] ?? ($_SESSION[$ADMIN_KEY] ? '管理员' : ''),
            'created_at' => time(),
        ];
        $store->update($no, ['replies' => $replyHistory]);

        $msg = !empty($sent) ? '已发送：' . implode('、', $sent) : '发送失败';
        if (!empty($errors)) {
            $msg .= '；' . implode('；', $errors);
        }
        header('Location: ' . rtrim($config['site_url'], '/') . '/fb-admin.php?reply_msg=' . urlencode($msg));
        exit;
    }
}

/* ---------- 未登录，渲染登录页 ---------- */
if (!$isAdmin) {
    $err = isset($loginError) ? '<div class="error">' . e($loginError) . '</div>' : '';
    $loginErrMsg = $_GET['login_error'] ?? '';
    if ($loginErrMsg !== '') {
        $err = '<div class="error">' . e($loginErrMsg) . '</div>';
    }
    $site = rtrim($config['site_url'], '/');
    $cssVer = file_exists(__DIR__ . '/assets/style.css') ? filemtime(__DIR__ . '/assets/style.css') : time();
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>管理员登录</title>'
        . '<link rel="stylesheet" href="/assets/style.css?v=' . $cssVer . '">'
        . '<style>
            .login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
            .login-box{max-width:400px;width:100%;background:#fff;border:1px solid var(--border);border-radius:20px;padding:40px 36px;box-shadow:var(--shadow-lg);}
            .login-box h1{font-size:24px;font-weight:800;margin-bottom:8px;text-align:center;letter-spacing:-0.5px;}
            .login-sub{text-align:center;color:var(--muted);font-size:13px;margin-bottom:28px;}
            .oauth-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:13px;background:var(--accent-grad);color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:600;cursor:pointer;text-align:center;text-decoration:none;box-shadow:0 4px 16px rgba(79,107,254,.3);transition:transform .15s,box-shadow .15s;}
            .oauth-btn:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(79,107,254,.4);}
            .divider{display:flex;align-items:center;color:var(--muted);font-size:12px;margin:22px 0;}
            .divider::before,.divider::after{content:"";flex:1;height:1px;background:var(--border);}
            .divider span{padding:0 14px;}
            .login-box input{width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:10px;margin-bottom:14px;font-size:14px;font-family:inherit;}
            .login-box input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(79,107,254,.12);}
            .login-box button[type=submit]{width:100%;padding:12px;background:var(--accent-grad);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;transition:opacity .15s;}
            .login-box button[type=submit]:hover{opacity:.9;}
            .error{color:#ef4444;font-size:13px;margin-bottom:12px;text-align:center;}
            .tip{font-size:12px;color:var(--muted);margin-top:16px;text-align:center;}
          </style></head><body>'
        . '<div class="login-wrap"><div class="login-box"><h1>管理员登录</h1>'
        . '<div class="login-sub">反馈管理后台</div>' . $err
        . '<a class="oauth-btn" href="' . $site . '/fb-admin.php?action=oauth_login"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>使用云湖账号登录</a>'
        . '<div class="divider"><span>或使用密码</span></div>'
        . '<form method="post">'
        . '<input type="hidden" name="action" value="login">'
        . '<input type="password" name="password" placeholder="请输入管理员密码" autofocus>'
        . '<button type="submit">登录</button>'
        . '</form>'
        . '<div class="tip">云湖登录需账号在管理员白名单内</div>'
        . '</div></div></body></html>';
    exit;
}

/* ---------- 后台主界面 ---------- */
$list = $store->all();
$site = rtrim($config['site_url'], '/');

// 统计
$total = count($list);
$pending = 0; $replied = 0; $resolved = 0; $planned = 0; $closed = 0;
foreach ($list as $it) {
    if ($it['status'] === 'pending') $pending++;
    elseif ($it['status'] === 'replied') $replied++;
    elseif ($it['status'] === 'resolved') $resolved++;
    elseif ($it['status'] === 'planned') $planned++;
    elseif ($it['status'] === 'closed') $closed++;
}

$statusOptions = [
    'pending'  => '待跟进',
    'replied'  => '已回复',
    'resolved' => '已解决',
    'planned'  => '已加入开发计划',
    'closed'   => '已关闭',
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
<?php $cssVer = file_exists(__DIR__ . '/assets/style.css') ? filemtime(__DIR__ . '/assets/style.css') : time(); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>反馈管理后台</title>
<link rel="stylesheet" href="/assets/style.css?v=<?php echo $cssVer; ?>">
<style>
  .admin-bar { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; }
  .admin-bar h1 {
    font-size:24px; font-weight:800; letter-spacing:-0.5px;
    background:var(--accent-grad); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
  }
  .admin-bar .logout {
    color:var(--accent); text-decoration:none; font-size:13px; font-weight:500;
    padding:6px 16px; border:1px solid var(--border); border-radius:999px; background:var(--card);
  }
  .admin-bar .logout:hover { border-color:var(--accent); }
  .stats { display:flex; gap:12px; margin-bottom:22px; flex-wrap:wrap; }
  .stat {
    background:var(--card); border:1px solid var(--border); border-radius:14px;
    padding:14px 20px; text-align:center; min-width:96px; box-shadow:var(--shadow-sm);
    flex:1;
  }
  .stat .num { font-size:26px; font-weight:800; color:var(--accent); }
  .stat .lab { font-size:12px; color:var(--muted); margin-top:2px; }
  table {
    width:100%; border-collapse:separate; border-spacing:0;
    background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden;
    box-shadow:var(--shadow-sm);
  }
  th, td { padding:12px 14px; text-align:left; font-size:13px; border-bottom:1px solid var(--border); vertical-align:top; }
  th { background:#fafbfd; color:var(--text-secondary); font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.4px; }
  tr:last-child td { border-bottom:none; }
  tbody tr { transition:background .15s; }
  tbody tr:hover { background:#fafbff; }
  .cell-content { max-width:340px; word-break:break-word; }
  .status-select {
    border:1px solid var(--border); border-radius:8px; padding:5px 10px;
    font-size:13px; background:#fff; color:var(--text-secondary); cursor:pointer;
  }
  .status-select:focus { outline:none; border-color:var(--accent); }
  .del-btn { color:#ef4444; text-decoration:none; font-size:13px; }
  .del-btn:hover { text-decoration:underline; }
  .type-badge {
    color:var(--accent); background:var(--accent-soft); padding:2px 10px;
    border-radius:6px; font-weight:500; font-size:12px;
  }
  .batch-bar {
    display:flex; align-items:center; gap:16px; margin-bottom:14px;
    padding:12px 16px; background:var(--card); border:1px solid var(--border);
    border-radius:12px; box-shadow:var(--shadow-sm);
  }
  .select-all { font-size:13px; color:var(--text-secondary); cursor:pointer; display:flex; align-items:center; gap:6px; }
  .batch-del-btn {
    padding:7px 18px; background:#ef4444; color:#fff; border:none; border-radius:8px;
    cursor:pointer; font-size:13px; font-weight:500; transition:opacity .15s;
  }
  .batch-del-btn:disabled { background:#f3c1c4; cursor:not-allowed; }
  .batch-count { font-size:13px; color:var(--muted); margin-left:auto; }
  .row-check, #check-all, #check-all-top { cursor:pointer; accent-color:var(--accent); }
  .reply-btn { color:var(--accent); text-decoration:none; font-size:13px; font-weight:500; margin-right:10px; }
  .reply-btn:hover { text-decoration:underline; }
  .reply-modal { position:fixed; inset:0; background:rgba(16,24,40,.5); display:flex; align-items:center; justify-content:center; z-index:1000; backdrop-filter:blur(4px); }
  .reply-modal-box { background:#fff; border-radius:16px; width:90%; max-width:520px; box-shadow:var(--shadow-lg); }
  .reply-modal-head { display:flex; align-items:center; justify-content:space-between; padding:18px 22px; border-bottom:1px solid var(--border); font-weight:700; font-size:16px; }
  .reply-close { color:var(--muted); text-decoration:none; font-size:24px; line-height:1; }
  .reply-modal-body { padding:22px; }
  .reply-info { font-size:13px; color:var(--muted); margin-bottom:14px; }
  .reply-modal-body textarea {
    width:100%; border:1px solid var(--border); border-radius:10px; padding:12px;
    font-size:14px; resize:vertical; font-family:inherit;
  }
  .reply-modal-body textarea:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(79,107,254,.12); }
  .reply-options { display:flex; gap:16px; margin:14px 0; font-size:13px; flex-wrap:wrap; }
  .reply-options label { display:flex; align-items:center; gap:5px; cursor:pointer; }
  .reply-options input { accent-color:var(--accent); }
  .reply-actions { display:flex; gap:10px; }
  .flash { padding:12px 18px; border-radius:10px; font-size:13px; margin-bottom:14px; }
  .flash-ok { background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; }
  .flash-err { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
</style>
</head>
<body>
<div class="container" style="max-width:1100px;">
  <header class="admin-bar">
    <h1>反馈管理后台</h1>
    <div style="display:flex;align-items:center;gap:12px;">
      <?php if (!empty($_SESSION['yh_user']['nickname'])): ?>
        <span style="color:var(--muted);font-size:13px;"><?php echo e($_SESSION['yh_user']['nickname']); ?></span>
      <?php endif; ?>
      <a class="logout" href="?action=logout">退出登录</a>
    </div>
  </header>

  <?php if (!empty($_GET['reply_msg'])): ?>
    <div class="flash flash-ok"><?php echo e($_GET['reply_msg']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['reply_error'])): ?>
    <div class="flash flash-err"><?php echo e($_GET['reply_error']); ?></div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="num"><?php echo $total; ?></div><div class="lab">全部</div></div>
    <div class="stat"><div class="num"><?php echo $pending; ?></div><div class="lab">待跟进</div></div>
    <div class="stat"><div class="num"><?php echo $replied; ?></div><div class="lab">已回复</div></div>
    <div class="stat"><div class="num"><?php echo $resolved; ?></div><div class="lab">已解决</div></div>
    <div class="stat"><div class="num"><?php echo $planned; ?></div><div class="lab">已加入开发计划</div></div>
    <div class="stat"><div class="num"><?php echo $closed; ?></div><div class="lab">已关闭</div></div>
  </div>

  <?php if (empty($list)): ?>
    <div class="empty">暂无反馈</div>
  <?php else: ?>
  <div class="batch-bar">
    <label class="select-all"><input type="checkbox" id="check-all"> 全选</label>
    <button class="batch-del-btn" id="batch-del" disabled>批量删除</button>
    <span class="batch-count" id="batch-count">已选 0 项</span>
  </div>
  <table id="fb-table">
    <thead>
      <tr>
        <th style="width:36px;"><input type="checkbox" id="check-all-top"></th>
        <th>编号</th><th>内容</th><th>类型</th><th>提交者</th><th>时间</th><th>状态</th><th>操作</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($list as $item): ?>
      <tr>
        <td><input type="checkbox" class="row-check" value="<?php echo e($item['no']); ?>"></td>
        <td><a href="<?php echo $site; ?>/feedback.php?no=<?php echo e($item['no']); ?>" target="_blank"><?php echo full_no($item['no']); ?></a></td>
        <td class="cell-content"><?php echo e($item['content']); ?></td>
        <td><span class="type-badge"><?php echo type_label($item['type']); ?></span></td>
        <td><?php echo e($item['senderNickname'] ?: '匿名'); ?><br><span style="color:var(--muted)"><?php echo e($item['senderId']); ?></span></td>
        <td><?php echo fmt_time($item['created_at']); ?></td>
        <td><?php echo status_select($item['no'], $item['status']); ?></td>
        <td>
          <a class="reply-btn" href="javascript:;" data-no="<?php echo e($item['no']); ?>" data-nick="<?php echo e($item['senderNickname'] ?: ''); ?>" data-sender="<?php echo e($item['senderId']); ?>" data-chattype="<?php echo e($item['chatType'] ?? 'bot'); ?>" data-chatid="<?php echo e($item['chatId'] ?? ''); ?>">回复</a>
          <a class="del-btn" href="?action=delete&no=<?php echo e($item['no']); ?>" onclick="return confirm('确定删除该反馈？');">删除</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- 回复弹窗 -->
<div class="reply-modal" id="reply-modal" style="display:none;">
  <div class="reply-modal-box">
    <div class="reply-modal-head">
      <span id="reply-title">回复反馈</span>
      <a href="javascript:;" class="reply-close" id="reply-close">×</a>
    </div>
    <div class="reply-modal-body">
      <div class="reply-info" id="reply-info"></div>
      <textarea id="reply-content" rows="5" placeholder="输入回复内容…"></textarea>
      <div class="reply-options">
        <label><input type="radio" name="send_to" value="user" checked> 私信提交者</label>
        <label><input type="radio" name="send_to" value="group"> 发到原群聊</label>
        <label><input type="radio" name="send_to" value="both"> 两者都发</label>
      </div>
      <div class="reply-actions">
        <button class="act-btn primary" id="reply-send">发送</button>
        <button class="act-btn" id="reply-cancel">取消</button>
      </div>
    </div>
  </div>
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

// 批量删除
(function(){
  var checks = document.querySelectorAll('.row-check');
  var checkAll = document.getElementById('check-all');
  var checkAllTop = document.getElementById('check-all-top');
  var batchDel = document.getElementById('batch-del');
  var batchCount = document.getElementById('batch-count');

  function updateState(){
    var checked = document.querySelectorAll('.row-check:checked');
    var n = checked.length;
    batchCount.textContent = '已选 ' + n + ' 项';
    batchDel.disabled = (n === 0);
    if (checkAll) checkAll.checked = (n === checks.length && n > 0);
    if (checkAllTop) checkAllTop.checked = (n === checks.length && n > 0);
  }

  function setAll(checked){
    checks.forEach(function(c){ c.checked = checked; });
    updateState();
  }

  if (checkAll) checkAll.addEventListener('change', function(){ setAll(checkAll.checked); });
  if (checkAllTop) checkAllTop.addEventListener('change', function(){ setAll(checkAllTop.checked); });
  checks.forEach(function(c){ c.addEventListener('change', updateState); });

  if (batchDel) {
    batchDel.addEventListener('click', function(){
      var checked = document.querySelectorAll('.row-check:checked');
      if (checked.length === 0) return;
      if (!confirm('确定删除选中的 ' + checked.length + ' 条反馈吗？删除后不可恢复')) return;
      var form = document.createElement('form');
      form.method = 'post';
      form.style.display = 'none';
      var f1 = document.createElement('input'); f1.name='action'; f1.value='batch_delete';
      form.appendChild(f1);
      checked.forEach(function(c){
        var f = document.createElement('input'); f.name='nos[]'; f.value=c.value;
        form.appendChild(f);
      });
      document.body.appendChild(form);
      form.submit();
    });
  }
})();

// 回复弹窗
(function(){
  var modal = document.getElementById('reply-modal');
  var replyBtns = document.querySelectorAll('.reply-btn');
  var closeBtn = document.getElementById('reply-close');
  var cancelBtn = document.getElementById('reply-cancel');
  var sendBtn = document.getElementById('reply-send');
  var contentEl = document.getElementById('reply-content');
  var infoEl = document.getElementById('reply-info');
  var currentNo = '';

  function openReply(btn){
    currentNo = btn.getAttribute('data-no');
    var nick = btn.getAttribute('data-nick') || '匿名';
    var sender = btn.getAttribute('data-sender') || '';
    var chattype = btn.getAttribute('data-chattype');
    var chatid = btn.getAttribute('data-chatid');
    document.getElementById('reply-title').textContent = '回复 #FB-' + currentNo;
    infoEl.innerHTML = '提交者：<b>' + nick + '</b>' + (sender ? '（ID ' + sender + '）' : '')
      + (chattype === 'group' ? ' · 来自群聊 ' + chatid : ' · 私聊来源');
    // 根据来源预选发送方式
    var radios = document.querySelectorAll('input[name="send_to"]');
    var defaultVal = chattype === 'group' ? 'group' : 'user';
    radios.forEach(function(r){ r.checked = (r.value === defaultVal); });
    contentEl.value = '';
    modal.style.display = 'flex';
  }

  function closeReply(){
    modal.style.display = 'none';
  }

  replyBtns.forEach(function(b){
    b.addEventListener('click', function(){ openReply(b); });
  });
  if (closeBtn) closeBtn.addEventListener('click', closeReply);
  if (cancelBtn) cancelBtn.addEventListener('click', closeReply);
  if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeReply(); });

  if (sendBtn) {
    sendBtn.addEventListener('click', function(){
      var content = contentEl.value.trim();
      if (!content) { alert('请输入回复内容'); return; }
      var sendTo = document.querySelector('input[name="send_to"]:checked').value;
      var form = document.createElement('form');
      form.method = 'post';
      form.style.display = 'none';
      var f1 = document.createElement('input'); f1.name='action'; f1.value='reply';
      var f2 = document.createElement('input'); f2.name='no'; f2.value=currentNo;
      var f3 = document.createElement('input'); f3.name='reply_content'; f3.value=content;
      var f4 = document.createElement('input'); f4.name='send_to'; f4.value=sendTo;
      form.appendChild(f1); form.appendChild(f2); form.appendChild(f3); form.appendChild(f4);
      document.body.appendChild(form);
      form.submit();
    });
  }
})();
</script>
</body>
</html>
