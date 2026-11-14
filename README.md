# 云湖反馈机器人（PHP）

> 一个基于云湖（YHChat）订阅地址回调的轻量级反馈收集系统。用户通过指令或 `@机器人` 提交反馈，机器人自动记录并回复反馈编号，管理员可在 Web 后台统一管理。

[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

---

## ✨ 功能特性

- 🚀 **指令反馈**：用户发送 `/反馈 内容` 即可提交，机器人自动记录并回复确认
- 💬 **@机器人反馈**：群里 `@机器人 + 内容` 也能提交（精确识别，不会误触发）
- 🔢 **自动编号**：格式 `#FB-年份月份-本月序号`，如 `#FB-202610-0001`，每月自动从 0001 递增
- 🖥️ **前端展示**：反馈列表页 + 详情页，支持点赞
- 📝 **评论互动**：登录用户可评论、修改、删除自己的评论
- 👤 **个人中心**：云湖登录后可查看、修改、删除自己提交的反馈
- 🔐 **管理后台**：登录后可查看、修改状态（待跟进 / 处理中 / 已解决）、删除反馈
- 🔑 **云湖 OAuth2 登录**：普通用户和管理员均可用云湖账号一键登录（授权码模式 + PKCE S256）
- 💾 **双存储驱动**：默认 JSON 文件（零配置），可一键切换 MySQL（改配置一行）
- 🔄 **版本号与检查更新**：前台页脚、后台顶栏显示当前版本，后台可一键检查 GitHub 最新 Release 并直达下载
- ⚡ **零依赖**：纯 PHP 实现，无需 Composer，开箱即用

---

## 🗄️ 数据存储方式（JSON / MySQL 二选一）

默认用 JSON 文件，无需任何配置。数据量变大或多台机器共用时，可切换到 MySQL——**只改 `config.php` 一个字段，业务代码零改动**。

### 方式一：JSON 文件（默认）

```php
'storage' => [
    'driver' => 'json',
    // db 配置留空即可
],
```

数据存于 `data/*.json`，备份这几个文件就等于备份全部数据。

### 方式二：MySQL 数据库

**第 1 步**：宝塔面板 → 数据库 → 添加数据库（字符集选 `utf8mb4`），记下库名/用户名/密码。

**第 2 步**：修改 `config.php`：

```php
'storage' => [
    'driver' => 'mysql',          // ← 只改这里
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'feedback',   // 你的库名
        'username' => 'feedback',   // 你的用户名
        'password' => '你的密码',
        'prefix'   => 'fb_',        // 表前缀
        'charset'  => 'utf8mb4',
    ],
],
```

**第 3 步**：直接访问网站即可。**表结构会在首次访问时自动创建**，无需手动建表、无需导入 SQL。

### 已有 JSON 数据要迁到 MySQL？

访问 `https://你的域名/migrate.php`，会显示迁移结果（导入了多少条、跳过多少条）。迁移完成后：

1. 把 `driver` 改成 `'mysql'`
2. **删除 `migrate.php`**（安全考虑）

迁移是可重复执行的，已存在的记录会自动跳过，不会重复导入。

### 如何确认当前用的是哪种？

管理后台「站点设置」里会显示：**当前使用：JSON 文件 / MySQL**。

### 自定义网站 Logo 与站点名称

进入管理后台 `/fb-admin.php` →「站点设置 → 网站 Logo 与站点名称」即可可视化配置，无需改代码：

- **上传 Logo 图片**：支持 PNG / JPG / GIF / WebP，≤ 2MB，选择后即时预览，保存为 `uploads/site-logo.*`
- **或填写图片地址**：外链 `https://example.com/logo.png`，或站内路径（如 `/logo.png`）
- **站点名称**：左上角文字，留空则显示默认「AI 反馈」
- **恢复默认**：清空图片地址，或勾选「清除已上传 Logo」

配置后的 Logo 会同时用于**页面左上角**和**浏览器标签图标（favicon）**，前台列表页/详情页、后台登录页与后台页面同步生效。未配置时依次兜底：项目根目录的 `logo.png` / `logo.jpg` / `logo.webp` 等 → 内置 SVG 图标。

> `uploads/` 目录需要 PHP 进程可写（首次上传会自动创建），目录内自带 `.htaccess`（禁止列目录、只允许访问图片文件）。Nginx 环境下该目录需保持可访问，不要加入 deny 规则。

### 自定义主题颜色

进入管理后台 `/fb-admin.php` →「站点设置 → 主题颜色」，可配置全站强调色（按钮、链接、徽章、选中态）：

- **直接选色**：点击色块用系统取色器，或填 `#7c3aed` / `#0af`（3 位简写也支持）
- **预设色板**：靛蓝、紫罗兰、天蓝、青碧、翠绿、橙红、玫红、石墨 8 种一键切换
- **从 Logo 取色**：自动分析 Logo 主色调并填入（上传新 Logo 时也会自动取色），改动即时预览
- **恢复默认**：清空输入框即回到默认靛蓝 `#4f6bfe`

主题色会**同时作用于前台与后台**。系统会自动派生配套色：深一档的 hover 色、很淡的徽章底色、以及浅色主题下的深色文字（选亮黄、亮青这类浅色时，按钮文字自动转深色，编号/链接自动压深一档，保证可读）。

> 从 Logo 取色用的是浏览器端 canvas 采样，因此**外链图片需要能被 CORS 读取**；若提示无法读取像素，把图片上传到本站即可。

---

### 版本号与检查更新

当前版本号定义在 `inc/version.php` 的 `FB_VERSION`，同时出现在：

- 前台页脚：`反馈由云湖机器人自动收集 · V0.0.1`
- 后台顶栏标题旁、登录页底部
- 后台「关于与更新」卡片

后台 `/fb-admin.php` →「关于与更新」可点「检查更新」：服务器会请求 GitHub Release 接口，**取最近发布的那个 Release 的版本号**（跳过草稿），与本地 `FB_VERSION` 比较，提示是否为新版本，并给出发布说明和下载按钮。

- 判断依据完全来自 GitHub Release 上的 tag，不自建规则；同号时正式版高于 Dev 版（`V0.0.1` > `V0.0.1Dev9`）
- 检查结果缓存 10 分钟（`data/update_check.json`），进入后台时若超过 24 小时未检查会自动静默查一次
- 联网失败不影响使用，会提示错误并给出「手动打开发布页」链接；有旧缓存时显示上次结果
- 版本比较支持 `V0.0.1`、`V0.0.1Dev9`、`V0.0.1_Dev7` 这类 tag，正式版高于同号预发布版
- 服务器访问不了 GitHub 时，可在 `config.php` 加一行 `'http_proxy' => 'http://127.0.0.1:7890'` 走代理
- **改了上游仓库或自建发行版**：把 `inc/version.php` 里的 `FB_REPO` 改成 `你的用户名/仓库名`

> 发版时只需改两处：`inc/version.php` 里的 `FB_VERSION`，以及 GitHub Release 的 tag（`V` + 版本号）。

---

## 🎬 使用效果

用户提交反馈后，机器人回复：

```
✅ 反馈已记录
编号：#FB-202610-0001
内容：网页版首屏加载过慢
状态：待跟进
查看：https://你的域名/feedback.php?no=202610-0001
```

前端列表页与后台管理页效果（替换为你的截图）：

| 反馈列表 | 管理后台 |
|:---:|:---:|
| ![反馈列表](docs/feedback-list.png) | ![管理后台](docs/fb-admin.png) |

---

## 📁 目录结构

```
EF/
├── index.php            # 云湖订阅回调入口（填到机器人控制台）
├── feedback.php         # 前端反馈页面（列表 / 详情 / 点赞）
├── fb-admin.php         # 管理员后台
├── config.php           # 配置文件（部署时修改这里）
├── migrate.php          # JSON → MySQL 迁移工具（迁移后可删）
├── .htaccess            # Apache 路由规则（可选）
├── nginx.conf           # Nginx 伪静态规则（可选）
├── inc/
│   ├── FeedbackStore.php   # JSON 数据存储类
│   ├── FeedbackStoreDb.php # MySQL 数据存储类（与上面接口一致）
│   ├── store.php           # 存储驱动加载器（按配置选择）
│   ├── Settings.php        # 运行时设置
│   ├── version.php         # 版本号 + 检查更新（发版时改这里）
│   ├── YunhuBot.php        # 云湖发送消息 SDK
│   └── functions.php       # 辅助函数
├── assets/
│   └── style.css           # 前端样式
└── data/                # JSON 模式的数据目录（自动生成）
```

---

## 🚀 快速开始

### 1. 环境要求

- PHP **7.4** 及以上
- PHP 扩展：`curl`、`json`、`mbstring`
- Web 服务器：Apache / Nginx 均可

### 2. 修改配置

编辑 `config.php`：

```php
return [
    // 云湖机器人 Token（机器人控制台获取）
    'bot_token'       => '你的机器人Token',

    // 机器人 ID（用于精确判断群里 @ 的是不是机器人自己）
    'bot_id'          => '你的机器人ID',

    // 站点根地址（不带末尾斜杠）
    'site_url'        => 'https://你的网站域名',

    // 指令 ID（云湖后台配置的指令ID）
    'command_id'      => '2713',

    // 指令触发词（用户实际发送的词）
    'command_trigger' => '/反馈',

    // 管理后台密码（务必修改）
    'admin_password'  => 'admin123456',

    // 云湖 OAuth2 登录凭据（在云湖 OAuth2 授权机器人申请，机器人ID 45781752）
    'oauth_client_id'       => '你的client_id',
    'oauth_client_secret'   => '你的client_secret',
    'oauth_redirect_uri'    => 'https://你的网站域名/oauth-callback.php',

    // 管理员白名单（允许登录后台的云湖 user_id）
    'admin_user_ids'  => [],

    // 数据存储目录（无需修改）
    'data_dir'        => __DIR__ . '/data',

    // 存储方式：json（默认）或 mysql
    'storage' => [
        'driver' => 'json',
        'db' => [
            'host' => '127.0.0.1', 'port' => 3306,
            'database' => '', 'username' => '', 'password' => '',
            'prefix' => 'fb_', 'charset' => 'utf8mb4',
        ],
    ],
];
```

### 3. 上传到服务器

将整个项目目录上传到 PHP 网站根目录，确保目录结构完整（`inc/`、`assets/` 等子目录都要上传）。

### 4. 配置云湖机器人

1. 登录 [云湖机器人控制台](https://www.yhchat.com/)
2. 进入你的机器人，找到「**配置消息订阅接口**」
3. 填入回调地址：`https://你的网站域名/index.php`
4. 开启订阅事件：
   - ✅ **指令消息事件**（`message.receive.instruction`）
   - ✅ **普通消息事件**（`message.receive.normal`）
5. 添加指令：指令 ID 填 `2713`，触发词填 `/反馈`

### 5. 配置云湖 OAuth2 登录

1. 打开 [云湖 OAuth2 授权机器人](https://yhfx.jwznb.com/share?key=2Zfa8aLUIpQw&ts=1773300327)（机器人 ID `45781752`）
2. 按提示填写应用名称和回调地址，获取 `client_id` 和 `client_secret`
3. 回调地址登记为：`https://你的网站域名/oauth-callback.php`（必须完全一致）
4. 把 `client_id`、`client_secret` 填入 `config.php`
5. 在 `admin_user_ids` 里填入管理员账号的云湖 `user_id`（白名单）

### 6. 验证

- 浏览器访问 `https://你的网站域名/index.php`，返回 `ok` 即正常
- 给机器人发 `/反馈 测试`，应收到确认回复
- 打开 `https://你的网站域名/fb-admin.php`，点「使用云湖账号登录」测试授权

---

## 📖 使用说明

### 提交反馈（两种方式）

**方式一：指令反馈（私聊 / 群聊均可）**

```
/反馈 网页版首屏加载过慢
```

**方式二：@机器人反馈（群聊）**

```
@云湖反馈助手 希望增加夜间模式
```

### 访问页面

| 功能 | 地址 |
|------|------|
| 反馈列表 | `https://你的域名/feedback` |
| 反馈详情 | `https://你的域名/feedback.php?no=编号` |
| 管理后台 | `https://你的域名/fb-admin.php` |

### 管理反馈

1. 打开管理后台 `https://你的域名/fb-admin.php`
2. 输入后台密码登录
3. 可查看统计、修改状态、删除反馈

---

## 🔧 常见问题（FAQ）

<details>
<summary><b>Q：机器人收不到消息 / 无反应？</b></summary>

1. 确认订阅地址填的是 `https://你的域名/index.php`（而不是网站根目录）
2. 确认「指令消息事件」「普通消息事件」两个订阅都已开启
3. 确认 `config.php` 里的 `bot_token` 填了真实 Token
4. 查看服务器 `data/webhook.log`，看是否有事件推送记录
</details>

<details>
<summary><b>Q：指令反馈没反应，但 @机器人 能用？</b></summary>

通常是 `command_id` 或 `command_trigger` 与云湖后台配置不一致。检查云湖后台的指令 ID 和触发词，与 `config.php` 对应。

指令消息事件的 `content.text` 通常只包含指令本身，用户内容需通过触发词正确剥离。如仍异常，查看 `webhook.log` 里的原始事件结构。
</details>

<details>
<summary><b>Q：群里 @别人 也触发了反馈？</b></summary>

需要正确填写 `config.php` 里的 `bot_id`（机器人 ID）。系统会精确比对 `at` 列表，只有 @机器人自己时才触发。
</details>

<details>
<summary><b>Q：访问 `/feedback/编号` 或 `/fb-admin` 返回 404？</b></summary>

本项目默认使用真实文件 + 参数形式，无需伪静态：
- 详情页用 `/feedback.php?no=编号`
- 后台用 `/fb-admin.php`

如需 `/feedback` 等漂亮路径，Apache 参考 `.htaccess`，Nginx 参考 `nginx.conf`。
</details>

<details>
<summary><b>Q：反馈编号都一样（不递增）？</b></summary>

请升级到最新版，早期版本的编号递增逻辑存在 bug，现已修复。
</details>

---

## 📦 数据说明

- **JSON 模式**：数据存于 `data/feedbacks.json`、`comments.json`、`history.json`，**备份这几个文件即可备份全部数据**
- **MySQL 模式**：数据存于数据库，用宝塔的数据库备份功能即可
- 调试日志位于 `data/webhook.log`（可在 `index.php` 中关闭 `log_debug` 调用）
- 发送失败的记录在 `data/fail_notify.log`
- 建议给 `data/` 目录设置可写权限（`755`，属主 www/www）

---

## 🔒 安全建议（重要）

1. **禁止 `data/` 目录公网访问**：已提供 `data/.htaccess` 与 `nginx.conf` 规则，上传后请在宝塔「伪静态」中粘贴 nginx 规则
2. 后台「站点设置 → 安全状态」会自动检测数据目录是否暴露
3. 修改默认后台密码 `admin123456`
4. 迁移完成后删除 `migrate.php`

---

## 🤝 贡献

欢迎提交 Issue 和 Pull Request。

## 📄 License

[MIT](LICENSE)
