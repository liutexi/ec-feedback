<?php
/**
 * JSON -> MySQL 数据迁移工具
 *
 * 用法：
 *   1. 在 config.php 里把 storage.db 填好（driver 可保持 json）
 *   2. 浏览器访问 /migrate.php ，或命令行 php migrate.php
 *   3. 迁移完成后把 driver 改成 'mysql'
 *
 * 安全性：迁移完成后建议删除本文件。
 */

require __DIR__ . '/inc/FeedbackStore.php';
require __DIR__ . '/inc/FeedbackStoreDb.php';
require __DIR__ . '/inc/functions.php';

$config = require __DIR__ . '/config.php';

$isCli = (PHP_SAPI === 'cli');
$db = $config['storage']['db'] ?? [];

// 迁移前先确认数据库能连上
try {
    $jsonStore = new FeedbackStore($config['data_dir']);
    $dbStore = new FeedbackStoreDb($db);
} catch (Exception $e) {
    $msg = '❌ 数据库连接失败：' . $e->getMessage() . "\n请检查 config.php 的 storage.db 配置（host/port/database/username/password）。";
    if ($isCli) {
        echo $msg . "\n";
    } else {
        echo '<pre style="font-family:monospace;padding:20px;">' . htmlspecialchars($msg) . '</pre>';
    }
    exit(1);
}

$report = [];
$pdo = $dbStore->pdo();
$prefix = $dbStore->prefix();

// ===== 1. 迁移反馈 =====
$feedbacks = $jsonStore->all();
$fbOk = 0; $fbSkip = 0;
foreach ($feedbacks as $f) {
    if ($dbStore->find($f['no'])) {
        $fbSkip++;
        continue;
    }
    // 直接用底层插入，保证 id 与原编号一致
    $stmt = $pdo->prepare("INSERT INTO `{$prefix}feedbacks`
        (`no`,`content`,`status`,`type`,`priority`,`senderNickname`,`senderId`,`senderType`,
         `owner_user_id`,`chatType`,`chatId`,`likes`,`liked_by`,`replies`,`created_at`,`updated_at`)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $f['no'],
        $f['content'] ?? '',
        $f['status'] ?? 'pending',
        $f['type'] ?? 'other',
        $f['priority'] ?? 'normal',
        $f['senderNickname'] ?? '',
        $f['senderId'] ?? '',
        $f['senderType'] ?? 'user',
        $f['owner_user_id'] ?? ($f['senderId'] ?? ''),
        $f['chatType'] ?? 'bot',
        $f['chatId'] ?? '',
        (int)($f['likes'] ?? 0),
        json_encode($f['liked_by'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($f['replies'] ?? [], JSON_UNESCAPED_UNICODE),
        (int)($f['created_at'] ?? 0),
        (int)($f['updated_at'] ?? 0),
    ]);
    $fbOk++;
}
$report[] = "反馈：导入 {$fbOk} 条，跳过 {$fbSkip} 条（已存在）";

// ===== 2. 迁移评论 =====
$commentsFile = rtrim($config['data_dir'], '/\\') . '/comments.json';
$comments = [];
if (file_exists($commentsFile)) {
    $raw = file_get_contents($commentsFile);
    $arr = json_decode($raw, true);
    if (is_array($arr)) {
        $comments = $arr;
    }
}
$cOk = 0; $cSkip = 0;
foreach ($comments as $c) {
    if ($dbStore->findComment($c['id'])) {
        $cSkip++;
        continue;
    }
    $stmt = $pdo->prepare("INSERT INTO `{$prefix}comments`
        (`id`,`feedback_no`,`user_id`,`nickname`,`avatar_url`,`content`,`created_at`)
        VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([
        $c['id'],
        $c['feedback_no'] ?? '',
        $c['user_id'] ?? '',
        $c['nickname'] ?? '',
        $c['avatar_url'] ?? '',
        $c['content'] ?? '',
        (int)($c['created_at'] ?? 0),
    ]);
    $cOk++;
}
$report[] = "评论：导入 {$cOk} 条，跳过 {$cSkip} 条";

// ===== 3. 迁移历史 =====
$historyFile = rtrim($config['data_dir'], '/\\') . '/history.json';
$history = [];
if (file_exists($historyFile)) {
    $raw = file_get_contents($historyFile);
    $arr = json_decode($raw, true);
    if (is_array($arr)) {
        $history = $arr;
    }
}
$hOk = 0; $hSkip = 0;
foreach ($history as $h) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}history` WHERE `id` = ?");
    $stmt->execute([$h['id'] ?? '']);
    if ((int)$stmt->fetchColumn() > 0) {
        $hSkip++;
        continue;
    }
    $stmt = $pdo->prepare("INSERT INTO `{$prefix}history`
        (`id`,`feedback_no`,`old_content`,`new_content`,`user_id`,`nickname`,`created_at`)
        VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([
        $h['id'] ?? uniqid('h', true),
        $h['feedback_no'] ?? '',
        $h['old_content'] ?? '',
        $h['new_content'] ?? '',
        $h['user_id'] ?? '',
        $h['nickname'] ?? '',
        (int)($h['created_at'] ?? 0),
    ]);
    $hOk++;
}
$report[] = "修改历史：导入 {$hOk} 条，跳过 {$hSkip} 条";

// ===== 输出结果 =====
$report[] = '';
$report[] = '✅ 迁移完成。请把 config.php 里的 ';
$report[] = "   'driver' => 'json'   改成   'driver' => 'mysql'";
$report[] = '然后删除本文件 migrate.php。';

$out = implode("\n", $report);

if ($isCli) {
    echo $out . "\n";
} else {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>数据迁移</title></head>'
        . '<body style="font-family:-apple-system,sans-serif;max-width:720px;margin:40px auto;padding:0 20px;">'
        . '<h2>JSON → MySQL 数据迁移</h2>'
        . '<pre style="background:#f5f6f8;border:1px solid #e8eaee;border-radius:10px;padding:20px;white-space:pre-wrap;line-height:1.8;">'
        . htmlspecialchars($out)
        . '</pre></body></html>';
}
