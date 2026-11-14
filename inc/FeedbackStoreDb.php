<?php
/**
 * 反馈数据存储类（MySQL 版）
 * 与 FeedbackStore（JSON 版）方法签名、返回结构、行为完全一致，可直接替换。
 */

class FeedbackStoreDb
{
    /** @var PDO */
    private $pdo;

    /** @var string 表前缀 */
    private $prefix;

    public function __construct(array $db)
    {
        $this->prefix = $db['prefix'] ?? 'fb_';
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'] ?? '127.0.0.1',
            (int)($db['port'] ?? 3306),
            $db['database'] ?? '',
            $db['charset'] ?? 'utf8mb4'
        );
        $this->pdo = new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $this->ensureTables();
    }

    /**
     * 首次运行时自动建表
     */
    private function ensureTables()
    {
        $p = $this->prefix;
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}feedbacks` (
            `no` VARCHAR(20) NOT NULL PRIMARY KEY,
            `content` MEDIUMTEXT,
            `status` VARCHAR(20) DEFAULT 'pending',
            `type` VARCHAR(20) DEFAULT 'other',
            `priority` VARCHAR(20) DEFAULT 'normal',
            `senderNickname` VARCHAR(255) DEFAULT '',
            `senderId` VARCHAR(64) DEFAULT '',
            `senderType` VARCHAR(20) DEFAULT 'user',
            `owner_user_id` VARCHAR(64) DEFAULT '',
            `chatType` VARCHAR(20) DEFAULT 'bot',
            `chatId` VARCHAR(64) DEFAULT '',
            `likes` INT DEFAULT 0,
            `liked_by` MEDIUMTEXT,
            `replies` MEDIUMTEXT,
            `created_at` BIGINT DEFAULT 0,
            `updated_at` BIGINT DEFAULT 0,
            KEY `idx_owner` (`owner_user_id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}comments` (
            `id` VARCHAR(64) NOT NULL PRIMARY KEY,
            `feedback_no` VARCHAR(20) NOT NULL,
            `user_id` VARCHAR(64) DEFAULT '',
            `nickname` VARCHAR(255) DEFAULT '',
            `avatar_url` VARCHAR(512) DEFAULT '',
            `content` MEDIUMTEXT,
            `created_at` BIGINT DEFAULT 0,
            KEY `idx_fb` (`feedback_no`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}history` (
            `id` VARCHAR(64) NOT NULL PRIMARY KEY,
            `feedback_no` VARCHAR(20) NOT NULL,
            `old_content` MEDIUMTEXT,
            `new_content` MEDIUMTEXT,
            `user_id` VARCHAR(64) DEFAULT '',
            `nickname` VARCHAR(255) DEFAULT '',
            `created_at` BIGINT DEFAULT 0,
            KEY `idx_fb` (`feedback_no`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}process_log` (
            `id` VARCHAR(64) NOT NULL PRIMARY KEY,
            `feedback_no` VARCHAR(20) NOT NULL,
            `action` VARCHAR(20) NOT NULL,
            `detail` MEDIUMTEXT,
            `user_id` VARCHAR(64) DEFAULT '',
            `nickname` VARCHAR(255) DEFAULT '',
            `created_at` BIGINT DEFAULT 0,
            KEY `idx_fb` (`feedback_no`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /* ==================== 内部工具 ==================== */

    /** JSON 字符串解码成数组 */
    private function decodeArr($raw)
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    /** 数组编码成 JSON 字符串 */
    private function encodeArr($arr)
    {
        return json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 数据库行 -> 标准记录（还原数组字段、转换类型） */
    private function rowToRecord(array $row)
    {
        if (!$row) {
            return null;
        }
        $row['likes'] = (int)($row['likes'] ?? 0);
        $row['created_at'] = (int)($row['created_at'] ?? 0);
        $row['updated_at'] = (int)($row['updated_at'] ?? 0);
        $row['liked_by'] = $this->decodeArr($row['liked_by'] ?? '');
        if (array_key_exists('replies', $row)) {
            $row['replies'] = $this->decodeArr($row['replies'] ?? '');
        }
        return $row;
    }

    /* ==================== 反馈 CRUD ==================== */

    /**
     * 生成反馈编号（格式 202610-0001）
     */
    public function generateId($timestamp = null)
    {
        $ts = $timestamp ?: time();
        $ym = date('Ym', $ts);
        $stmt = $this->pdo->prepare(
            "SELECT `no` FROM `{$this->prefix}feedbacks` WHERE `no` LIKE :p ORDER BY `no` DESC LIMIT 1"
        );
        $stmt->execute([':p' => $ym . '-%']);
        $last = $stmt->fetchColumn();
        $seq = 1;
        if ($last) {
            $parts = explode('-', $last);
            $seq = (int)($parts[1] ?? 0) + 1;
        }
        return sprintf('%s-%04d', $ym, $seq);
    }

    /**
     * 新增一条反馈
     */
    public function add(array $feedback)
    {
        $now = time();
        $no = $this->generateId($now);

        $record = [
            'no'             => $no,
            'content'        => isset($feedback['content']) ? trim($feedback['content']) : '',
            'status'         => 'pending',
            'type'           => $feedback['type'] ?? 'other',
            'priority'       => $feedback['priority'] ?? 'normal',
            'senderNickname' => $feedback['senderNickname'] ?? '',
            'senderId'       => $feedback['senderId'] ?? '',
            'senderType'     => $feedback['senderType'] ?? 'user',
            'owner_user_id'  => $feedback['owner_user_id'] ?? ($feedback['senderId'] ?? ''),
            'chatType'       => $feedback['chatType'] ?? 'bot',
            'chatId'         => $feedback['chatId'] ?? '',
            'likes'          => 0,
            'liked_by'       => [],
            'replies'        => [],
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        $stmt = $this->pdo->prepare("INSERT INTO `{$this->prefix}feedbacks`
            (`no`,`content`,`status`,`type`,`priority`,`senderNickname`,`senderId`,`senderType`,
             `owner_user_id`,`chatType`,`chatId`,`likes`,`liked_by`,`replies`,`created_at`,`updated_at`)
            VALUES (:no,:content,:status,:type,:priority,:sn,:sid,:st,:owner,:ct,:cid,:likes,:lb,:rp,:ca,:ua)");
        $stmt->execute([
            ':no'      => $record['no'],
            ':content' => $record['content'],
            ':status'  => $record['status'],
            ':type'    => $record['type'],
            ':priority'=> $record['priority'],
            ':sn'      => $record['senderNickname'],
            ':sid'     => $record['senderId'],
            ':st'      => $record['senderType'],
            ':owner'   => $record['owner_user_id'],
            ':ct'      => $record['chatType'],
            ':cid'     => $record['chatId'],
            ':likes'   => 0,
            ':lb'      => '[]',
            ':rp'      => '[]',
            ':ca'      => $now,
            ':ua'      => $now,
        ]);
        return $record;
    }

    /**
     * 根据编号查找反馈
     */
    public function find($no)
    {
        $no = ltrim((string)$no, '#');
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->prefix}feedbacks` WHERE `no` = ? LIMIT 1");
        $stmt->execute([$no]);
        return $this->rowToRecord($stmt->fetch());
    }

    /**
     * 按短编号查找（当月优先，跨月重名则仅唯一时返回）
     */
    public function findByShortNo($short)
    {
        $short = preg_replace('/\D/', '', (string)$short);
        if ($short === '') {
            return null;
        }
        $seq = ltrim($short, '0');
        if ($seq === '') {
            $seq = '0';
        }
        $curYm = date('Ym');

        // 当月优先：no LIKE '202610-<seq补零>'
        $padded = sprintf('%04d', (int)$seq);
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `{$this->prefix}feedbacks` WHERE `no` = ? LIMIT 1"
        );
        $stmt->execute([$curYm . '-' . $padded]);
        $hit = $stmt->fetch();
        if ($hit) {
            return $this->rowToRecord($hit);
        }

        // 跨月：用 SUBSTRING_INDEX 精确取 "-" 后的序号比较，仅唯一时返回
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `{$this->prefix}feedbacks`
             WHERE SUBSTRING_INDEX(`no`, '-', -1) = ?
             ORDER BY `created_at` DESC LIMIT 2"
        );
        $stmt->execute([$padded]);
        $rows = $stmt->fetchAll();
        if (count($rows) === 1) {
            return $this->rowToRecord($rows[0]);
        }
        return null;
    }

    /**
     * 生成短编号
     */
    public function shortNo($no)
    {
        $parts = explode('-', ltrim((string)$no, '#'));
        return count($parts) === 2 ? $parts[1] : $no;
    }

    /**
     * 获取全部反馈（创建时间倒序）
     */
    public function all()
    {
        $stmt = $this->pdo->query("SELECT * FROM `{$this->prefix}feedbacks` ORDER BY `created_at` DESC");
        return array_map([$this, 'rowToRecord'], $stmt->fetchAll());
    }

    /**
     * 获取全部反馈（热度倒序）
     */
    public function allByHot()
    {
        $data = $this->all();
        usort($data, function ($a, $b) {
            $ha = $this->heat($a);
            $hb = $this->heat($b);
            if ($hb !== $ha) {
                return $hb <=> $ha;
            }
            return $b['created_at'] <=> $a['created_at'];
        });
        return $data;
    }

    /**
     * 单条反馈热度 = 点赞 + 评论数
     */
    public function heat(array $item)
    {
        return (int)($item['likes'] ?? 0) + $this->commentCount($item['no'] ?? '');
    }

    /**
     * 更新反馈（部分字段）
     */
    public function update($no, array $fields, $operator = null)
    {
        $no = ltrim((string)$no, '#');
        $item = $this->find($no);
        if (!$item) {
            return null;
        }
        $oldContent = $item['content'] ?? '';

        // 允许更新的字段白名单（防止注入非法列名 / 未知字段报错）
        static $allowedCols = [
            'content', 'status', 'type', 'priority', 'senderNickname', 'senderId',
            'senderType', 'owner_user_id', 'chatType', 'chatId', 'likes',
            'liked_by', 'replies', 'updated_at',
        ];

        // 组装 SET
        $sets = [];
        $params = [':no' => $no];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowedCols, true)) {
                continue; // 忽略未知字段
            }
            // 数组字段（liked_by / replies）转 JSON
            if (is_array($v)) {
                $v = $this->encodeArr($v);
            }
            $sets[] = "`{$k}` = :f_{$k}";
            $params[":f_{$k}"] = $v;
        }
        if (empty($sets)) {
            return $item; // 没有可更新字段
        }
        $sets[] = "`updated_at` = :ua";
        $params[':ua'] = time();

        $sql = "UPDATE `{$this->prefix}feedbacks` SET " . implode(', ', $sets) . " WHERE `no` = :no";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        // 记录内容修改历史
        if ($operator !== null && isset($fields['content'])) {
            $newContent = $fields['content'];
            if ($oldContent !== $newContent) {
                $this->pdo->prepare("INSERT INTO `{$this->prefix}history`
                    (`id`,`feedback_no`,`old_content`,`new_content`,`user_id`,`nickname`,`created_at`)
                    VALUES (?,?,?,?,?,?,?)")
                    ->execute([
                        uniqid('h', true), $no, $oldContent, $newContent,
                        $operator['user_id'] ?? '', $operator['nickname'] ?? '', time()
                    ]);
                // 同步记入处理记录时间线
                $this->addProcessLog($no, 'edit', [
                    'old' => $oldContent,
                    'new' => $newContent,
                ], $operator);
            }
        }

        return $this->find($no);
    }

    /**
     * 获取修改历史（时间倒序）
     */
    public function getHistory($no)
    {
        $no = ltrim((string)$no, '#');
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `{$this->prefix}history` WHERE `feedback_no` = ? ORDER BY `created_at` DESC"
        );
        $stmt->execute([$no]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['created_at'] = (int)$r['created_at'];
        }
        return $rows;
    }

    /**
     * 删除某条反馈的历史
     */
    public function deleteHistoryByFeedback($no)
    {
        $no = ltrim((string)$no, '#');
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->prefix}history` WHERE `feedback_no` = ?");
        $stmt->execute([$no]);
        return $stmt->rowCount() > 0;
    }

    /* ==================== 处理记录 ==================== */

    /**
     * 新增一条处理记录
     */
    public function addProcessLog($no, $action, array $detail, array $operator = [])
    {
        $no = ltrim((string)$no, '#');
        $stmt = $this->pdo->prepare("INSERT INTO `{$this->prefix}process_log`
            (`id`,`feedback_no`,`action`,`detail`,`user_id`,`nickname`,`created_at`)
            VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([
            uniqid('p', true),
            $no,
            $action,
            $this->encodeArr($detail),
            $operator['user_id'] ?? '',
            $operator['nickname'] ?? '',
            time(),
        ]);
        return true;
    }

    /**
     * 获取某条反馈的处理记录（时间正序）
     */
    public function getProcessLog($no)
    {
        $no = ltrim((string)$no, '#');
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `{$this->prefix}process_log` WHERE `feedback_no` = ? ORDER BY `created_at` ASC"
        );
        $stmt->execute([$no]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['created_at'] = (int)$r['created_at'];
            $r['detail'] = $this->decodeArr($r['detail'] ?? '');
            $r['operator'] = [
                'user_id'  => $r['user_id'] ?? '',
                'nickname' => $r['nickname'] ?? '',
            ];
        }
        return $rows;
    }

    /**
     * 删除某条反馈的处理记录
     */
    public function deleteProcessLogByFeedback($no)
    {
        $no = ltrim((string)$no, '#');
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->prefix}process_log` WHERE `feedback_no` = ?");
        $stmt->execute([$no]);
        return $stmt->rowCount() > 0;
    }

    /**
     * 删除反馈（连带评论与历史）
     */
    public function delete($no)
    {
        $no = ltrim((string)$no, '#');
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->prefix}feedbacks` WHERE `no` = ?");
        $stmt->execute([$no]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->deleteCommentsByFeedback($no);
        $this->deleteHistoryByFeedback($no);
        $this->deleteProcessLogByFeedback($no);
        return true;
    }

    /**
     * 点赞 / 取消点赞
     */
    public function toggleLike($no, $userId)
    {
        $no = ltrim((string)$no, '#');
        $userId = (string)$userId;
        $item = $this->find($no);
        if (!$item) {
            return null;
        }
        $likedBy = isset($item['liked_by']) && is_array($item['liked_by']) ? array_map('strval', $item['liked_by']) : [];
        $idx = array_search($userId, $likedBy, true);
        $likes = (int)($item['likes'] ?? 0);
        if ($idx !== false) {
            array_splice($likedBy, $idx, 1);
            $likes = max(0, $likes - 1);
            $liked = false;
        } else {
            $likedBy[] = $userId;
            $likes++;
            $liked = true;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE `{$this->prefix}feedbacks` SET `likes` = ?, `liked_by` = ? WHERE `no` = ?"
        );
        $stmt->execute([$likes, $this->encodeArr($likedBy), $no]);
        return ['liked' => $liked, 'likes' => $likes];
    }

    /**
     * 判断是否已点赞
     */
    public function hasLiked($no, $userId)
    {
        $item = $this->find($no);
        if (!$item) {
            return false;
        }
        $likedBy = isset($item['liked_by']) && is_array($item['liked_by']) ? array_map('strval', $item['liked_by']) : [];
        return in_array((string)$userId, $likedBy, true);
    }

    /**
     * 反馈总数
     */
    public function count()
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}feedbacks`")->fetchColumn();
    }

    /**
     * 某用户提交的反馈
     */
    public function findByOwner($userId)
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `{$this->prefix}feedbacks` WHERE `owner_user_id` = ? ORDER BY `created_at` DESC"
        );
        $stmt->execute([(string)$userId]);
        return array_map([$this, 'rowToRecord'], $stmt->fetchAll());
    }

    /**
     * 某用户点赞过的反馈
     */
    public function findLikedBy($userId)
    {
        $userId = (string)$userId;
        $result = [];
        foreach ($this->all() as $item) {
            $likedBy = isset($item['liked_by']) && is_array($item['liked_by']) ? array_map('strval', $item['liked_by']) : [];
            if (in_array($userId, $likedBy, true)) {
                $result[] = $item;
            }
        }
        return $result; // all() 已按 created_at 倒序
    }

    /**
     * 用户统计
     */
    public function statsByOwner($userId)
    {
        $userId = (string)$userId;
        $mine = $this->findByOwner($userId);
        $totalLikes = 0;
        $resolved = 0;
        foreach ($mine as $item) {
            $totalLikes += (int)($item['likes'] ?? 0);
            if (($item['status'] ?? '') === 'resolved') {
                $resolved++;
            }
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}comments` WHERE `user_id` = ?");
        $stmt->execute([$userId]);
        return [
            'total'    => count($mine),
            'likes'    => $totalLikes,
            'comments' => (int)$stmt->fetchColumn(),
            'resolved' => $resolved,
        ];
    }

    /* ==================== 评论管理 ==================== */

    /**
     * 获取某条反馈的评论（时间正序）
     */
    public function getComments($no)
    {
        $no = ltrim((string)$no, '#');
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `{$this->prefix}comments` WHERE `feedback_no` = ? ORDER BY `created_at` ASC"
        );
        $stmt->execute([$no]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['created_at'] = (int)$r['created_at'];
        }
        return $rows;
    }

    /**
     * 评论数量
     */
    public function commentCount($no)
    {
        $no = ltrim((string)$no, '#');
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}comments` WHERE `feedback_no` = ?");
        $stmt->execute([$no]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * 新增评论
     */
    public function addComment($no, array $comment)
    {
        $no = ltrim((string)$no, '#');
        if (!$this->find($no)) {
            return null;
        }
        $record = [
            'id'          => uniqid('c', true),
            'feedback_no' => $no,
            'user_id'     => $comment['user_id'] ?? '',
            'nickname'    => $comment['nickname'] ?? '',
            'avatar_url'  => $comment['avatar_url'] ?? '',
            'content'     => trim($comment['content'] ?? ''),
            'created_at'  => time(),
        ];
        $stmt = $this->pdo->prepare("INSERT INTO `{$this->prefix}comments`
            (`id`,`feedback_no`,`user_id`,`nickname`,`avatar_url`,`content`,`created_at`)
            VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([
            $record['id'], $record['feedback_no'], $record['user_id'],
            $record['nickname'], $record['avatar_url'], $record['content'], $record['created_at']
        ]);
        return $record;
    }

    /**
     * 根据 ID 查评论
     */
    public function findComment($commentId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->prefix}comments` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$commentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 更新评论内容
     */
    public function updateComment($commentId, $content)
    {
        $stmt = $this->pdo->prepare("UPDATE `{$this->prefix}comments` SET `content` = ? WHERE `id` = ?");
        $stmt->execute([trim($content), $commentId]);
        if ($stmt->rowCount() === 0) {
            // 内容完全相同也可能 rowCount=0，确认存在
            return $this->findComment($commentId);
        }
        return $this->findComment($commentId);
    }

    /**
     * 删除评论
     */
    public function deleteComment($commentId)
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->prefix}comments` WHERE `id` = ?");
        $stmt->execute([$commentId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * 删除某条反馈的所有评论
     */
    public function deleteCommentsByFeedback($no)
    {
        $no = ltrim((string)$no, '#');
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->prefix}comments` WHERE `feedback_no` = ?");
        $stmt->execute([$no]);
        return $stmt->rowCount() > 0;
    }

    /**
     * 后台统计看板
     */
    public function dashboardStats()
    {
        $now = time();
        $weekAgo = $now - 7 * 86400;
        $todayStart = strtotime('today');

        $total = $this->count();

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}feedbacks` WHERE `created_at` >= ?");
        $stmt->execute([$weekAgo]);
        $weekNew = (int)$stmt->fetchColumn();

        $stmt->execute([$todayStart]);
        $todayNew = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}feedbacks` WHERE `status` <> 'pending'");
        $stmt->execute();
        $responded = (int)$stmt->fetchColumn();

        // 平均响应：非 pending 的 updated_at - created_at
        $stmt = $this->pdo->query("SELECT AVG(`updated_at` - `created_at`) AS avg_sec, COUNT(*) AS cnt
            FROM `{$this->prefix}feedbacks`
            WHERE `status` <> 'pending' AND `updated_at` > `created_at`");
        $row = $stmt->fetch();
        $avgSec = (int)round((float)($row['avg_sec'] ?? 0));

        $comments = (int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->prefix}comments`")->fetchColumn();
        $likes = (int)$this->pdo->query("SELECT COALESCE(SUM(`likes`),0) FROM `{$this->prefix}feedbacks`")->fetchColumn();

        return [
            'total'     => $total,
            'week_new'  => $weekNew,
            'today_new' => $todayNew,
            'rate'      => $total > 0 ? round($responded / $total * 100) : 0,
            'avg_hours' => $avgSec > 0 ? round($avgSec / 3600, 1) : 0,
            'comments'  => $comments,
            'likes'     => $likes,
        ];
    }

    /**
     * 暴露 PDO（迁移工具用）
     */
    public function pdo()
    {
        return $this->pdo;
    }

    public function prefix()
    {
        return $this->prefix;
    }
}
