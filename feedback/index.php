<?php
/**
 * /feedback 列表页入口
 * 访问 https://网站/feedback 时，Nginx/Apache 会自动定位到本文件。
 * 直接复用父目录的 feedback.php（列表/详情/点赞逻辑）。
 */
require __DIR__ . '/../feedback.php';
