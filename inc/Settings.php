<?php
/**
 * 站点运行时设置（JSON 文件存储）
 * 用于后台可开关的配置项，避免改代码文件
 */

class Settings
{
    /** @var string 配置文件路径 */
    private $file;

    /** @var array 设置数据 */
    private $data;

    /** @var array 默认值 */
    private static $defaults = [
        'short_no_enabled' => false,  // 是否启用短编号（如 001）
        'storage_driver'   => '',     // 存储驱动：空=跟随 config.php；json / mysql
        'storage_db'       => [],     // 数据库连接信息（driver=mysql 时使用）
        'site_name'        => '',     // 站点名称（空则用默认 "AI 反馈"）
        'site_logo'        => '',     // 站点 Logo：/uploads/site-logo.xxx 或 http(s) 外链；空则自动检测根目录 logo.* 
        'theme_color'      => '',     // 主题主色：#rrggbb；空则用样式表内置默认色
    ];

    public function __construct($dataDir)
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        $this->file = rtrim($dataDir, '/\\') . '/settings.json';
        $this->data = $this->load();
    }

    private function load()
    {
        if (!file_exists($this->file)) {
            return self::$defaults;
        }
        $raw = file_get_contents($this->file);
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return self::$defaults;
        }
        return array_merge(self::$defaults, $arr);
    }

    private function save()
    {
        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->file, $json, LOCK_EX);
    }

    /**
     * 读取设置项
     */
    public function get($key, $default = null)
    {
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }
        return $default !== null ? $default : (self::$defaults[$key] ?? null);
    }

    /**
     * 写入设置项
     */
    public function set($key, $value)
    {
        $this->data[$key] = $value;
        $this->save();
    }

    /**
     * 短编号是否启用
     */
    public function shortNoEnabled()
    {
        return (bool)$this->get('short_no_enabled', false);
    }
}
