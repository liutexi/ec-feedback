<?php
/**
 * 存储驱动加载器
 * 根据配置自动选择 JSON 或数据库实现，业务代码无需关心底层差异。
 *
 * 优先级：后台「站点设置」里保存的选择（data/settings.json）
 *         > config.php 里的 storage 配置
 *       后台选择为空时，回落到 config.php。
 */

require_once __DIR__ . '/FeedbackStore.php';
require_once __DIR__ . '/FeedbackStoreDb.php';

/**
 * 解析最终生效的存储配置
 *
 * @param array $config 全局配置
 * @param Settings|null $settings 运行时设置（可选）
 * @return array ['driver'=>string, 'db'=>array]
 */
function resolve_storage_config(array $config, $settings = null)
{
    $base = $config['storage'] ?? [];
    $driver = $base['driver'] ?? 'json';
    $db = $base['db'] ?? [];

    // 后台设置覆盖（为空则沿用 config.php）
    if ($settings instanceof Settings) {
        $uiDriver = (string)$settings->get('storage_driver', '');
        if ($uiDriver !== '') {
            $driver = $uiDriver;
        }
        $uiDb = $settings->get('storage_db', []);
        if (is_array($uiDb) && !empty($uiDb)) {
            // 只覆盖有值的字段，避免把 config 里的好配置清空
            foreach ($uiDb as $k => $v) {
                if ($v !== '' && $v !== null) {
                    $db[$k] = $v;
                }
            }
        }
    }

    // 归一化驱动名
    $norm = strtolower((string)$driver);
    if (in_array($norm, ['mysql', 'db', 'database'], true)) {
        $driver = 'mysql';
    } else {
        $driver = 'json';
    }

    return ['driver' => $driver, 'db' => $db];
}

/**
 * 创建存储实例
 *
 * 若数据库不可用，自动降级为 JSON 并记录错误，
 * 避免后台切错配置后整站白屏、无法改回来。
 *
 * @param array $config 全局配置
 * @param Settings|null $settings 运行时设置
 * @return FeedbackStore|FeedbackStoreDb
 */
function create_store(array $config, $settings = null)
{
    $resolved = resolve_storage_config($config, $settings);

    if ($resolved['driver'] === 'mysql') {
        try {
            return new FeedbackStoreDb($resolved['db']);
        } catch (Exception $e) {
            // 记录错误，降级到 JSON，保证站内可访问（含后台改回配置）
            $GLOBALS['__storage_error'] = $e->getMessage();
            $logDir = $config['data_dir'] ?? (__DIR__ . '/../data');
            if (is_dir($logDir)) {
                @file_put_contents(
                    rtrim($logDir, '/\\') . '/storage_error.log',
                    '[' . date('Y-m-d H:i:s') . '] DB 连接失败，已降级为 JSON：' . $e->getMessage() . "\n",
                    FILE_APPEND
                );
            }
            return new FeedbackStore($config['data_dir']);
        }
    }
    return new FeedbackStore($config['data_dir']);
}

/**
 * 取出上次存储降级的错误信息（如有）
 */
function storage_last_error()
{
    return $GLOBALS['__storage_error'] ?? '';
}

/**
 * 当前使用的驱动名（用于后台展示）
 */
function storage_driver_name(array $config, $settings = null)
{
    $resolved = resolve_storage_config($config, $settings);
    return $resolved['driver'] === 'mysql' ? 'MySQL' : 'JSON 文件';
}

/**
 * 测试数据库连接是否可用
 *
 * @param array $db
 * @return array ['ok'=>bool, 'message'=>string]
 */
function test_db_connection(array $db)
{
    if (!extension_loaded('pdo_mysql')) {
        return ['ok' => false, 'message' => 'PHP 未安装 pdo_mysql 扩展，无法使用数据库存储'];
    }
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'] ?? '127.0.0.1',
            (int)($db['port'] ?? 3306),
            $db['database'] ?? '',
            $db['charset'] ?? 'utf8mb4'
        );
        new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 4,
        ]);
        return ['ok' => true, 'message' => '连接成功'];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}
