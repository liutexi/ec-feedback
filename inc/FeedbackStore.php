<?php
/**
 * 反馈数据存储类（基于 JSON 文件）
 * 提供反馈的增删改查，以及编号生成
 */

class FeedbackStore
{
    /** @var string 数据文件路径 */
    private $file;

    /** @var string 评论数据文件路径 */
    private $commentFile;

    /** @var string 修改历史数据文件路径 */
    private $historyFile;

    /** @var string 处理记录数据文件路径 */
    private $processLogFile;

    /** @var array 反馈数据（内存缓存） */
    private $data;

    /** @var array 评论数据（内存缓存） */
    private $comments;

    /** @var array 修改历史数据（内存缓存） */
    private $history;

    /** @var array 处理记录（内存缓存） */
    private $processLog;

    /** @var bool 最近一次 save() 是否成功 */
    private $lastSaveOk = true;

    public function __construct($dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        $this->file = rtrim($dataDir, '/\\') . '/feedbacks.json';
        $this->commentFile = rtrim($dataDir, '/\\') . '/comments.json';
        $this->historyFile = rtrim($dataDir, '/\\') . '/history.json';
        $this->processLogFile = rtrim($dataDir, '/\\') . '/process_log.json';
        $this->data = $this->load($this->file);
        $this->comments = $this->load($this->commentFile);
        $this->history = $this->load($this->historyFile);
        $this->processLog = $this->load($this->processLogFile);
    }

    /**
     * 从文件加载数据
     */
    private function load($file)
    {
        if (!file_exists($file)) {
            file_put_contents($file, '[]', LOCK_EX);
            return [];
        }
        $raw = file_get_contents($file);
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    /**
     * 将数据持久化到文件
     * @return bool 是否写入成功
     */
    private function save()
    {
        // 加锁写入，避免并发覆盖
        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = @file_put_contents($this->file, $json, LOCK_EX);
        if ($result === false) {
            $this->lastSaveOk = false;
            $this->logError('写入失败: ' . $this->file);
            return false;
        }
        $this->lastSaveOk = true;
        return true;
    }

    /**
     * 最近一次 save() 是否成功（供调用方判断写入是否真的落盘）
     */
    public function lastSaveOk()
    {
        return $this->lastSaveOk;
    }

    /**
     * 持久化评论数据
     * @return bool
     */
    private function saveComments()
    {
        $json = json_encode($this->comments, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = @file_put_contents($this->commentFile, $json, LOCK_EX);
        if ($result === false) {
            $this->logError('评论写入失败: ' . $this->commentFile);
            return false;
        }
        return true;
    }

    /**
     * 持久化修改历史
     * @return bool
     */
    private function saveHistory()
    {
        $json = json_encode($this->history, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = @file_put_contents($this->historyFile, $json, LOCK_EX);
        if ($result === false) {
            $this->logError('历史写入失败: ' . $this->historyFile);
            return false;
        }
        return true;
    }

    /**
     * 持久化处理记录
     * @return bool
     */
    private function saveProcessLog()
    {
        $json = json_encode($this->processLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = @file_put_contents($this->processLogFile, $json, LOCK_EX);
        if ($result === false) {
            $this->logError('处理记录写入失败: ' . $this->processLogFile);
            return false;
        }
        return true;
    }

    /**
     * 记录存储错误到独立日志
     */
    private function logError($msg)
    {
        $dir = dirname($this->file);
        @file_put_contents(
            $dir . '/store_error.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n",
            FILE_APPEND
        );
    }

    /**
     * 生成反馈编号
     * 格式：#FB-年份月份-本月序号，如 #FB-202610-0001
     *
     * @param int $timestamp 提交时间戳
     * @return string 完整编号（不含 # 前缀）
     */
    public function generateId($timestamp = null)
    {
        $ts = $timestamp ?: time();
        $ym = date('Ym', $ts); // 202610
        $seq = 1;
        foreach ($this->data as $item) {
            if (isset($item['no']) && strpos($item['no'], $ym) === 0) {
                // no 形如 "202610-0001"，序号在 "-" 之后
                $parts = explode('-', $item['no']);
                $current = isset($parts[1]) ? (int)$parts[1] : 0;
                $seq = max($seq, $current + 1);
            }
        }
        return sprintf('%s-%04d', $ym, $seq);
    }

    /**
     * 新增一条反馈
     *
     * @param array $feedback 反馈字段（content、senderNickname、senderId、senderType、chatType、type、priority 等）
     * @return array 保存后的完整反馈记录
     */
    public function add(array $feedback)
    {
        $now = time();
        $no = $this->generateId($now);

        $record = [
            'no'          => $no,                       // 如 202610-0001
            'content'     => isset($feedback['content']) ? trim($feedback['content']) : '',
            'status'      => 'pending',                 // pending=待跟进, processing=处理中, resolved=已解决
            'type'        => isset($feedback['type']) ? $feedback['type'] : 'other', // bug=缺陷, suggest=建议, other=其他
            'priority'    => isset($feedback['priority']) ? $feedback['priority'] : 'normal', // high=高, normal=普通, low=低
            'senderNickname' => isset($feedback['senderNickname']) ? $feedback['senderNickname'] : '',
            'senderId'    => isset($feedback['senderId']) ? $feedback['senderId'] : '',
            'senderType'  => isset($feedback['senderType']) ? $feedback['senderType'] : 'user',
            'owner_user_id' => isset($feedback['owner_user_id']) ? $feedback['owner_user_id'] : (isset($feedback['senderId']) ? $feedback['senderId'] : ''),
            'chatType'    => isset($feedback['chatType']) ? $feedback['chatType'] : 'bot', // bot=私聊, group=群聊
            'chatId'      => isset($feedback['chatId']) ? $feedback['chatId'] : '',
            'likes'       => 0,
            'liked_by'    => [],                          // 点赞用户 ID 列表（去重，防止重复点赞）
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        $this->data[] = $record;
        $this->save();
        return $record;
    }

    /**
     * 根据编号查找反馈（编号可带 # 前缀或纯编号）
     */
    public function find($no)
    {
        $no = ltrim($no, '#');
        foreach ($this->data as $item) {
            if ($item['no'] === $no) {
                return $item;
            }
        }
        return null;
    }

    /**
     * 按短编号查找（如 001 / 1）。
     * 优先匹配当月最新一条；跨月重名时返回 null（避免歧义）。
     *
     * @return array|null
     */
    public function findByShortNo($short)
    {
        $short = preg_replace('/\D/', '', (string)$short); // 只留数字
        if ($short === '') {
            return null;
        }
        $seq = ltrim($short, '0');
        if ($seq === '') {
            $seq = '0';
        }
        $curYm = date('Ym');
        $curMatch = null;
        $allMatches = [];
        foreach ($this->data as $item) {
            $parts = explode('-', $item['no'] ?? '');
            if (count($parts) !== 2) {
                continue;
            }
            // 序号去前导零后比较
            $itemSeq = ltrim($parts[1], '0');
            if ($itemSeq === '') {
                $itemSeq = '0';
            }
            if ($itemSeq !== $seq) {
                continue;
            }
            $allMatches[] = $item;
            if ($parts[0] === $curYm) {
                $curMatch = $item; // 当月优先
            }
        }
        if ($curMatch !== null) {
            return $curMatch;
        }
        // 非当月：只有全局唯一时才返回
        if (count($allMatches) === 1) {
            return $allMatches[0];
        }
        return null;
    }

    /**
     * 生成短编号（取 no 的序号部分，如 202610-0001 -> 0001）
     */
    public function shortNo($no)
    {
        $parts = explode('-', ltrim((string)$no, '#'));
        return count($parts) === 2 ? $parts[1] : $no;
    }

    /**
     * 获取全部反馈（按创建时间倒序）
     */
    public function all()
    {
        $data = $this->data;
        usort($data, function ($a, $b) {
            return $b['created_at'] <=> $a['created_at'];
        });
        return $data;
    }

    /**
     * 获取全部反馈（按热度倒序）
     * 热度 = 点赞数 + 评论数
     */
    public function allByHot()
    {
        $data = $this->data;
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
     * 计算单条反馈的热度
     */
    public function heat(array $item)
    {
        $likes = (int)($item['likes'] ?? 0);
        $comments = $this->commentCount($item['no'] ?? '');
        return $likes + $comments;
    }

    /**
     * 更新反馈（部分字段）
     *
     * @param string $no 反馈编号
     * @param array $fields 要更新的字段
     * @param array|null $operator 操作人信息 ['user_id'=>, 'nickname'=>]，提供则记录内容修改历史
     */
    public function update($no, array $fields, $operator = null)
    {
        $no = ltrim($no, '#');
        foreach ($this->data as &$item) {
            if ($item['no'] === $no) {
                $oldContent = $item['content'] ?? '';
                foreach ($fields as $k => $v) {
                    $item[$k] = $v;
                }
                $item['updated_at'] = time();

                // 记录内容修改历史
                if ($operator !== null && isset($fields['content'])) {
                    $newContent = $fields['content'];
                    if ($oldContent !== $newContent) {
                        $this->history[] = [
                            'id'          => uniqid('h', true),
                            'feedback_no' => $no,
                            'old_content' => $oldContent,
                            'new_content' => $newContent,
                            'user_id'     => $operator['user_id'] ?? '',
                            'nickname'    => $operator['nickname'] ?? '',
                            'created_at'  => time(),
                        ];
                        $this->saveHistory();
                        // 同步记入处理记录时间线
                        $this->addProcessLog($no, 'edit', [
                            'old' => $oldContent,
                            'new' => $newContent,
                        ], $operator);
                    }
                }

                $this->save();
                return $item;
            }
        }
        return null;
    }

    /**
     * 获取某条反馈的修改历史（按时间倒序，最新的在前）
     */
    public function getHistory($no)
    {
        $no = ltrim($no, '#');
        $result = [];
        foreach ($this->history as $h) {
            if ($h['feedback_no'] === $no) {
                $result[] = $h;
            }
        }
        usort($result, function ($a, $b) {
            return $b['created_at'] <=> $a['created_at'];
        });
        return $result;
    }

    /* ==================== 处理记录 ==================== */

    /**
     * 新增一条处理记录
     *
     * @param string $no 反馈编号
     * @param string $action 动作类型：status（状态变更）/ reply（回复）/ edit（内容修改）
     * @param array $detail 详情（如 ['from'=>, 'to'=>]、['content'=>]、['old'=>, 'new'=>]）
     * @param array $operator 操作人 ['user_id'=>, 'nickname'=>]
     */
    public function addProcessLog($no, $action, array $detail, array $operator = [])
    {
        $no = ltrim((string)$no, '#');
        $this->processLog[] = [
            'id'          => uniqid('p', true),
            'feedback_no' => $no,
            'action'      => $action,
            'detail'      => $detail,
            'operator'    => [
                'user_id'  => $operator['user_id'] ?? '',
                'nickname' => $operator['nickname'] ?? '',
            ],
            'created_at'  => time(),
        ];
        $this->saveProcessLog();
        return true;
    }

    /**
     * 获取某条反馈的处理记录（按时间正序，旧→新）
     */
    public function getProcessLog($no)
    {
        $no = ltrim((string)$no, '#');
        $result = [];
        foreach ($this->processLog as $p) {
            if ($p['feedback_no'] === $no) {
                $result[] = $p;
            }
        }
        usort($result, function ($a, $b) {
            return ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0);
        });
        return $result;
    }

    /**
     * 删除某条反馈的所有处理记录（删除反馈时调用）
     */
    public function deleteProcessLogByFeedback($no)
    {
        $no = ltrim((string)$no, '#');
        $before = count($this->processLog);
        $this->processLog = array_values(array_filter($this->processLog, function ($p) use ($no) {
            return $p['feedback_no'] !== $no;
        }));
        if (count($this->processLog) !== $before) {
            $this->saveProcessLog();
        }
    }

    /**
     * 删除某条反馈的修改历史（删除反馈时调用）
     */
    public function deleteHistoryByFeedback($no)
    {
        $no = ltrim($no, '#');
        $before = count($this->history);
        $this->history = array_values(array_filter($this->history, function ($h) use ($no) {
            return $h['feedback_no'] !== $no;
        }));
        if (count($this->history) !== $before) {
            $this->saveHistory();
        }
    }

    /**
     * 删除反馈
     */
    public function delete($no)
    {
        $no = ltrim($no, '#');
        foreach ($this->data as $i => $item) {
            if ($item['no'] === $no) {
                array_splice($this->data, $i, 1);
                $this->save();
                $this->deleteCommentsByFeedback($no);
                $this->deleteHistoryByFeedback($no);
                $this->deleteProcessLogByFeedback($no);
                return true;
            }
        }
        return false;
    }

    /**
     * 点赞 / 取消点赞（切换）
     * 每个用户对每条反馈只能点赞一次，再次调用则取消
     *
     * @param string $no 反馈编号
     * @param string $userId 点赞用户 ID
     * @return array ['liked'=>bool 是否已点赞, 'likes'=>int 最新点赞数] 或 null
     */
    public function toggleLike($no, $userId)
    {
        $no = ltrim($no, '#');
        $userId = (string)$userId;
        foreach ($this->data as &$item) {
            if ($item['no'] === $no) {
                $likedBy = isset($item['liked_by']) && is_array($item['liked_by']) ? $item['liked_by'] : [];
                // 从 likes 反推，兼容旧数据
                if (empty($likedBy) && (int)($item['likes'] ?? 0) > 0) {
                    $likedBy = [];
                }
                $idx = array_search($userId, $likedBy, true);
                if ($idx !== false) {
                    // 已点赞 -> 取消
                    array_splice($likedBy, $idx, 1);
                    $item['likes'] = max(0, (int)($item['likes'] ?? 0) - 1);
                    $liked = false;
                } else {
                    // 未点赞 -> 点赞
                    $likedBy[] = $userId;
                    $item['likes'] = (int)($item['likes'] ?? 0) + 1;
                    $liked = true;
                }
                $item['liked_by'] = $likedBy;
                $this->save();
                return ['liked' => $liked, 'likes' => (int)$item['likes']];
            }
        }
        return null;
    }

    /**
     * 判断某用户是否已点赞某条反馈
     */
    public function hasLiked($no, $userId)
    {
        $item = $this->find($no);
        if (!$item) {
            return false;
        }
        $likedBy = isset($item['liked_by']) && is_array($item['liked_by']) ? $item['liked_by'] : [];
        return in_array((string)$userId, array_map('strval', $likedBy), true);
    }

    /**
     * 统计数量
     */
    public function count()
    {
        return count($this->data);
    }

    /**
     * 获取某个用户的全部反馈（按 owner_user_id）
     */
    public function findByOwner($userId)
    {
        $result = [];
        foreach ($this->data as $item) {
            if (($item['owner_user_id'] ?? '') === (string)$userId) {
                $result[] = $item;
            }
        }
        usort($result, function ($a, $b) {
            return $b['created_at'] <=> $a['created_at'];
        });
        return $result;
    }

    /**
     * 获取某用户点赞过的反馈列表
     */
    public function findLikedBy($userId)
    {
        $userId = (string)$userId;
        $result = [];
        foreach ($this->data as $item) {
            $likedBy = isset($item['liked_by']) && is_array($item['liked_by']) ? array_map('strval', $item['liked_by']) : [];
            if (in_array($userId, $likedBy, true)) {
                $result[] = $item;
            }
        }
        usort($result, function ($a, $b) {
            return $b['created_at'] <=> $a['created_at'];
        });
        return $result;
    }

    /**
     * 用户统计：提交数、获得赞数、发出评论数、已解决数
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
        // 我发出的评论数
        $myComments = 0;
        foreach ($this->comments as $c) {
            if (($c['user_id'] ?? '') === $userId) {
                $myComments++;
            }
        }
        return [
            'total'      => count($mine),
            'likes'      => $totalLikes,
            'comments'   => $myComments,
            'resolved'   => $resolved,
        ];
    }

    /* ==================== 评论管理 ==================== */

    /**
     * 获取某条反馈的所有评论（按时间正序）
     */
    public function getComments($no)
    {
        $no = ltrim($no, '#');
        $result = [];
        foreach ($this->comments as $c) {
            if ($c['feedback_no'] === $no) {
                $result[] = $c;
            }
        }
        usort($result, function ($a, $b) {
            return $a['created_at'] <=> $b['created_at'];
        });
        return $result;
    }

    /**
     * 获取某条反馈的评论数量
     */
    public function commentCount($no)
    {
        return count($this->getComments($no));
    }

    /**
     * 新增评论
     *
     * @param string $no 反馈编号
     * @param array $comment ['user_id','nickname','avatar_url','content']
     * @return array|null 保存后的评论记录
     */
    public function addComment($no, array $comment)
    {
        $no = ltrim($no, '#');
        if (!$this->find($no)) {
            return null;
        }
        $record = [
            'id'           => uniqid('c', true),
            'feedback_no'  => $no,
            'user_id'      => $comment['user_id'] ?? '',
            'nickname'     => $comment['nickname'] ?? '',
            'avatar_url'   => $comment['avatar_url'] ?? '',
            'content'      => trim($comment['content'] ?? ''),
            'created_at'   => time(),
        ];
        $this->comments[] = $record;
        $this->saveComments();
        return $record;
    }

    /**
     * 根据评论 ID 查找评论
     */
    public function findComment($commentId)
    {
        foreach ($this->comments as $c) {
            if ($c['id'] === $commentId) {
                return $c;
            }
        }
        return null;
    }

    /**
     * 更新评论内容
     */
    public function updateComment($commentId, $content)
    {
        foreach ($this->comments as &$c) {
            if ($c['id'] === $commentId) {
                $c['content'] = trim($content);
                $this->saveComments();
                return $c;
            }
        }
        return null;
    }

    /**
     * 删除评论
     */
    public function deleteComment($commentId)
    {
        foreach ($this->comments as $i => $c) {
            if ($c['id'] === $commentId) {
                array_splice($this->comments, $i, 1);
                $this->saveComments();
                return true;
            }
        }
        return false;
    }

    /**
     * 删除某条反馈的所有评论（删除反馈时调用）
     */
    public function deleteCommentsByFeedback($no)
    {
        $no = ltrim($no, '#');
        $before = count($this->comments);
        $this->comments = array_values(array_filter($this->comments, function ($c) use ($no) {
            return $c['feedback_no'] !== $no;
        }));
        if (count($this->comments) !== $before) {
            $this->saveComments();
        }
    }

    /**
     * 后台统计看板数据
     */
    public function dashboardStats()
    {
        $now = time();
        $weekAgo = $now - 7 * 86400;
        $todayStart = strtotime('today');

        $weekNew = 0;      // 本周新增
        $todayNew = 0;     // 今日新增
        $responded = 0;    // 已响应（非 pending）
        $totalRespondSec = 0;  // 响应总耗时
        $respondCount = 0;

        foreach ($this->data as $item) {
            $created = (int)($item['created_at'] ?? 0);
            if ($created >= $weekAgo) {
                $weekNew++;
            }
            if ($created >= $todayStart) {
                $todayNew++;
            }
            // 响应时长：从创建到第一次更新（改状态/回复）
            $status = $item['status'] ?? 'pending';
            if ($status !== 'pending') {
                $responded++;
                $updated = (int)($item['updated_at'] ?? 0);
                if ($updated > $created && $updated > 0) {
                    $totalRespondSec += ($updated - $created);
                    $respondCount++;
                }
            }
        }

        $total = count($this->data);
        $rate = $total > 0 ? round($responded / $total * 100) : 0;
        $avgSec = $respondCount > 0 ? (int)round($totalRespondSec / $respondCount) : 0;

        return [
            'total'        => $total,
            'week_new'     => $weekNew,
            'today_new'    => $todayNew,
            'rate'         => $rate,          // 响应率 %
            'avg_hours'    => $avgSec > 0 ? round($avgSec / 3600, 1) : 0,  // 平均响应小时
            'comments'     => count($this->comments),
            'likes'        => array_sum(array_map(function ($i) { return (int)($i['likes'] ?? 0); }, $this->data)),
        ];
    }
}
