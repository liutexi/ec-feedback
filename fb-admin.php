<?php
/**
 * 管理员后台
 * 访问路径：/fb-admin.php
 * 功能：云湖 OAuth2 登录（白名单校验）、查看全部反馈、修改状态、删除反馈
 */

require __DIR__ . '/inc/FeedbackStore.php';
require __DIR__ . '/inc/store.php';
require __DIR__ . '/inc/Settings.php';
require __DIR__ . '/inc/AdminLog.php';
require __DIR__ . '/inc/YunhuOAuth.php';
require __DIR__ . '/inc/YunhuBot.php';
require __DIR__ . '/inc/functions.php';

$config = require __DIR__ . '/config.php';
$settings = new Settings($config['data_dir']);
$store = create_store($config, $settings);
$adminLog = new AdminLog($config['data_dir']);
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

// 当前操作人信息（用于操作日志）
$operatorInfo = [
    'user_id'  => $_SESSION['yh_user']['user_id'] ?? '',
    'nickname' => $_SESSION['yh_user']['nickname'] ?? (($_SESSION[$ADMIN_KEY] ?? false) ? '管理员' : ''),
];

/* ---------- 操作处理 ---------- */
if ($isAdmin) {
    if ($action === 'update_status') {
        $no = $_POST['no'] ?? '';
        $status = $_POST['status'] ?? '';
        $allowed = ['pending', 'replied', 'resolved', 'planned', 'closed'];
        if ($no && in_array($status, $allowed, true)) {
            $before = $store->find($no);
            $store->update($no, ['status' => $status]);
            // 处理记录：状态变更
            $store->addProcessLog($no, 'status', [
                'from' => $before['status'] ?? '',
                'to'   => $status,
            ], $operatorInfo);
            $adminLog->log('update_status', [
                'no'     => $no,
                'from'   => $before['status'] ?? '',
                'to'     => $status,
            ], $operatorInfo);
        }
        header('Location: ' . rtrim($config['site_url'], '/') . '/fb-admin.php');
        exit;
    }
    if ($action === 'delete') {
        $no = $_GET['no'] ?? ($_POST['no'] ?? '');
        if ($no) {
            $before = $store->find($no);
            $store->delete($no);
            $adminLog->log('delete', [
                'no'      => $no,
                'content' => isset($before['content']) ? mb_substr($before['content'], 0, 100) : '',
            ], $operatorInfo);
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
            $adminLog->log('batch_delete', [
                'count' => count($nos),
                'nos'   => implode(',', $nos),
            ], $operatorInfo);
        }
        header('Location: ' . rtrim($config['site_url'], '/') . '/fb-admin.php');
        exit;
    }
    if ($action === 'save_settings') {
        // ---- 主题颜色（先校验，避免其它项已写入后才发现错误）----
        $themeColor = normalize_theme_color($_POST['theme_color'] ?? '');
        if ($themeColor === null) {
            header('Location: ' . rtrim($config['site_url'], '/')
                . '/fb-admin.php?theme_error=' . urlencode('主题颜色格式不正确：请填 #RRGGBB 或 #RGB') . '#theme-color-config');
            exit;
        }

        // ---- 网站 Logo / 站点名称 ----
        $siteName = mb_substr(trim((string)($_POST['site_name'] ?? '')), 0, 30);
        $logoError = '';

        if (!empty($_POST['site_logo_reset'])) {
            $settings->set('site_logo', '');
            clear_site_logo_file();
        } else {
            $upload = handle_site_logo_upload($settings, $_FILES['site_logo_file'] ?? null);
            if (!$upload['ok']) {
                $logoError = $upload['message'];
            } elseif (!$upload['changed']) {
                // 没选新文件 → 以输入框里的地址为准（清空输入框 = 恢复自动检测）
                $norm = normalize_site_logo_url($_POST['site_logo_url'] ?? '');
                if ($norm === null) {
                    $logoError = 'Logo 地址不合法：请填写 http(s) 图片链接或站内路径（如 /logo.png）';
                } else {
                    // 从"上传文件"改成外链 / 清空时，删掉已上传的图片，避免残留
                    if (strpos($norm, '/uploads/site-logo.') !== 0) {
                        clear_site_logo_file();
                    }
                    $settings->set('site_logo', $norm);
                }
            }
        }
        if ($logoError !== '') {
            header('Location: ' . rtrim($config['site_url'], '/')
                . '/fb-admin.php?logo_error=' . urlencode($logoError) . '#site-logo-config');
            exit;
        }
        $settings->set('site_name', $siteName);
        $settings->set('theme_color', $themeColor);

        $shortNo = !empty($_POST['short_no_enabled']);
        $settings->set('short_no_enabled', $shortNo);

        // 存储驱动
        $driver = ($_POST['storage_driver'] ?? 'json') === 'mysql' ? 'mysql' : 'json';
        $dbInput = [
            'host'     => trim($_POST['db_host'] ?? ''),
            'port'     => (int)($_POST['db_port'] ?? 3306) ?: 3306,
            'database' => trim($_POST['db_database'] ?? ''),
            'username' => trim($_POST['db_username'] ?? ''),
            'password' => (string)($_POST['db_password'] ?? ''),
            'prefix'   => trim($_POST['db_prefix'] ?? '') ?: 'fb_',
            'charset'  => 'utf8mb4',
        ];

        // 切到 MySQL 前先验证连接，避免保存后整站不可用
        if ($driver === 'mysql') {
            $test = test_db_connection($dbInput);
            if (!$test['ok']) {
                header('Location: ' . rtrim($config['site_url'], '/')
                    . '/fb-admin.php?storage_error=' . urlencode('数据库连接失败：' . $test['message']));
                exit;
            }
        }

        $settings->set('storage_driver', $driver);
        $settings->set('storage_db', $dbInput);

        $adminLog->log('save_settings', [
            'short_no_enabled' => $shortNo,
            'storage_driver'   => $driver,
            'site_name'        => $siteName,
            'site_logo'        => (string)$settings->get('site_logo', ''),
            'theme_color'      => $themeColor,
        ], $operatorInfo);

        // 区块内的就近保存按钮：保存后跳回该区块并显示成功提示
        $scope = (string)($_POST['save_scope'] ?? '');
        if ($scope === 'logo') {
            $backQuery = 'logo_saved=1#site-logo-config';
        } elseif ($scope === 'theme') {
            $backQuery = 'theme_saved=1#theme-color-config';
        } else {
            $backQuery = 'settings_saved=1';
        }
        header('Location: ' . rtrim($config['site_url'], '/') . '/fb-admin.php?' . $backQuery);
        exit;
    }
    // 测试数据库连接（AJAX）
    if ($action === 'test_db') {
        header('Content-Type: application/json; charset=utf-8');
        $dbInput = [
            'host'     => trim($_POST['db_host'] ?? ''),
            'port'     => (int)($_POST['db_port'] ?? 3306) ?: 3306,
            'database' => trim($_POST['db_database'] ?? ''),
            'username' => trim($_POST['db_username'] ?? ''),
            'password' => (string)($_POST['db_password'] ?? ''),
            'charset'  => 'utf8mb4',
        ];
        echo json_encode(test_db_connection($dbInput), JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 迁移 JSON -> 数据库（AJAX）
    if ($action === 'migrate_to_db') {
        header('Content-Type: application/json; charset=utf-8');
        $dbInput = [
            'host'     => trim($_POST['db_host'] ?? ''),
            'port'     => (int)($_POST['db_port'] ?? 3306) ?: 3306,
            'database' => trim($_POST['db_database'] ?? ''),
            'username' => trim($_POST['db_username'] ?? ''),
            'password' => (string)($_POST['db_password'] ?? ''),
            'prefix'   => trim($_POST['db_prefix'] ?? '') ?: 'fb_',
            'charset'  => 'utf8mb4',
        ];
        try {
            $jsonStore = new FeedbackStore($config['data_dir']);
            $dbStore = new FeedbackStoreDb($dbInput);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'message' => '数据库连接失败：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pdo = $dbStore->pdo();
        $p = $dbStore->prefix();

        $fbOk = 0; $fbSkip = 0;
        foreach ($jsonStore->all() as $f) {
            if ($dbStore->find($f['no'])) { $fbSkip++; continue; }
            $stmt = $pdo->prepare("INSERT INTO `{$p}feedbacks`
                (`no`,`content`,`status`,`type`,`priority`,`senderNickname`,`senderId`,`senderType`,
                 `owner_user_id`,`chatType`,`chatId`,`likes`,`liked_by`,`replies`,`created_at`,`updated_at`)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $f['no'], $f['content'] ?? '', $f['status'] ?? 'pending', $f['type'] ?? 'other',
                $f['priority'] ?? 'normal', $f['senderNickname'] ?? '', $f['senderId'] ?? '',
                $f['senderType'] ?? 'user', $f['owner_user_id'] ?? ($f['senderId'] ?? ''),
                $f['chatType'] ?? 'bot', $f['chatId'] ?? '', (int)($f['likes'] ?? 0),
                json_encode($f['liked_by'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($f['replies'] ?? [], JSON_UNESCAPED_UNICODE),
                (int)($f['created_at'] ?? 0), (int)($f['updated_at'] ?? 0),
            ]);
            $fbOk++;
        }

        $cOk = 0; $cSkip = 0;
        $cFile = rtrim($config['data_dir'], '/\\') . '/comments.json';
        if (file_exists($cFile)) {
            $arr = json_decode((string)file_get_contents($cFile), true);
            if (is_array($arr)) {
                foreach ($arr as $c) {
                    if ($dbStore->findComment($c['id'])) { $cSkip++; continue; }
                    $stmt = $pdo->prepare("INSERT INTO `{$p}comments`
                        (`id`,`feedback_no`,`user_id`,`nickname`,`avatar_url`,`content`,`created_at`)
                        VALUES (?,?,?,?,?,?,?)");
                    $stmt->execute([
                        $c['id'], $c['feedback_no'] ?? '', $c['user_id'] ?? '', $c['nickname'] ?? '',
                        $c['avatar_url'] ?? '', $c['content'] ?? '', (int)($c['created_at'] ?? 0),
                    ]);
                    $cOk++;
                }
            }
        }

        $hOk = 0; $hSkip = 0;
        $hFile = rtrim($config['data_dir'], '/\\') . '/history.json';
        if (file_exists($hFile)) {
            $arr = json_decode((string)file_get_contents($hFile), true);
            if (is_array($arr)) {
                foreach ($arr as $h) {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM `{$p}history` WHERE `id` = ?");
                    $chk->execute([$h['id'] ?? '']);
                    if ((int)$chk->fetchColumn() > 0) { $hSkip++; continue; }
                    $stmt = $pdo->prepare("INSERT INTO `{$p}history`
                        (`id`,`feedback_no`,`old_content`,`new_content`,`user_id`,`nickname`,`created_at`)
                        VALUES (?,?,?,?,?,?,?)");
                    $stmt->execute([
                        $h['id'] ?? uniqid('h', true), $h['feedback_no'] ?? '',
                        $h['old_content'] ?? '', $h['new_content'] ?? '',
                        $h['user_id'] ?? '', $h['nickname'] ?? '', (int)($h['created_at'] ?? 0),
                    ]);
                    $hOk++;
                }
            }
        }

        echo json_encode([
            'ok' => true,
            'message' => "迁移完成：反馈 {$fbOk} 条（跳过 {$fbSkip}），评论 {$cOk} 条（跳过 {$cSkip}），历史 {$hOk} 条（跳过 {$hSkip}）",
        ], JSON_UNESCAPED_UNICODE);
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

        // 处理记录：管理员回复
        $store->addProcessLog($no, 'reply', [
            'content'    => $replyContent,
            'send_to'    => $sendTo,
            'sent'       => implode('、', $sent),
        ], $operatorInfo);

        $adminLog->log('reply', [
            'no'      => $no,
            'send_to' => $sendTo,
            'sent'    => implode('、', $sent),
        ], $operatorInfo);

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
    $__loginLogo = site_logo_url($settings);
    $__loginFavicon = $__loginLogo !== '' ? '<link rel="icon" href="' . e($__loginLogo) . '">' : '';
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>管理员登录</title>'
        . $__loginFavicon
        . '<link rel="stylesheet" href="/assets/style.css?v=' . $cssVer . '">'
        . theme_color_style($settings)
        . '<style>
            .login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
            .login-box{max-width:400px;width:100%;background:#fff;border:1px solid var(--border);border-radius:20px;padding:40px 36px;box-shadow:var(--shadow-lg);}
            .login-box h1{font-size:22px;font-weight:700;margin-bottom:8px;text-align:center;letter-spacing:-0.3px;color:var(--text);}
            .login-sub{text-align:center;color:var(--muted);font-size:13px;margin-bottom:24px;}
            .oauth-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;background:var(--accent);color:var(--accent-fg,#fff);border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;text-align:center;text-decoration:none;transition:background .15s;}
            .oauth-btn:hover{background:var(--accent-strong);}
            .oauth-btn .ico{display:inline-flex;transition:transform .2s cubic-bezier(.34,1.3,.64,1);}
            .oauth-btn:hover .ico{transform:translateX(2px);}
            @media (prefers-reduced-motion: reduce){.oauth-btn .ico,.oauth-btn:hover .ico{transition:none;transform:none;}}
            .divider{display:flex;align-items:center;color:var(--muted);font-size:12px;margin:20px 0;}
            .divider::before,.divider::after{content:"";flex:1;height:1px;background:var(--border);}
            .divider span{padding:0 14px;}
            .login-box input{width:100%;padding:11px 14px;border:1px solid var(--border);border-radius:8px;margin-bottom:14px;font-size:14px;font-family:inherit;}
            .login-box input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(var(--accent-rgb),.12);}
            .login-box button[type=submit]{width:100%;padding:11px;background:var(--accent);color:var(--accent-fg,#fff);border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;transition:background .15s;}
            .login-box button[type=submit]:hover{background:var(--accent-strong);}
            .error{color:#ef4444;font-size:13px;margin-bottom:12px;text-align:center;}
            .tip{font-size:12px;color:var(--muted);margin-top:16px;text-align:center;}
            .login-logo{display:block;width:46px;height:46px;object-fit:contain;margin:0 auto 14px;border-radius:12px;border:1px solid var(--border);box-shadow:var(--shadow-sm);}
            .login-back{display:inline-flex;align-items:center;justify-content:center;gap:4px;width:100%;margin-top:14px;font-size:12px;color:var(--muted);text-decoration:none;transition:color .15s;}
            .login-back .ico{display:inline-flex;transition:transform .2s cubic-bezier(.34,1.3,.64,1);}
            .login-back:hover .ico{transform:translateX(-2px);}
            .login-back:hover{color:var(--accent-text);}
          </style></head><body>'
        . '<div class="login-wrap"><div class="login-box">'
        . ($__loginLogo !== '' ? '<img class="login-logo" src="' . e($__loginLogo) . '" alt="">' : '')
        . '<h1>管理员登录</h1>'
        . '<div class="login-sub">反馈管理后台</div>' . $err
        . '<a class="oauth-btn" href="' . $site . '/fb-admin.php?action=oauth_login"><span class="ico"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg></span>使用云湖账号登录</a>'
        . '<div class="divider"><span>或使用密码</span></div>'
        . '<form method="post">'
        . '<input type="hidden" name="action" value="login">'
        . '<input type="password" name="password" placeholder="请输入管理员密码" autofocus>'
        . '<button type="submit">登录</button>'
        . '</form>'
        . '<div class="tip">云湖登录需账号在管理员白名单内</div>'
        . '<a class="login-back" href="/feedback.php"><span class="ico"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg></span>返回反馈页</a>'
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

// 看板统计
$dashboard = $store->dashboardStats();

// 安全检查：探测 data 目录是否可被公网直接访问
// 结果：exposed=危险  protected=已保护  unknown=无法检测
$dataSecurity = 'unknown';
$probeUrl = rtrim($config['site_url'], '/') . '/data/feedbacks.json';
if (function_exists('curl_init') && $probeUrl !== '' && strpos($probeUrl, '你的网站域名') === false) {
    $ch = curl_init($probeUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $probeBody = curl_exec($ch);
    $probeCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $probeErr  = curl_error($ch);
    curl_close($ch);

    if ($probeBody === false || $probeErr !== '') {
        $dataSecurity = 'unknown';   // 请求失败（DNS/防火墙），无法判断
    } elseif ($probeCode === 200 && strlen($probeBody) > 0) {
        $dataSecurity = 'exposed';   // 200 且有内容 => 暴露
    } elseif ($probeCode === 403 || $probeCode === 404) {
        $dataSecurity = 'protected'; // 被拒绝或不存在 => 安全
    } else {
        $dataSecurity = 'unknown';
    }
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
    $html = '<select class="status-select" data-no="' . e($no) . '" data-st="' . e($current) . '">';
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
<?php $__adminLogo = site_logo_url($settings); if ($__adminLogo !== ''): ?>
<link rel="icon" href="<?php echo e($__adminLogo); ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/style.css?v=<?php echo $cssVer; ?>">
<?php echo theme_color_style($settings); ?>
<style>
  /* ===== 顶部导航 ===== */
  .admin-topbar {
    position:sticky; top:0; z-index:50; width:100%;
    background:#fff;
    border-bottom:1px solid var(--border);
  }
  .admin-topbar-inner {
    max-width:1100px; margin:0 auto; padding:0 20px; min-height:60px;
    display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
  }
  .admin-brand { display:flex; align-items:center; gap:9px; min-width:0; }
  /* 与前台 .brand-logo 完全同规格：32px / 圆角 8px / 纯主色 */
  .admin-brand-logo {
    width:32px; height:32px; flex-shrink:0; border-radius:8px;
    object-fit:cover; background:var(--card); border:1px solid var(--border);
  }
  .admin-brand-fallback {
    display:inline-flex; align-items:center; justify-content:center; color:var(--accent-fg,#fff);
    background:var(--accent); border:none;
  }
  .admin-brand-title { font-size:16px; font-weight:700; letter-spacing:-.3px; line-height:1.3; color:var(--text); }
  .admin-brand-sub { font-size:11px; color:var(--muted); line-height:1.4; }
  .admin-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .admin-back {
    display:inline-flex; align-items:center; gap:6px; padding:8px 15px;
    background:var(--accent); color:var(--accent-fg,#fff); border-radius:8px;
    font-size:13px; font-weight:600; text-decoration:none; white-space:nowrap;
    box-shadow:0 2px 8px rgba(var(--accent-rgb),.24); transition:all .18s;
  }
  .admin-back:hover {
    background:var(--accent-strong); transform:translateY(-1px);
    box-shadow:0 5px 14px rgba(var(--accent-rgb),.3);
  }
  .admin-user {
    display:inline-flex; align-items:center; gap:6px; padding:6px 12px;
    background:var(--bg); border:1px solid var(--border); border-radius:999px;
    font-size:12px; color:var(--text-secondary); white-space:nowrap;
  }
  .admin-user-dot { width:6px; height:6px; border-radius:50%; background:var(--resolved); }
  .admin-logout {
    color:var(--text-secondary); text-decoration:none; font-size:13px; font-weight:500;
    padding:8px 14px; border:1px solid var(--border); border-radius:8px; background:var(--card);
    white-space:nowrap; transition:all .18s;
  }
  .admin-logout:hover { border-color:var(--accent); color:var(--accent-text); background:var(--accent-soft); }
  .admin-main { padding-top:28px; padding-bottom:56px; }
  /* 锚点跳转时给吸顶栏留位置（Logo 保存后回跳用） */
  #site-logo-config { scroll-margin-top:86px; }
  .act-btn.primary { box-shadow:0 2px 8px rgba(var(--accent-rgb),.2); }
  .act-btn.primary:hover { box-shadow:0 5px 14px rgba(var(--accent-rgb),.3); }

  /* ===== 分区标题 ===== */
  .section-head {
    display:flex; align-items:baseline; justify-content:space-between; gap:6px 12px;
    margin:2px 0 12px; flex-wrap:wrap;
  }
  .section-head h2 { font-size:15px; font-weight:700; letter-spacing:-.2px; color:var(--text); }
  .section-head span { font-size:12px; color:var(--muted); min-width:0; }

  /* ===== 统计卡片 ===== */
  .stats { display:flex; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
  .stat {
    --stat-c: var(--accent);
    position:relative; overflow:hidden;
    background:var(--card); border:1px solid var(--border); border-radius:12px;
    padding:16px 18px; min-width:96px; box-shadow:var(--shadow-sm); flex:1;
    transition:transform .18s, box-shadow .18s, border-color .18s;
  }
  .stat:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); border-color:rgba(var(--accent-rgb),.22); }
  .stat::before {
    content:""; position:absolute; left:0; top:13px; bottom:13px;
    width:3px; border-radius:0 3px 3px 0; background:var(--stat-c);
  }
  .stat .num { font-size:26px; font-weight:800; line-height:1.2; color:var(--stat-c); letter-spacing:-.5px; }
  .stat .lab { font-size:12px; color:var(--muted); margin-top:3px; }
  .stats-status .stat:nth-child(1) { --stat-c:var(--pending); }
  .stats-status .stat:nth-child(2) { --stat-c:var(--replied); }
  .stats-status .stat:nth-child(3) { --stat-c:var(--resolved); }
  .stats-status .stat:nth-child(4) { --stat-c:var(--planned); }
  .stats-status .stat:nth-child(5) { --stat-c:var(--closed); }
  .stats-status .stat:nth-child(6) { --stat-c:var(--accent); }
  @media (max-width:640px) {
    .admin-topbar-inner { padding:10px 14px; gap:10px; }
    .admin-brand-sub { display:none; }
    .admin-back, .admin-logout { padding:7px 11px; font-size:12px; }
    .stat { min-width:calc(50% - 6px); }
  }
  /* 窄屏时表格横向滚动，避免把整页撑宽 */
  .table-scroll { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; border-radius:12px; }
  .table-scroll table { min-width:780px; }
  .table-scroll::-webkit-scrollbar { height:8px; }
  .table-scroll::-webkit-scrollbar-thumb { background:#d8dce4; border-radius:4px; }
  .table-scroll::-webkit-scrollbar-track { background:transparent; }
  table {
    width:100%; border-collapse:separate; border-spacing:0;
    background:var(--card); border:1px solid var(--border); border-radius:12px; overflow:hidden;
    box-shadow:var(--shadow-sm);
  }
  th, td { padding:12px 14px; text-align:left; font-size:13px; border-bottom:1px solid var(--border); vertical-align:top; }
  th { background:#fafbfd; color:var(--muted); font-weight:600; font-size:12px; letter-spacing:0; }
  tr:last-child td { border-bottom:none; }
  tbody tr { transition:background .15s; }
  tbody tr:hover { background:#fafbff; }
  .cell-content { max-width:340px; word-break:break-word; }
  .status-select {
    border:1px solid var(--border); border-radius:999px; padding:4px 10px;
    font-size:12px; font-weight:600; background:#fff; color:var(--text-secondary);
    cursor:pointer; font-family:inherit; transition:background .18s, border-color .18s, color .18s;
  }
  .status-select:focus { outline:none; border-color:var(--accent); }
  /* 状态下拉配色对齐前台 .status 徽章 */
  .status-select[data-st="pending"]  { background:#fff4e0; color:#b45309; border-color:#fbe2bb; }
  .status-select[data-st="replied"]  { background:#e3ecff; color:#1d4ed8; border-color:#c9daff; }
  .status-select[data-st="resolved"] { background:#e0f7ee; color:#047857; border-color:#b7ebd6; }
  .status-select[data-st="planned"]  { background:#f0e9ff; color:#6d28d9; border-color:#ddd0fb; }
  .status-select[data-st="closed"]   { background:#f1f2f4; color:#6b7280; border-color:#e3e5e8; }
  /* 表格里的编号链接对齐前台 .no 样式 */
  #fb-table td a { color:var(--accent-text); text-decoration:none; font-weight:600; transition:color .15s; }
  #fb-table td a:hover { color:var(--accent-strong); text-decoration:underline; }
  #fb-table td a.cell-no {
    font-family:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
    font-size:12.5px; letter-spacing:-.2px;
  }
  .del-btn { color:#ef4444; text-decoration:none; font-size:13px; }
  .del-btn:hover { text-decoration:underline; }
  .type-badge {
    color:var(--accent-text); background:var(--accent-soft); padding:2px 10px;
    border-radius:6px; font-weight:500; font-size:12px;
  }
  .batch-bar {
    display:flex; align-items:center; gap:16px; margin-bottom:14px;
    padding:12px 16px; background:var(--card); border:1px solid var(--border);
    border-radius:12px; box-shadow:var(--shadow-sm);
  }
  .settings-card {
    background:var(--card); border:1px solid var(--border); border-radius:12px;
    padding:20px 22px; margin-bottom:18px; box-shadow:var(--shadow-sm);
    transition:box-shadow .18s, border-color .18s;
  }
  .settings-card:hover { box-shadow:var(--shadow-md); border-color:rgba(var(--accent-rgb),.22); }
  .settings-card h3 {
    display:flex; align-items:center; gap:8px;
    font-size:15px; font-weight:700; letter-spacing:-.2px;
    padding-bottom:12px; margin-bottom:6px; border-bottom:1px solid var(--border);
  }
  .settings-card h3::before { content:""; width:3px; height:14px; border-radius:3px; background:var(--accent); }
  .setting-row {
    display:flex; align-items:flex-start; justify-content:space-between;
    gap:20px; padding:12px 0; border-top:1px solid var(--border);
  }
  .setting-row:first-of-type { border-top:none; padding-top:0; }
  .setting-label { font-size:14px; font-weight:500; color:var(--text); }
  .setting-desc { font-size:12px; color:var(--muted); margin-top:3px; line-height:1.5; }
  .switch { position:relative; display:inline-block; width:44px; height:24px; flex-shrink:0; }
  .switch input { opacity:0; width:0; height:0; }
  .switch .slider {
    position:absolute; inset:0; background:#d5d8de; border-radius:999px;
    cursor:pointer; transition:background .2s;
  }
  .switch .slider::before {
    content:""; position:absolute; left:2px; top:2px; width:20px; height:20px;
    background:#fff; border-radius:50%; transition:transform .2s;
    box-shadow:0 1px 3px rgba(0,0,0,.2);
  }
  .switch input:checked + .slider { background:var(--accent); }
  .switch input:checked + .slider::before { transform:translateX(20px); }

  /* 网站 Logo 配置 */
  .logo-cfg { display:flex; gap:18px; align-items:flex-start; margin-top:14px; flex-wrap:wrap; }
  .logo-preview {
    width:96px; height:96px; flex-shrink:0; overflow:hidden;
    border:1px dashed var(--border); border-radius:12px; background:#fafbfd;
    display:flex; align-items:center; justify-content:center;
  }
  .logo-preview img { max-width:100%; max-height:100%; object-fit:contain; }
  .logo-preview .logo-ph { font-size:12px; color:var(--muted); }
  .logo-fields { flex:1; min-width:240px; max-width:640px; display:grid; gap:10px; }
  .logo-fields label { display:block; font-size:12px; font-weight:500; color:var(--text-secondary); margin-bottom:5px; }
  .logo-fields input[type=text], .logo-fields input[type=file] {
    width:100%; box-sizing:border-box; padding:8px 11px; border:1px solid var(--border);
    border-radius:8px; font-size:13px; font-family:inherit; background:#fff;
  }
  .logo-fields input[type=file] { padding:6px; color:var(--muted); font-size:12px; cursor:pointer; }
  .logo-fields input[type=file]::file-selector-button {
    margin-right:10px; padding:6px 14px; border:1px solid var(--border); border-radius:7px;
    background:#fff; color:var(--text-secondary); font-size:12px; font-weight:600;
    font-family:inherit; cursor:pointer; transition:all .18s;
  }
  .logo-fields input[type=file]::file-selector-button:hover {
    border-color:var(--accent); color:var(--accent-text); background:var(--accent-soft);
  }
  .logo-fields input:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(var(--accent-rgb),.12); }
  .logo-fields .logo-reset {
    display:flex; align-items:center; gap:6px; margin:2px 0 0;
    font-size:12px; font-weight:400; color:var(--muted); cursor:pointer;
  }
  .logo-fields .logo-reset input { accent-color:var(--accent); cursor:pointer; }
  .logo-save-row {
    display:flex; align-items:center; gap:12px; flex-wrap:wrap;
    margin-top:14px; padding-top:14px; border-top:1px solid var(--border);
  }
  .logo-save-hint { font-size:12px; color:var(--muted); }
  .logo-saved-ok {
    font-size:13px; font-weight:600; color:#047857; background:#ecfdf5;
    border:1px solid #a7f3d0; padding:5px 12px; border-radius:8px;
  }
  .logo-saved-err {
    font-size:13px; font-weight:600; color:#b91c1c; background:#fef2f2;
    border:1px solid #fecaca; padding:5px 12px; border-radius:8px;
  }

  /* 主题颜色配置 */
  #theme-color-config { scroll-margin-top:86px; }
  .theme-cfg { display:flex; gap:18px; align-items:flex-start; margin-top:14px; flex-wrap:wrap; }
  .theme-preview {
    width:230px; flex-shrink:0; padding:16px 14px; border:1px dashed var(--border);
    border-radius:12px; background:#fafbfd;
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:9px;
  }
  .theme-demo { width:100%; display:flex; align-items:center; gap:8px; flex-wrap:wrap; justify-content:center; }
  .theme-demo-btn {
    background:var(--accent); color:var(--accent-fg,#fff);
    font-size:13px; font-weight:600; padding:7px 16px; border-radius:8px;
    transition:background .2s, color .2s;
  }
  .theme-demo-chip {
    background:var(--accent-soft); color:var(--accent-text);
    font-size:11px; font-weight:600; padding:3px 10px; border-radius:999px;
    transition:background .2s, color .2s;
  }
  .theme-demo-card {
    width:100%; display:flex; align-items:center; gap:8px;
    background:#fff; border:1px solid var(--border); border-left:3px solid var(--accent);
    border-radius:8px; padding:7px 10px;
  }
  .theme-demo-no {
    font-size:11px; font-weight:700; color:var(--accent-text);
    font-family:ui-monospace,Menlo,Consolas,monospace;
  }
  .theme-demo-card-t { font-size:12px; color:var(--text-secondary); }
  .theme-demo-link {
    color:var(--accent-text); font-size:12px; font-weight:600;
    border-bottom:1px solid currentColor; transition:color .2s;
  }
  .theme-fields { flex:1; min-width:280px; max-width:640px; display:grid; gap:12px; }
  .theme-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .theme-picker-wrap {
    width:46px; height:36px; flex-shrink:0; border-radius:8px; overflow:hidden;
    border:1px solid var(--border); position:relative; background:#fff;
  }
  .theme-picker-wrap input[type=color] {
    position:absolute; inset:-25%; width:150%; height:150%;
    border:none; padding:0; background:none; cursor:pointer;
  }
  #theme_color {
    flex:1; min-width:150px; padding:8px 11px; border:1px solid var(--border);
    border-radius:8px; font-size:13px; font-family:ui-monospace,Menlo,Consolas,monospace;
    letter-spacing:.4px; background:#fff;
  }
  #theme_color:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(var(--accent-rgb),.12); }
  .theme-swatches { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .theme-sw {
    width:26px; height:26px; padding:0; border:none; border-radius:8px; cursor:pointer;
    background:var(--sw); transition:transform .16s, box-shadow .16s;
  }
  .theme-sw:hover { transform:translateY(-2px); }
  .theme-sw.active { box-shadow:0 0 0 2px #fff, 0 0 0 4px var(--sw); }
  .theme-hint { font-size:12px; color:var(--muted); min-height:18px; }
  .theme-hint.ok { color:#047857; font-weight:600; }
  .theme-hint.err { color:#b91c1c; font-weight:600; }

  /* 存储选择 */
  .storage-opts { display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
  .storage-opt {
    flex:1; min-width:150px; border:1.5px solid var(--border); border-radius:10px;
    padding:12px 14px; cursor:pointer; transition:all .15s; background:#fff;
  }
  .storage-opt:hover { border-color:rgba(var(--accent-rgb),.35); }
  .storage-opt.active { border-color:var(--accent); background:var(--accent-soft); }
  .storage-opt-title { font-size:14px; font-weight:600; color:var(--text); display:flex; align-items:center; gap:6px; }
  .storage-opt-desc { font-size:12px; color:var(--muted); margin-top:4px; line-height:1.5; }
  .db-fields { margin-top:14px; padding:14px; background:#fafbfd; border:1px solid var(--border); border-radius:10px; display:none; }
  .db-fields.show { display:block; }
  .db-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; }
  .db-field label { display:block; font-size:12px; color:var(--text-secondary); margin-bottom:5px; font-weight:500; }
  .db-field input {
    width:100%; padding:9px 12px; border:1px solid var(--border); border-radius:8px;
    font-size:13px; font-family:inherit; background:#fff;
  }
  .db-field input:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(var(--accent-rgb),.12); }
  .db-actions { display:flex; gap:10px; margin-top:12px; flex-wrap:wrap; align-items:center; }
  .btn-ghost {
    padding:8px 16px; background:#fff; border:1px solid var(--border); border-radius:8px;
    font-size:13px; cursor:pointer; color:var(--text-secondary); font-family:inherit;
  }
  .btn-ghost:hover { border-color:var(--accent); color:var(--accent-text); }
  .btn-ghost:disabled { opacity:.55; cursor:wait; }
  .db-msg { font-size:13px; margin-top:10px; padding:9px 12px; border-radius:8px; display:none; }
  .db-msg.ok { display:flex; align-items:center; gap:6px; background:#e0f7ee; color:#047857; }
  .db-msg.err { display:flex; align-items:center; gap:6px; background:#fff1f0; color:#b91c1c; }
  .select-all { font-size:13px; color:var(--text-secondary); cursor:pointer; display:flex; align-items:center; gap:6px; }
  .batch-del-btn {
    padding:7px 18px; background:#ef4444; color:#fff; border:none; border-radius:8px;
    cursor:pointer; font-size:13px; font-weight:500; transition:opacity .15s;
  }
  .batch-del-btn:disabled { background:#f3c1c4; cursor:not-allowed; }
  .batch-count { font-size:13px; color:var(--muted); margin-left:auto; }
  .row-check, #check-all, #check-all-top { cursor:pointer; accent-color:var(--accent); }
  .reply-btn { color:var(--accent-text); text-decoration:none; font-size:13px; font-weight:500; margin-right:10px; }
  .reply-btn:hover { text-decoration:underline; }
  .reply-modal { position:fixed; inset:0; background:rgba(16,24,40,.5); display:flex; align-items:center; justify-content:center; z-index:1000; backdrop-filter:blur(4px); }
  .reply-modal-box { background:#fff; border-radius:12px; width:90%; max-width:520px; box-shadow:var(--shadow-lg); }
  .reply-modal-head { display:flex; align-items:center; justify-content:space-between; padding:18px 22px; border-bottom:1px solid var(--border); font-weight:700; font-size:16px; }
  .reply-close {
    display:inline-flex; align-items:center; justify-content:center;
    width:30px; height:30px; border-radius:8px; color:var(--muted); text-decoration:none;
    transition:color .18s, background .18s, transform .2s cubic-bezier(.34,1.3,.64,1);
  }
  .reply-close:hover { color:var(--text); background:#f5f6f8; transform:scale(1.08); }
  .reply-modal-body { padding:22px; }
  .reply-info { font-size:13px; color:var(--muted); margin-bottom:14px; }
  .reply-modal-body textarea {
    width:100%; border:1px solid var(--border); border-radius:10px; padding:12px;
    font-size:14px; resize:vertical; font-family:inherit;
  }
  .reply-modal-body textarea:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(var(--accent-rgb),.12); }
  .reply-options { display:flex; gap:16px; margin:14px 0; font-size:13px; flex-wrap:wrap; }
  .reply-options label { display:flex; align-items:center; gap:5px; cursor:pointer; }
  .reply-options input { accent-color:var(--accent); }
  .reply-actions { display:flex; gap:10px; }
  .flash { padding:12px 18px; border-radius:10px; font-size:13px; margin-bottom:14px; }
  .flash-ok { background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; }
  .flash-err { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }

  /* 安全状态行（SVG 图标替代 emoji） */
  .sec-line { display:flex; align-items:flex-start; gap:6px; font-weight:600; }
  .sec-line .ico { margin-top:2px; }
  .sec-warn { color:#b91c1c; }
  .sec-ok   { color:#047857; }
  .sec-info { color:#b45309; }

  /* ==================== SVG 图标：微量动效 ==================== */
  .ico { display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
  .ico svg { display:block; }

  /* 入场：描边轻轻绘一遍（路径加 pathLength="100" 归一后比例才准） */
  @keyframes ico-draw {
    from { stroke-dasharray:100; stroke-dashoffset:100; }
    to   { stroke-dasharray:100; stroke-dashoffset:0; }
  }
  .ico-draw svg > * { animation: ico-draw .5s cubic-bezier(.4,0,.2,1) both; }
  .ico-draw svg > *:nth-child(2) { animation-delay:.09s; }
  .ico-draw svg > *:nth-child(3) { animation-delay:.16s; }

  /* 返回按钮：箭头轻推 */
  .admin-back .ico { transition: transform .2s cubic-bezier(.34,1.3,.64,1); }
  .admin-back:hover .ico { transform: translateX(-2px); }

  /* 品牌图标：hover 微倾 */
  .admin-brand-logo { transition: transform .3s cubic-bezier(.34,1.3,.64,1); }
  .admin-brand:hover .admin-brand-logo { transform: rotate(-5deg) scale(1.05); }

  /* 存储方式切换：图标底色 + 弹一下 */
  .so-ico {
    display:inline-flex; align-items:center; justify-content:center;
    width:26px; height:26px; border-radius:7px; background:#f1f3f9; color:var(--muted);
    transition: background .22s, color .22s, transform .22s;
  }
  .storage-opt:hover .so-ico { transform: translateY(-1px); }
  .storage-opt.active .so-ico { background:var(--accent); color:var(--accent-fg,#fff); }
  @keyframes ico-pop {
    0%   { transform: scale(.82); opacity:.5; }
    60%  { transform: scale(1.06); opacity:1; }
    100% { transform: scale(1); }
  }
  .storage-opt.active .so-ico svg { animation: ico-pop .34s cubic-bezier(.34,1.4,.64,1) both; }

  /* 登录按钮图标轻推 */
  .oauth-btn .ico { transition: transform .2s cubic-bezier(.34,1.3,.64,1); }
  .oauth-btn:hover .ico { transform: translateX(2px); }

  @media (prefers-reduced-motion: reduce) {
    .ico-draw svg > *, .storage-opt.active .so-ico svg { animation:none !important; }
    .admin-back .ico, .admin-brand-logo, .so-ico, .oauth-btn .ico { transition:none !important; }
    .admin-back:hover .ico, .admin-brand:hover .admin-brand-logo,
    .storage-opt:hover .so-ico, .oauth-btn:hover .ico { transform:none !important; }
  }
</style>
</head>
<body>
<div class="admin-topbar">
  <div class="admin-topbar-inner">
    <div class="admin-brand">
      <?php if ($__adminLogo !== ''): ?>
        <img class="admin-brand-logo" src="<?php echo e($__adminLogo); ?>" alt="">
      <?php else: ?>
        <span class="admin-brand-logo admin-brand-fallback ico-draw"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path pathLength="100" d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>
      <?php endif; ?>
      <div class="admin-brand-text">
        <div class="admin-brand-title">反馈管理后台</div>
        <div class="admin-brand-sub"><?php echo e(site_brand_name($settings)); ?> · 管理控制台</div>
      </div>
    </div>
    <div class="admin-actions">
      <a class="admin-back" href="/feedback.php"><span class="ico ico-draw"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path pathLength="100" d="M19 12H5"/><polyline pathLength="100" points="12 19 5 12 12 5"/></svg></span>回到反馈页</a>
      <?php if (!empty($_SESSION['yh_user']['nickname'])): ?>
        <span class="admin-user"><span class="admin-user-dot"></span><?php echo e($_SESSION['yh_user']['nickname']); ?></span>
      <?php endif; ?>
      <a class="admin-logout" href="?action=logout">退出登录</a>
    </div>
  </div>
</div>
<div class="admin-main container" style="max-width:1100px;">

  <?php if (!empty($_GET['reply_msg'])): ?>
    <div class="flash flash-ok"><?php echo e($_GET['reply_msg']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['reply_error'])): ?>
    <div class="flash flash-err"><?php echo e($_GET['reply_error']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['settings_saved'])): ?>
    <div class="flash flash-ok">设置已保存</div>
  <?php endif; ?>
  <?php if (!empty($_GET['storage_error'])): ?>
    <div class="flash flash-err"><?php echo e($_GET['storage_error']); ?></div>
  <?php endif; ?>
  <?php $__serr = storage_last_error(); if ($__serr !== ''): ?>
    <div class="flash flash-err">当前数据库不可用，系统已临时降级为 JSON 存储：<?php echo e($__serr); ?></div>
  <?php endif; ?>

  <?php
    // 当前存储配置（优先取后台保存的，其次 config.php）
    $__resolved = resolve_storage_config($config, $settings);
    $__curDriver = $__resolved['driver'];
    $__curDb = $__resolved['db'];
  ?>

  <!-- 站点设置 -->
  <div class="settings-card">
    <h3>站点设置</h3>
    <form method="post" id="settings-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save_settings">

      <!-- 网站 Logo / 站点名称 -->
      <?php
        $__logoShow = site_logo_url($settings);
        $__logoCfg  = trim((string)$settings->get('site_logo', ''));
        $__siteName = (string)$settings->get('site_name', '');
      ?>
      <div class="setting-row" id="site-logo-config" style="flex-direction:column;align-items:stretch;">
        <div>
          <div class="setting-label">网站 Logo 与站点名称</div>
          <div class="setting-desc">
            显示在页面左上角，同时作为浏览器标签图标。可上传图片（PNG / JPG / GIF / WebP，≤ 2MB），也可填外链地址；
            留空则自动使用项目根目录的 <code>logo.png</code>，都没有时用内置图标。
          </div>
        </div>
        <div class="logo-cfg">
          <div class="logo-preview" id="logo-preview">
            <?php if ($__logoShow !== ''): ?>
              <img src="<?php echo e($__logoShow); ?>" alt="Logo 预览" id="logo-preview-img">
            <?php else: ?>
              <span class="logo-ph" id="logo-preview-ph">默认图标</span>
            <?php endif; ?>
          </div>
          <div class="logo-fields">
            <div>
              <label for="site_name">站点名称</label>
              <input type="text" name="site_name" id="site_name" maxlength="30"
                     value="<?php echo e($__siteName); ?>" placeholder="AI 反馈（留空用默认）">
            </div>
            <div>
              <label for="site_logo_file">上传 Logo 图片</label>
              <input type="file" name="site_logo_file" id="site_logo_file"
                     accept="image/png,image/jpeg,image/gif,image/webp">
            </div>
            <div>
              <label for="site_logo_url">或填写图片地址</label>
              <input type="text" name="site_logo_url" id="site_logo_url"
                     value="<?php echo e($__logoCfg); ?>" placeholder="https://example.com/logo.png 或 /logo.png">
            </div>
            <label class="logo-reset">
              <input type="checkbox" name="site_logo_reset" value="1"> 清除已上传 Logo，恢复默认图标
            </label>
          </div>
        </div>
        <div class="logo-save-row">
          <button type="submit" name="save_scope" value="logo" class="act-btn primary">保存 Logo 设置</button>
          <span class="logo-save-hint">改完记得点这里保存；下方「保存设置」也会一并保存</span>
          <?php if (!empty($_GET['logo_saved'])): ?>
            <span class="logo-saved-ok">✓ 已保存</span>
          <?php endif; ?>
          <?php if (!empty($_GET['logo_error'])): ?>
            <span class="logo-saved-err"><?php echo e($_GET['logo_error']); ?></span>
          <?php endif; ?>
        </div>
      </div>

      <!-- 主题颜色 -->
      <?php
        $__themeRaw = trim((string)$settings->get('theme_color', ''));
        $__themeNorm = normalize_theme_color($__themeRaw);
        $__themeVal = ($__themeNorm === null) ? '' : $__themeNorm;
        $__themePicker = $__themeVal !== '' ? $__themeVal : '#4f6bfe';
        $__swatches = [
            '#4f6bfe' => '靛蓝（默认）',
            '#7c3aed' => '紫罗兰',
            '#0ea5e9' => '天蓝',
            '#0d9488' => '青碧',
            '#16a34a' => '翠绿',
            '#ea580c' => '橙红',
            '#e11d48' => '玫红',
            '#475569' => '石墨',
        ];
      ?>
      <div class="setting-row" id="theme-color-config" style="flex-direction:column;align-items:stretch;">
        <div>
          <div class="setting-label">主题颜色</div>
          <div class="setting-desc">
            控制按钮、链接、徽章、选中态等强调色，<b>前台与后台同步生效</b>。
            可直接选色、填色值，或点「从 Logo 取色」自动提取；留空则用默认靛蓝。
          </div>
        </div>

        <div class="theme-cfg">
          <div class="theme-preview">
            <div class="theme-demo">
              <span class="theme-demo-btn">按钮</span>
              <span class="theme-demo-chip">徽章</span>
            </div>
            <div class="theme-demo-card">
              <span class="theme-demo-no">#0001</span>
              <span class="theme-demo-card-t">反馈卡片</span>
            </div>
            <div class="theme-demo"><span class="theme-demo-link">文字链接</span></div>
          </div>

          <div class="theme-fields">
            <div class="theme-row">
              <label class="theme-picker-wrap" title="选择颜色">
                <input type="color" id="theme-picker" value="<?php echo e($__themePicker); ?>">
              </label>
              <input type="text" name="theme_color" id="theme_color" maxlength="7" spellcheck="false"
                     value="<?php echo e($__themeVal); ?>" placeholder="#4f6bfe（留空用默认）">
              <button type="button" class="btn-ghost" id="theme-pick-logo">从 Logo 取色</button>
              <button type="button" class="btn-ghost" id="theme-reset">恢复默认</button>
            </div>

            <div class="theme-swatches" id="theme-swatches">
              <?php foreach ($__swatches as $c => $name): ?>
                <button type="button" class="theme-sw<?php echo ($__themeVal === $c) ? ' active' : ''; ?>"
                        data-color="<?php echo e($c); ?>" style="--sw:<?php echo e($c); ?>"
                        title="<?php echo e($name); ?> <?php echo e($c); ?>"></button>
              <?php endforeach; ?>
            </div>

            <div class="theme-hint" id="theme-hint">改动会即时预览，记得点下方保存</div>
          </div>
        </div>

        <div class="logo-save-row">
          <button type="submit" name="save_scope" value="theme" class="act-btn primary">保存主题颜色</button>
          <span class="logo-save-hint">改完记得点这里保存；下方「保存设置」也会一并保存</span>
          <?php if (!empty($_GET['theme_saved'])): ?>
            <span class="logo-saved-ok">✓ 已保存</span>
          <?php endif; ?>
          <?php if (!empty($_GET['theme_error'])): ?>
            <span class="logo-saved-err"><?php echo e($_GET['theme_error']); ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="setting-row">
        <div>
          <div class="setting-label">短编号链接</div>
          <div class="setting-desc">
            开启后，机器人回复的反馈链接使用当月序号（如 <code>/feedback/001</code>），
            更简短好记；关闭则使用完整编号（如 <code>/feedback/202610-0001</code>，跨月唯一）。
          </div>
        </div>
        <label class="switch">
          <input type="checkbox" name="short_no_enabled" value="1" <?php echo $settings->shortNoEnabled() ? 'checked' : ''; ?>>
          <span class="slider"></span>
        </label>
      </div>

      <!-- 数据存储方式 -->
      <div class="setting-row" style="flex-direction:column;align-items:stretch;">
        <div>
          <div class="setting-label">数据存储方式</div>
          <div class="setting-desc">
            当前生效：<b style="color:var(--accent-text);"><?php
              $__err = storage_last_error();
              if ($__err !== '') {
                  echo 'JSON 文件（数据库异常已降级）';
              } else {
                  echo e(storage_driver_name($config, $settings));
              }
            ?></b>
          </div>
        </div>

        <div class="storage-opts">
          <div class="storage-opt <?php echo $__curDriver === 'json' ? 'active' : ''; ?>" data-driver="json">
            <div class="storage-opt-title"><span class="so-ico"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg></span>JSON 文件</div>
            <div class="storage-opt-desc">零配置，数据存在 data/ 目录，适合小站</div>
          </div>
          <div class="storage-opt <?php echo $__curDriver === 'mysql' ? 'active' : ''; ?>" data-driver="mysql">
            <div class="storage-opt-title"><span class="so-ico"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg></span>MySQL 数据库</div>
            <div class="storage-opt-desc">适合数据量大、多实例共用，需先建库</div>
          </div>
        </div>
        <input type="hidden" name="storage_driver" id="storage-driver" value="<?php echo e($__curDriver); ?>">

        <!-- 数据库连接信息 -->
        <div class="db-fields <?php echo $__curDriver === 'mysql' ? 'show' : ''; ?>" id="db-fields">
          <div class="db-grid">
            <div class="db-field">
              <label>主机地址</label>
              <input type="text" name="db_host" id="db_host" value="<?php echo e($__curDb['host'] ?? '127.0.0.1'); ?>" placeholder="127.0.0.1">
            </div>
            <div class="db-field">
              <label>端口</label>
              <input type="number" name="db_port" id="db_port" value="<?php echo e($__curDb['port'] ?? 3306); ?>" placeholder="3306">
            </div>
            <div class="db-field">
              <label>数据库名</label>
              <input type="text" name="db_database" id="db_database" value="<?php echo e($__curDb['database'] ?? ''); ?>" placeholder="feedback">
            </div>
            <div class="db-field">
              <label>用户名</label>
              <input type="text" name="db_username" id="db_username" value="<?php echo e($__curDb['username'] ?? ''); ?>" placeholder="feedback">
            </div>
            <div class="db-field">
              <label>密码</label>
              <input type="password" name="db_password" id="db_password" value="<?php echo e($__curDb['password'] ?? ''); ?>" placeholder="数据库密码">
            </div>
            <div class="db-field">
              <label>表前缀</label>
              <input type="text" name="db_prefix" id="db_prefix" value="<?php echo e($__curDb['prefix'] ?? 'fb_'); ?>" placeholder="fb_">
            </div>
          </div>
          <div class="db-actions">
            <button type="button" class="btn-ghost" id="btn-test-db">测试连接</button>
            <button type="button" class="btn-ghost" id="btn-migrate">导入现有 JSON 数据</button>
            <span style="font-size:12px;color:var(--muted);">表结构首次连接时自动创建</span>
          </div>
          <div class="db-msg" id="db-msg"></div>
        </div>
      </div>

      <div style="margin-top:14px;">
        <button type="submit" class="act-btn primary">保存设置</button>
      </div>
    </form>
  </div>

  <!-- 安全状态 -->
  <div class="settings-card">
    <h3>安全状态</h3>
    <div class="setting-row" style="border-top:none;padding-top:0;">
      <div>
        <div class="setting-label">数据目录访问防护</div>
        <div class="setting-desc">
          <?php if ($dataSecurity === 'exposed'): ?>
            <span class="sec-line sec-warn"><span class="ico"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path pathLength="100" d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line pathLength="100" x1="12" y1="9" x2="12" y2="13"/><line pathLength="100" x1="12" y1="17" x2="12.01" y2="17"/></svg></span><span>危险：data 目录可被公网直接下载，用户反馈与 ID 会泄露</span></span><br>
            请立即在宝塔「网站设置 → 伪静态」粘贴 nginx.conf 中的规则，并确认 data 目录下的 .htaccess 已上传。
          <?php elseif ($dataSecurity === 'protected'): ?>
            <span class="sec-line sec-ok"><span class="ico"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle pathLength="100" cx="12" cy="12" r="10"/><polyline pathLength="100" points="8 12.5 11 15.5 16.5 9"/></svg></span><span>正常：data 目录已被保护，无法通过 URL 直接访问</span></span>
          <?php else: ?>
            <span class="sec-line sec-info"><span class="ico"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle pathLength="100" cx="12" cy="12" r="10"/><line pathLength="100" x1="12" y1="11" x2="12" y2="16"/><line pathLength="100" x1="12" y1="8" x2="12.01" y2="8"/></svg></span><span>无法自动检测（服务器无法访问自身域名）</span></span><br>
            请手动在浏览器打开 <code><?php echo e(rtrim($config['site_url'], '/') . '/data/feedbacks.json'); ?></code>，
            若返回 403/404 说明安全；若显示 JSON 内容则必须立即加固。
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- 操作日志 -->
  <?php
    $__logs = $adminLog->all(50);
    $__logLabels = [
        'update_status' => ['改状态', '#f0a020'],
        'delete'        => ['删除', '#e5484d'],
        'batch_delete'  => ['批量删除', '#e5484d'],
        'reply'         => ['回复', '#2f7df6'],
        'save_settings' => ['改设置', '#8b5cf6'],
    ];
  ?>
  <div class="settings-card">
    <h3>操作日志 <span style="font-weight:400;font-size:12px;color:var(--muted);">最近 50 条</span></h3>
    <?php if (empty($__logs)): ?>
      <div style="color:var(--muted);font-size:13px;padding:8px 0;">暂无操作记录</div>
    <?php else: ?>
      <div style="max-height:360px;overflow-y:auto;">
        <table style="width:100%;border-collapse:collapse;">
          <?php foreach ($__logs as $lg): ?>
            <?php
              $la = $__logLabels[$lg['action']] ?? ['操作', '#888'];
              $detailText = '';
              if ($lg['action'] === 'update_status') {
                  $detailText = '#' . ($lg['detail']['no'] ?? '') . '：' . status_label($lg['detail']['from'] ?? '') . ' → ' . status_label($lg['detail']['to'] ?? '');
              } elseif ($lg['action'] === 'delete') {
                  $detailText = '#' . ($lg['detail']['no'] ?? '');
              } elseif ($lg['action'] === 'batch_delete') {
                  $detailText = ($lg['detail']['count'] ?? 0) . ' 条（' . ($lg['detail']['nos'] ?? '') . '）';
              } elseif ($lg['action'] === 'reply') {
                  $detailText = '#' . ($lg['detail']['no'] ?? '') . ' → ' . ($lg['detail']['sent'] ?? '');
              } elseif ($lg['action'] === 'save_settings') {
                  $detailText = '短编号=' . (($lg['detail']['short_no_enabled'] ?? false) ? '开' : '关') . '，存储=' . ($lg['detail']['storage_driver'] ?? '');
              }
            ?>
            <tr style="border-bottom:1px solid var(--border);font-size:13px;">
              <td style="padding:8px 10px;white-space:nowrap;color:var(--muted);"><?php echo e(date('m-d H:i', $lg['created_at'] ?? 0)); ?></td>
              <td style="padding:8px 10px;white-space:nowrap;"><span style="color:<?php echo $la[1]; ?>;font-weight:600;"><?php echo e($la[0]); ?></span></td>
              <td style="padding:8px 10px;"><?php echo e($detailText); ?></td>
              <td style="padding:8px 10px;white-space:nowrap;color:var(--text-secondary);"><?php echo e($lg['operator']['nickname'] ?? ''); ?></td>
              <td style="padding:8px 10px;white-space:nowrap;color:var(--muted);"><?php echo e($lg['ip'] ?? ''); ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="section-head"><h2>数据概览</h2><span>实时统计 · 共 <?php echo (int)$total; ?> 条反馈</span></div>
  <div class="stats">
    <div class="stat"><div class="num"><?php echo $total; ?></div><div class="lab">全部</div></div>
    <div class="stat"><div class="num"><?php echo $dashboard['today_new']; ?></div><div class="lab">今日新增</div></div>
    <div class="stat"><div class="num"><?php echo $dashboard['week_new']; ?></div><div class="lab">本周新增</div></div>
    <div class="stat"><div class="num"><?php echo $dashboard['rate']; ?>%</div><div class="lab">响应率</div></div>
    <div class="stat"><div class="num"><?php echo $dashboard['avg_hours'] > 0 ? $dashboard['avg_hours'] . 'h' : '—'; ?></div><div class="lab">平均响应</div></div>
    <div class="stat"><div class="num"><?php echo $dashboard['likes']; ?></div><div class="lab">总获赞</div></div>
  </div>

  <div class="section-head"><h2>状态分布</h2><span>按当前处理状态统计</span></div>
  <div class="stats stats-status">
    <div class="stat"><div class="num"><?php echo $pending; ?></div><div class="lab">待跟进</div></div>
    <div class="stat"><div class="num"><?php echo $replied; ?></div><div class="lab">已回复</div></div>
    <div class="stat"><div class="num"><?php echo $resolved; ?></div><div class="lab">已解决</div></div>
    <div class="stat"><div class="num"><?php echo $planned; ?></div><div class="lab">已加入开发计划</div></div>
    <div class="stat"><div class="num"><?php echo $closed; ?></div><div class="lab">已关闭</div></div>
    <div class="stat"><div class="num"><?php echo $dashboard['comments']; ?></div><div class="lab">总评论</div></div>
  </div>

  <div class="section-head"><h2>反馈列表</h2><span>共 <?php echo (int)$total; ?> 条 · 支持状态修改、回复与批量操作</span></div>

  <?php if (empty($list)): ?>
    <div class="empty">暂无反馈</div>
  <?php else: ?>
  <div class="batch-bar">
    <label class="select-all"><input type="checkbox" id="check-all"> 全选</label>
    <button class="batch-del-btn" id="batch-del" disabled>批量删除</button>
    <span class="batch-count" id="batch-count">已选 0 项</span>
  </div>
  <div class="table-scroll">
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
        <td><a class="cell-no" href="<?php echo $site; ?>/feedback.php?no=<?php echo e($item['no']); ?>" target="_blank"><?php echo full_no($item['no']); ?></a></td>
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
  </div>
  <?php endif; ?>
</div>

<!-- 回复弹窗 -->
<div class="reply-modal" id="reply-modal" style="display:none;">
  <div class="reply-modal-box">
    <div class="reply-modal-head">
      <span id="reply-title">回复反馈</span>
      <a href="javascript:;" class="reply-close" id="reply-close"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></a>
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
    sel.setAttribute('data-st', status);   // 配色即时跟随（对齐前台状态徽章）
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

// ===== 存储方式切换 =====
(function(){
  var opts = document.querySelectorAll('.storage-opt');
  var driverInput = document.getElementById('storage-driver');
  var dbFields = document.getElementById('db-fields');
  if (!opts.length || !driverInput) return;

  opts.forEach(function(opt){
    opt.addEventListener('click', function(){
      opts.forEach(function(o){ o.classList.remove('active'); });
      opt.classList.add('active');
      // 让图标缩放动画每次切换都重播一次（微量）
      var svg = opt.querySelector('.so-ico svg');
      if (svg) {
        svg.style.animation = 'none';
        void svg.offsetWidth;
        svg.style.animation = '';
      }
      var d = opt.getAttribute('data-driver');
      driverInput.value = d;
      if (dbFields) {
        dbFields.classList.toggle('show', d === 'mysql');
      }
    });
  });

  function dbPayload(){
    return {
      action: arguments[0],
      db_host: (document.getElementById('db_host')||{}).value || '',
      db_port: (document.getElementById('db_port')||{}).value || '3306',
      db_database: (document.getElementById('db_database')||{}).value || '',
      db_username: (document.getElementById('db_username')||{}).value || '',
      db_password: (document.getElementById('db_password')||{}).value || '',
      db_prefix: (document.getElementById('db_prefix')||{}).value || 'fb_'
    };
  }

  var ICO_OK  = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="8 12.5 11 15.5 16.5 9"/></svg>';
  var ICO_ERR = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="13"/><line x1="12" y1="16.5" x2="12.01" y2="16.5"/></svg>';

  function showMsg(ok, text){
    var box = document.getElementById('db-msg');
    if (!box) return;
    box.className = 'db-msg ' + (ok ? 'ok' : 'err');
    box.innerHTML = '<span class="ico">' + (ok ? ICO_OK : ICO_ERR) + '</span><span class="db-msg-text"></span>';
    box.querySelector('.db-msg-text').textContent = text;   // 文本走 textContent，避免注入
  }

  var btnTest = document.getElementById('btn-test-db');
  if (btnTest) {
    btnTest.addEventListener('click', function(){
      btnTest.disabled = true;
      btnTest.textContent = '测试中…';
      var data = new URLSearchParams(dbPayload('test_db'));
      fetch(window.location.pathname, { method:'POST', body: data, headers:{'Content-Type':'application/x-www-form-urlencoded'} })
        .then(function(r){ return r.json(); })
        .then(function(d){ showMsg(!!d.ok, d.message); })
        .catch(function(e){ showMsg(false, '请求失败：' + e); })
        .finally(function(){ btnTest.disabled = false; btnTest.textContent = '测试连接'; });
    });
  }

  var btnMig = document.getElementById('btn-migrate');
  if (btnMig) {
    btnMig.addEventListener('click', function(){
      if (!confirm('将把当前 JSON 数据导入到数据库（已存在的会跳过，可重复执行）。继续？')) return;
      btnMig.disabled = true;
      btnMig.textContent = '导入中…';
      var data = new URLSearchParams(dbPayload('migrate_to_db'));
      fetch(window.location.pathname, { method:'POST', body: data, headers:{'Content-Type':'application/x-www-form-urlencoded'} })
        .then(function(r){ return r.json(); })
        .then(function(d){ showMsg(!!d.ok, d.message); })
        .catch(function(e){ showMsg(false, '请求失败：' + e); })
        .finally(function(){ btnMig.disabled = false; btnMig.textContent = '导入现有 JSON 数据'; });
    });
  }
  // ---- 网站 Logo 实时预览 ----
  var logoBox = document.getElementById('logo-preview');
  if (logoBox) {
    var logoFile  = document.getElementById('site_logo_file');
    var logoUrl   = document.getElementById('site_logo_url');
    var logoReset = document.querySelector('input[name="site_logo_reset"]');

    function setLogoPreview(src) {
      var img = document.getElementById('logo-preview-img');
      var ph  = document.getElementById('logo-preview-ph');
      if (!src) {
        if (img) img.remove();
        if (!ph) {
          ph = document.createElement('span');
          ph.className = 'logo-ph'; ph.id = 'logo-preview-ph'; ph.textContent = '默认图标';
          logoBox.appendChild(ph);
        }
        return;
      }
      if (ph) ph.remove();
      if (!img) {
        img = document.createElement('img');
        img.id = 'logo-preview-img'; img.alt = 'Logo 预览';
        logoBox.appendChild(img);
      }
      img.src = src;
    }

    if (logoFile) logoFile.addEventListener('change', function(){
      var f = this.files && this.files[0];
      if (!f) return;
      var reader = new FileReader();
      reader.onload = function(ev){ setLogoPreview(ev.target.result); };
      reader.readAsDataURL(f);
    });
    if (logoUrl) logoUrl.addEventListener('input', function(){
      var v = this.value.trim();
      if (/^https?:\/\/\S+$/i.test(v) || /^\/[^\/]/.test(v)) setLogoPreview(v);
    });
    if (logoReset) logoReset.addEventListener('change', function(){
      if (this.checked) setLogoPreview('');
    });
  }
})();

// ===== 主题颜色：实时预览 + 从 Logo 动态取色 =====
(function(){
  var picker   = document.getElementById('theme-picker');
  var hexIn    = document.getElementById('theme_color');
  var hint     = document.getElementById('theme-hint');
  var resetBtn = document.getElementById('theme-reset');
  var pickBtn  = document.getElementById('theme-pick-logo');
  var logoFile = document.getElementById('site_logo_file');
  if (!picker || !hexIn || !hint) return;

  var root = document.documentElement;
  var DEFAULT = '#4f6bfe';
  var VARS = ['--accent', '--accent-strong', '--accent-soft', '--accent-rgb', '--accent-fg', '--accent-text'];

  function normHex(v) {
    v = String(v || '').trim();
    if (!v) return '';
    if (v.charAt(0) !== '#') v = '#' + v;
    if (/^#[0-9a-fA-F]{3}$/.test(v)) v = '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
    return /^#[0-9a-fA-F]{6}$/.test(v) ? v.toLowerCase() : null;
  }
  function hex2rgb(h) {
    h = h.replace('#', '');
    return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)];
  }
  function clamp(n) { return Math.max(0, Math.min(255, Math.round(n))); }
  function toHex(a) {
    return '#' + a.map(function(v){ var s = clamp(v).toString(16); return s.length < 2 ? '0' + s : s; }).join('');
  }
  function mix(rgb, t, r) {
    return [clamp(rgb[0] + (t[0] - rgb[0]) * r), clamp(rgb[1] + (t[1] - rgb[1]) * r), clamp(rgb[2] + (t[2] - rgb[2]) * r)];
  }
  function luma(a) { return (0.299 * a[0] + 0.587 * a[1] + 0.114 * a[2]) / 255; }

  function apply(color) {
    var rgb = hex2rgb(color);
    var light = luma(rgb) > 0.62;
    root.style.setProperty('--accent', color);
    root.style.setProperty('--accent-strong', toHex(mix(rgb, [0, 0, 0], light ? 0.22 : 0.14)));
    root.style.setProperty('--accent-soft', toHex(mix(rgb, [255, 255, 255], light ? 0.84 : 0.92)));
    root.style.setProperty('--accent-rgb', rgb.join(', '));
    root.style.setProperty('--accent-fg', light ? '#1f2329' : '#ffffff');
    root.style.setProperty('--accent-text', toHex(luma(rgb) > 0.5 ? mix(rgb, [0, 0, 0], 0.55) : rgb));
  }
  function clearVars() {
    VARS.forEach(function(k){ root.style.removeProperty(k); });
  }
  function setHint(text, type) {
    hint.textContent = text;
    hint.className = 'theme-hint' + (type ? ' ' + type : '');
  }
  function markSwatch(color) {
    var all = document.querySelectorAll('.theme-sw');
    for (var i = 0; i < all.length; i++) {
      all[i].classList.toggle('active', all[i].getAttribute('data-color') === color);
    }
  }
  function render(color, opts) {
    opts = opts || {};
    if (opts.picker) picker.value = color;
    if (opts.input !== false) hexIn.value = color;
    apply(color);
    markSwatch(color);
  }
  function useDefault(showHint) {
    clearVars();
    picker.value = DEFAULT;
    hexIn.value = '';
    markSwatch('');
    if (showHint) setHint('已恢复默认 #4F6BFE，记得点保存', 'ok');
  }

  markSwatch(normHex(hexIn.value) || '');

  picker.addEventListener('input', function(){
    var c = this.value.toLowerCase();
    render(c, { picker: true });
    setHint('预览中 · ' + c.toUpperCase() + '，记得点保存');
  });

  hexIn.addEventListener('input', function(){
    var v = this.value.trim();
    if (v === '') { useDefault(false); setHint('已清空 = 使用默认颜色，记得点保存'); return; }
    var c = normHex(v);
    if (!c) { setHint('格式不对：需要 #RRGGBB 或 #RGB', 'err'); return; }
    render(c, { picker: true, input: false });
    setHint('预览中 · ' + c.toUpperCase() + '，记得点保存');
  });
  hexIn.addEventListener('blur', function(){
    var c = normHex(this.value);
    if (c) { this.value = c; render(c, { picker: true, input: false }); }
  });

  document.querySelectorAll('.theme-sw').forEach(function(sw){
    sw.addEventListener('click', function(){
      var c = this.getAttribute('data-color');
      render(c, { picker: true });
      setHint('已选择 ' + c.toUpperCase() + '，记得点保存');
    });
  });

  if (resetBtn) resetBtn.addEventListener('click', function(){ useDefault(true); });

  /* 取主色：量化统计 + 按「数量 × 饱和度」加权，跳过近白近黑与灰阶 */
  function dominantColor(img) {
    try {
      var n = 64;
      var cv = document.createElement('canvas');
      cv.width = n; cv.height = n;
      var ctx = cv.getContext('2d');
      ctx.drawImage(img, 0, 0, n, n);
      var data = ctx.getImageData(0, 0, n, n).data;
      var buckets = {}, best = null, bestScore = 0;
      for (var i = 0; i < data.length; i += 4) {
        if (data[i + 3] < 200) continue;
        var r = data[i], g = data[i + 1], b = data[i + 2];
        var mx = Math.max(r, g, b), mn = Math.min(r, g, b);
        if (mn > 242) continue;              // 近白
        if (mx < 34) continue;               // 近黑
        var sat = mx === 0 ? 0 : (mx - mn) / mx;
        if (sat < 0.12) continue;            // 灰阶
        var k = (r >> 4) + '_' + (g >> 4) + '_' + (b >> 4);
        var o = buckets[k] || (buckets[k] = { n: 0, r: 0, g: 0, b: 0, sat: 0 });
        o.n++; o.r += r; o.g += g; o.b += b; o.sat += sat;
      }
      Object.keys(buckets).forEach(function(k){
        var o = buckets[k];
        var score = Math.log(o.n + 1) * (1 + (o.sat / o.n) * 1.3);
        if (score > bestScore) {
          bestScore = score;
          best = toHex([o.r / o.n, o.g / o.n, o.b / o.n]);
        }
      });
      return best;
    } catch (e) {
      return 'ERR';   // 跨域图片会污染 canvas
    }
  }

  function handlePick(img, fromUpload) {
    var color = dominantColor(img);
    if (color === 'ERR') {
      setHint('这张图来自外部链接，浏览器不允许读取像素；请把图片上传到本站再取色', 'err');
      return;
    }
    if (!color) {
      setHint('没从图片里找到合适的颜色（可能接近纯白/纯灰），请手动选色', 'err');
      return;
    }
    render(color, { picker: true });
    setHint((fromUpload ? '已从新上传的 Logo 自动取色 ' : '已从 Logo 提取颜色 ') + color.toUpperCase() + '，记得点保存', 'ok');
  }

  if (pickBtn) pickBtn.addEventListener('click', function(){
    var img = document.getElementById('logo-preview-img') || document.querySelector('.admin-brand-logo');
    if (img && img.tagName === 'IMG') {
      if (!img.complete) {
        setHint('正在读取 Logo…');
        img.addEventListener('load', function(){ handlePick(img, false); }, { once: true });
      } else {
        handlePick(img, false);
      }
    } else {
      setHint('还没有 Logo，请先上传一张图片，或直接选颜色', 'err');
    }
  });

  // 上传新 Logo 时自动取色（动态取色）
  if (logoFile) logoFile.addEventListener('change', function(){
    var f = this.files && this.files[0];
    if (!f) return;
    var reader = new FileReader();
    reader.onload = function(ev){
      var img = new Image();
      img.onload = function(){ handlePick(img, true); };
      img.src = ev.target.result;
    };
    reader.readAsDataURL(f);
  });
})();
</script>
</body>
</html>
