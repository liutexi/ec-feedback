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
- 🔐 **管理后台**：登录后可查看、修改状态（待跟进 / 处理中 / 已解决）、删除反馈
- 💾 **纯 JSON 存储**：无需数据库，虚拟主机 / 共享主机即可运行
- ⚡ **零依赖**：纯 PHP 实现，无需 Composer，开箱即用

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
├── .htaccess            # Apache 路由规则（可选）
├── nginx.conf           # Nginx 伪静态规则（可选）
├── inc/
│   ├── FeedbackStore.php   # JSON 数据存储类
│   ├── YunhuBot.php        # 云湖发送消息 SDK
│   └── functions.php       # 辅助函数
├── assets/
│   └── style.css           # 前端样式
└── data/                # 数据目录（feedbacks.json、webhook.log，自动生成）
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

    // 数据存储目录（无需修改）
    'data_dir'        => __DIR__ . '/data',
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

### 5. 验证

- 浏览器访问 `https://你的网站域名/index.php`，返回 `ok` 即正常
- 给机器人发 `/反馈 测试`，应收到确认回复

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

- 反馈数据存储于 `data/feedbacks.json`，**备份该文件即可备份全部数据**
- 调试日志位于 `data/webhook.log`（可在 `index.php` 中关闭 `log_debug` 调用）
- 建议给 `data/` 目录设置可写权限（`755`，属主 www/www）

---

## 🤝 贡献

欢迎提交 Issue 和 Pull Request。

## 📄 License

[MIT](LICENSE)
