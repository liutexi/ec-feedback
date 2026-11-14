<?php
/**
 * 云湖反馈机器人 —— 全局配置文件
 * 部署前请修改以下配置
 */

return [
    // 云湖机器人 Token（在机器人控制台获取）
    'bot_token' => '在这里填写你的机器人Token',

    // 机器人 ID（用于判断群里 @ 的是不是机器人自己，避免 @ 别人也触发反馈）
    // 在云湖机器人控制台可查到（一串数字）
    'bot_id' => '在这里填写你的机器人ID',

    // 站点根地址（不带末尾斜杠），用于生成反馈详情链接
    // 例如：'https://feedback-ai.web01.jwznb.com'
    'site_url' => 'https://你的网站域名',

    // 指令 ID（云湖后台配置的指令ID，用于匹配指令消息事件）
    'command_id' => '2713',

    // 指令触发词（用户实际发送的触发词，如 /反馈 或 反馈），用于从消息里剥离前缀
    // 若为空，则回退用 command_id 作为前缀
    'command_trigger' => '/反馈',

    // 管理员密码（用于访问 /fb-admin 后台），部署后请务必修改
    'admin_password' => 'admin123456',

    // ===== 云湖 OAuth2 登录配置 =====
    // 在云湖 OAuth2 授权机器人（机器人ID 45781752）申请获得
    'oauth_client_id' => '在这里填写client_id',
    'oauth_client_secret' => '在这里填写client_secret',

    // 回调地址：必须是云湖后台登记的完整地址，与申请时一致
    'oauth_redirect_uri' => 'https://你的网站域名/oauth-callback.php',

    // 管理员白名单：填入允许登录后台的云湖 user_id（数组，可多个）
    // 例如：['17658825', '12345678']
    // 留空数组表示不限制（任何登录用户都能进后台，不安全）
    'admin_user_ids' => [],

    // 数据存储目录（相对项目根目录），用于存放 JSON 反馈数据
    'data_dir' => __DIR__ . '/data',

    // ===== 存储方式 =====
    // driver = 'json'  : 使用 JSON 文件（默认，零配置，适合小站）
    // driver = 'mysql' : 使用 MySQL 数据库（适合数据量大 / 多实例）
    //
    // 切换步骤：
    //   1. 先在宝塔创建一个空数据库（utf8mb4）
    //   2. 填好下面的 host/port/database/username/password
    //   3. 把 driver 改成 'mysql'
    // 表结构首次访问会自动创建，无需手动建表。
    'storage' => [
        'driver' => 'json',
        'db' => [
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => 'feedback',
            'username' => 'feedback',
            'password' => '',
            'prefix'   => 'fb_',      // 表名前缀，多站共用一个库时可区分
            'charset'  => 'utf8mb4',
        ],
    ],
];
