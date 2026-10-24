# 云湖反馈机器人（PHP）

基于云湖订阅地址回调的反馈收集系统：用户通过普通指令 `2713` 或 `@机器人` 提交反馈，机器人自动记录并回复编号，管理员可在后台管理。

## 功能特性

- ✅ **指令反馈**：用户发送 `2713 反馈内容`，机器人记录并回复确认
- ✅ **@机器人反馈**：用户在群里 `@机器人 + 内容` 也可提交反馈
- ✅ **自动编号**：格式 `#FB-年份月份-本月序号`，如 `#FB-202610-0001`
- ✅ **前端展示**：反馈列表页 + 详情页（支持点赞）
- ✅ **管理后台**：登录后查看、修改状态（待跟进/处理中/已解决）、删除
- ✅ **纯 JSON 存储**：无需数据库，虚拟主机即可运行

## 目录结构

```
EF/
├── index.php          # 云湖订阅回调入口（填到机器人控制台）
├── feedback.php       # 前端反馈页面（列表/详情/点赞）
├── fb-admin.php       # 管理员后台
├── config.php         # 配置文件（改这里）
├── .htaccess          # Apache 路由规则
├── inc/
│   ├── FeedbackStore.php   # JSON 数据存储类
│   ├── YunhuBot.php        # 云湖发送消息 SDK
│   └── functions.php       # 辅助函数
├── assets/
│   └── style.css           # 前端样式
└── data/              # 数据目录（feedbacks.json 等，自动生成）
```

## 部署步骤

### 1. 修改配置

编辑 `config.php`：

```php
return [
    'bot_token'       => '你的机器人Token',          // 云湖机器人 Token
    'site_url'        => 'https://你的网站域名',      // 站点根地址（不带末尾斜杠）
    'command_id'      => '2713',                     // 指令ID（云湖后台配置的指令ID）
    'command_trigger' => '/反馈',                    // 指令触发词（用户实际发送的词）
    'admin_password'  => 'admin123456',              // 后台密码，务必修改
    'data_dir'        => __DIR__ . '/data',
];
```

### 2. 上传到服务器

将整个 `EF` 目录上传到你的 PHP 网站根目录（需 PHP 7.4+，开启 curl 扩展）。

### 3. 配置云湖机器人

1. 登录 [云湖机器人控制台](https://www.yhchat.com/)
2. 创建/进入你的机器人
3. 找到「**配置消息订阅接口**」，填入回调地址：
   ```
   https://你的网站域名/index.php
   ```
4. 开启订阅事件：
   - ✅ **指令消息事件**（`message.receive.instruction`）
   - ✅ **普通消息事件**（`message.receive.normal`）
5. 在机器人指令配置中，添加一条指令（指令ID填 `2713`，触发词填 `/反馈`）

### 4. 访问地址

| 功能 | 地址 |
|------|------|
| 反馈列表 | `https://你的网站域名/feedback` |
| 反馈详情 | `https://你的网站域名/feedback.php?no=编号`（如 `?no=202610-0001`） |
| 管理后台 | `https://你的网站域名/fb-admin.php` |

## 使用说明

**提交反馈（两种方式）：**

1. 私聊机器人发送指令：
   ```
   2713 网页版首屏加载过慢
   ```
   机器人回复：
   ```
   ✅ 反馈已记录
   编号：#FB-202610-0001
   内容：网页版首屏加载过慢
   状态：待跟进
   查看：https://你的网站域名/feedback
   ```

2. 群里 `@机器人 反馈内容`：
   ```
   @云湖反馈助手 希望增加夜间模式
   ```

**管理反馈：**

1. 打开 `https://你的网站域名/fb-admin`
2. 输入后台密码登录
3. 可修改状态、删除反馈

## 依赖要求

- PHP 7.4 及以上
- PHP 扩展：`curl`、`json`、`mbstring`
- Apache（需开启 `mod_rewrite`，用于详情页路由）

> 若使用 Nginx，请在站点配置中添加重写规则：
> ```nginx
> location /feedback {
>     try_files $uri /feedback.php?$query_string;
> }
> location /fb-admin {
>     try_files $uri /fb-admin.php?$query_string;
> }
> ```

## 数据说明

- 反馈数据存储于 `data/feedbacks.json`，备份该文件即可备份全部数据
- 调试日志位于 `data/webhook.log`（可在 `index.php` 中关闭 `log_debug` 调用）
