<?php
/**
 * 云湖反馈机器人 —— 订阅回调入口
 *
 * 该文件填写到云湖机器人控制台的「配置消息订阅接口」中。
 * 处理逻辑：
 *   1. 接收云湖推送的事件（POST JSON）
 *   2. 识别「指令消息事件」(message.receive.instruction)，指令名为 2713 时记录反馈
 *   3. 识别「普通消息事件」(message.receive.normal)，当 @机器人且带文本时也记录反馈
 *   4. 生成编号、存储 JSON、调用云湖接口回复确认文本
 */

declare(strict_types=1);

require __DIR__ . '/inc/FeedbackStore.php';
require __DIR__ . '/inc/store.php';
require __DIR__ . '/inc/Settings.php';
require __DIR__ . '/inc/YunhuBot.php';
require __DIR__ . '/inc/functions.php';

$config = require __DIR__ . '/config.php';

// 日志（调试用，可注释关闭）
function log_debug($msg)
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($dir . '/webhook.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/**
 * 发送消息，失败时自动重试（最多 3 次，指数退避）
 * 全部失败则记入 fail_notify.log
 */
function send_with_retry($bot, $recvId, $recvType, $text, $parentId = '', $refNo = '')
{
    $maxTry = 3;
    for ($i = 1; $i <= $maxTry; $i++) {
        $r = $bot->sendText($recvId, $recvType, $text, $parentId);
        if (($r['code'] ?? 0) == 1) {
            if ($i > 1) {
                log_debug("notify retry success ({$i}) ref={$refNo}");
            }
            return true;
        }
        $msg = $r['msg'] ?? 'unknown';
        log_debug("notify attempt {$i} failed ref={$refNo}: {$msg}");
        if ($i < $maxTry) {
            usleep(200000 * $i); // 200ms / 400ms 退避
        }
    }
    // 最终失败，记录到独立日志便于排查
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents(
        $dir . '/fail_notify.log',
        '[' . date('Y-m-d H:i:s') . "] 发送失败 ref={$refNo} recvType={$recvType} recvId={$recvId} text=" . mb_substr($text, 0, 100) . "\n",
        FILE_APPEND
    );
    return false;
}

// 读取请求体
$rawBody = file_get_contents('php://input');
$event = json_decode($rawBody, true);

// 非 JSON 或缺少 header，直接返回 200（避免云湖重试堆积）
if (!is_array($event) || !isset($event['header']['eventType'])) {
    http_response_code(200);
    echo 'ok';
    exit;
}

$eventType = $event['header']['eventType'];

// 记录所有收到的原始事件（调试用，确认指令事件的实际结构）
log_debug('EVENT_TYPE=' . $eventType . ' RAW=' . $rawBody);

$settings = new Settings($config['data_dir']);
$store = create_store($config, $settings);
$bot = new YunhuBot($config['bot_token']);
$siteUrl = rtrim($config['site_url'], '/');
$commandId = (string)($config['command_id'] ?? '');
$commandTrigger = $config['command_trigger'] ?? '';
$botId = (string)($config['bot_id'] ?? '');

// 判断是否是反馈触发
$feedbackContent = '';
$feedbackMeta = [];

switch ($eventType) {

    // 一、指令消息事件：用户发送指令触发
    case 'message.receive.instruction':
        $ev = $event['event'] ?? [];
        $msg = $ev['message'] ?? [];
        $instructionName = $msg['instructionName'] ?? ($msg['commandName'] ?? '');
        $instructionId   = $msg['instructionId'] ?? ($msg['commandId'] ?? 0);

        // 仅处理目标指令：优先匹配指令ID，其次匹配指令名/触发词
        $isTarget = false;
        if ($commandId !== '') {
            $isTarget = ((string)$instructionId === $commandId);
        }
        if (!$isTarget) {
            $isTarget = ((string)$instructionName === $commandTrigger)
                     || ((string)$instructionName === '/' . ltrim($commandTrigger, '/'))
                     || ($commandTrigger !== '' && (string)$instructionName === ltrim($commandTrigger, '/'));
        }
        if (!$isTarget) {
            break;
        }

        $rawText = $msg['content']['text'] ?? '';
        // 用触发词（或指令名）剥离前缀，提取反馈内容
        $trigger = $commandTrigger !== '' ? ltrim($commandTrigger, '/') : $instructionName;
        $feedbackContent = extract_feedback_content($rawText, $trigger);
        $feedbackMeta = [
            'senderNickname' => $ev['sender']['senderNickname'] ?? '',
            'senderId'       => $ev['sender']['senderId'] ?? '',
            'senderType'     => $ev['sender']['senderType'] ?? 'user',
            'chatType'       => $ev['chat']['chatType'] ?? 'bot',
            'chatId'         => $ev['chat']['chatId'] ?? '',
        ];
        break;

    // 二、普通消息事件：@机器人 + 内容
    case 'message.receive.normal':
        $ev = $event['event'] ?? [];
        $msg = $ev['message'] ?? [];

        // 仅处理文本消息
        if (($msg['contentType'] ?? '') !== 'text') {
            break;
        }

        $content = $msg['content'] ?? [];
        $text = trim($content['text'] ?? '');
        $atList = $content['at'] ?? [];

        // 判断是否 @了机器人：私聊一律算反馈；群聊需精确判断 at 列表里包含机器人 ID
        $chatType = $ev['chat']['chatType'] ?? 'bot';

        if ($chatType === 'bot') {
            // 私聊给机器人：直接作为反馈
            if ($text === '') {
                break;
            }
            $feedbackContent = $text;
            $feedbackMeta = [
                'senderNickname' => $ev['sender']['senderNickname'] ?? '',
                'senderId'       => $ev['sender']['senderId'] ?? '',
                'senderType'     => $ev['sender']['senderType'] ?? 'user',
                'chatType'       => 'bot',
                'chatId'         => $ev['chat']['chatId'] ?? '',
            ];
        } else {
            // 群聊：需精确 @机器人（at 列表包含机器人 ID）
            $isAtBot = false;
            if (is_array($atList) && $botId !== '') {
                foreach ($atList as $atId) {
                    if ((string)$atId === $botId) {
                        $isAtBot = true;
                        break;
                    }
                }
            }
            if (!$isAtBot) {
                break;
            }
            $text = trim($text);
            // 去掉文本里的 @机器人昵称 部分（若云湖把 @昵称 放在 text 里）
            if (preg_match('/^@\S+\s*/u', $text)) {
                $text = preg_replace('/^@\S+\s*/u', '', $text);
            }
            // @机器人后没有实质内容则不处理
            if ($text === '') {
                break;
            }
            $feedbackContent = $text;
            $feedbackMeta = [
                'senderNickname' => $ev['sender']['senderNickname'] ?? '',
                'senderId'       => $ev['sender']['senderId'] ?? '',
                'senderType'     => $ev['sender']['senderType'] ?? 'user',
                'chatType'       => 'group',
                'chatId'         => $ev['chat']['chatId'] ?? '',
            ];
        }
        break;
}

// 未识别到反馈内容，返回 ok
if ($feedbackContent === '') {
    // 若是目标指令但没带内容，提示用户
    if ($eventType === 'message.receive.instruction') {
        $ev = $event['event'] ?? [];
        $msg = $ev['message'] ?? [];
        $instructionName = $msg['instructionName'] ?? '';
        $instructionId   = $msg['instructionId'] ?? 0;
        $isTarget = ($commandId !== '' && (string)$instructionId === $commandId)
                 || ($commandTrigger !== '' && ((string)$instructionName === $commandTrigger || (string)$instructionName === ltrim($commandTrigger, '/')));
        if ($isTarget) {
            $recvId = $ev['chat']['chatId'] ?? '';
            $recvType = ($ev['chat']['chatType'] ?? 'bot') === 'group' ? 'group' : 'user';
            if ($recvId) {
                $bot->sendText($recvId, $recvType, '请在指令后输入反馈内容，例如：' . $commandTrigger . ' 网页版闪退');
            }
        }
    }
    http_response_code(200);
    echo 'ok';
    exit;
}

// ===== 内容校验：长度限制 + 空内容 =====
$MAX_LEN = 2000;
$feedbackContent = trim($feedbackContent);
if ($feedbackContent === '') {
    // 空内容，提示用户
    $recvId = $feedbackMeta['chatId'] ?? '';
    $recvType = ($feedbackMeta['chatType'] ?? 'bot') === 'group' ? 'group' : 'user';
    if ($recvId) {
        $bot->sendText($recvId, $recvType, "⚠️ 反馈内容不能为空\n请发送：" . ($commandTrigger ?: '反馈') . " 你的问题描述");
    }
    http_response_code(200);
    echo 'ok';
    exit;
}
$truncated = false;
if (mb_strlen($feedbackContent) > $MAX_LEN) {
    $feedbackContent = mb_substr($feedbackContent, 0, $MAX_LEN);
    $truncated = true;
}

// 记录反馈
$record = $store->add(array_merge(['content' => $feedbackContent], $feedbackMeta));
$no = full_no($record['no']);

// 处理记录：提交反馈（时间线起点）
$store->addProcessLog($record['no'], 'submit', [
    'content' => $feedbackContent,
], [
    'user_id'  => $feedbackMeta['senderId'] ?? '',
    'nickname' => $feedbackMeta['senderNickname'] ?? '',
]);

// 写入失败检测：save 落盘失败时，提示用户而不是谎报"已记录"
$saveFailed = false;
if (method_exists($store, 'lastSaveOk') && !$store->lastSaveOk()) {
    $saveFailed = true;
}

// 生成详情链接：后台开启短编号时用短编号（如 001），否则用完整编号
$linkNo = $settings->shortNoEnabled() ? $store->shortNo($record['no']) : $record['no'];
$detailUrl = $siteUrl . '/feedback/' . $linkNo;

// 构造回复文本
if ($saveFailed) {
    $replyText = "⚠️ 反馈提交失败\n"
        . "数据未能保存，请联系管理员检查服务器 data 目录写入权限。\n"
        . "（错误已记录到 data/store_error.log）";
} else {
    $replyText = "✅ 反馈已记录\n"
        . "编号：{$no}\n"
        . "内容：{$feedbackContent}\n"
        . ($truncated ? "（内容过长已截断至 {$MAX_LEN} 字）\n" : '')
        . "状态：待跟进\n"
        . "查看：{$detailUrl}";
}

// 回复给用户（带失败重试）
$recvId = $feedbackMeta['chatId'] ?? '';
$recvType = ($feedbackMeta['chatType'] ?? 'bot') === 'group' ? 'group' : 'user';
if ($recvId) {
    $parentId = $event['event']['message']['msgId'] ?? '';
    send_with_retry($bot, $recvId, $recvType, $replyText, $parentId, $record['no']);
}

log_debug("new feedback {$record['no']} from {$feedbackMeta['senderNickname']}");

http_response_code(200);
echo 'ok';
