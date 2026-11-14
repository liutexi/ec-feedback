<?php
/**
 * 管理员操作日志
 * 记录管理员对反馈的所有写操作，便于追查。
 * 数据存于 data/admin_log.json（追加式）。
 */

class AdminLog
{
    /** @var string 日志文件路径 */
    private $file;

    /** @var int 单日志文件最大条数（超出后自动清理旧记录） */
    private $maxEntries = 500;

    public function __construct($dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        $this->file = rtrim($dataDir, '/\\') . '/admin_log.json';
    }

    /**
     * 记录一条操作
     *
     * @param string $action 操作类型：update_status / delete / batch_delete / reply / save_settings
     * @param array $detail 详情（编号、状态变化、数量等）
     * @param array $operator 操作人信息 ['user_id'=>, 'nickname'=>]
     */
    public function log($action, array $detail, array $operator = [])
    {
        $entry = [
            'action'     => $action,
            'detail'     => $detail,
            'operator'   => [
                'user_id'  => $operator['user_id'] ?? '',
                'nickname' => $operator['nickname'] ?? '',
            ],
            'ip'         => $this->clientIp(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 200) : '',
            'created_at' => time(),
        ];

        $list = $this->load();
        $list[] = $entry;

        // 超出上限时裁剪最旧的
        if (count($list) > $this->maxEntries) {
            $list = array_slice($list, -$this->maxEntries);
        }

        $json = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($this->file, $json, LOCK_EX);
    }

    /**
     * 读取全部日志（时间倒序，最新在前）
     */
    public function all($limit = 100)
    {
        $list = $this->load();
        usort($list, function ($a, $b) {
            return ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0);
        });
        return array_slice($list, 0, $limit);
    }

    /**
     * 日志条数
     */
    public function count()
    {
        return count($this->load());
    }

    /**
     * 从文件加载
     */
    private function load()
    {
        if (!file_exists($this->file)) {
            return [];
        }
        $raw = @file_get_contents($this->file);
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    /**
     * 获取客户端真实 IP
     */
    private function clientIp()
    {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($keys as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}
