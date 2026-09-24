# 社区便民留言板

基于 PHP 原生开发的社区便民留言板网站，支持居民求助、意见建议、失物招领等功能。

## 功能特性

- **首页展示**：滚动显示最新留言信息，统计各类留言数量
- **留言发布**：用户可提交留言，选择类型（求助/建议/失物招领），支持图片上传
- **失物认领协同**：失物招领留言支持认领全流程协同
  - 捡到物品的发布者可登记**认领凭证要求**与**保管地点**
  - 失主提交**认领凭证与认领进度**，进入待核验候选队列（多人申请按提交先后排列）
  - 发布者对候选进行**确认 / 驳回**；确认后办理状态流转为「已认领」
  - 支持**撤销恢复**为待核验、已认领后**变更认领人**（原确认人自动置驳并保留记录）
  - 凭证不完整或重复申请停留在待核验；网络重试幂等，不覆盖已有候选
  - 办理状态、候选列表、处理记录在同一事务快照中返回，始终保持一致
- **排序筛选**：支持按时间/热度排序，按类型筛选
- **后台管理**：管理员可审核、通过、拒绝、删除留言
- **响应式布局**：适配手机和电脑端

## 技术栈

- **后端**：PHP 原生开发
- **数据库**：MySQL
- **前端**：HTML5 + CSS3 + JavaScript（原生）
- **特性**：响应式设计、图片上传、分页、搜索

## 安装部署

### 环境要求

- PHP >= 7.4
- MySQL >= 5.7
- Apache / Nginx

### 安装步骤

1. 将项目文件上传到 Web 服务器根目录

2. 访问安装脚本创建数据库：
   ```
   http://your-domain/install.php
   ```

3. 安装完成后删除 `install.php` 文件

4. 访问首页：
   ```
   http://your-domain/index.php
   ```

5. 访问后台：
   ```
   http://your-domain/admin/login.php
   ```

### 默认管理员账号

- 用户名：`admin`
- 密码：`admin123`

## 项目结构

```
label-9900013/
├── index.php              # 首页
├── submit.php             # 发布留言页
├── detail.php             # 留言详情页
├── install.php            # 安装脚本
├── config/
│   └── database.php       # 数据库配置
├── database/
│   ├── migration_add_favorites.sql  # 收藏表迁移
│   ├── migration_add_reports.sql    # 举报表迁移
│   └── migration_add_claims.sql     # 失物认领协同迁移（认领设置/候选/处理记录）
├── includes/
│   ├── functions.php      # 公共函数
│   ├── claim_panel.php    # 失物认领协同面板
│   ├── header.php         # 前台头部
│   └── footer.php         # 前台底部
├── api/
│   ├── submit.php         # 留言提交API
│   ├── claim.php          # 失物认领协同API（登记/申请/确认/驳回/恢复/详情）
│   ├── report.php         # 举报API
│   └── favorite.php       # 收藏API
├── admin/
│   ├── index.php          # 后台管理页
│   ├── login.php          # 后台登录
│   ├── api.php            # 后台API
│   ├── logout.php         # 退出登录
│   └── header.php         # 后台头部
├── assets/
│   ├── css/
│   │   └── style.css      # 样式文件
│   └── js/
│       └── main.js        # 脚本文件
└── uploads/               # 图片上传目录
```

### 已部署旧版本升级

旧数据库需执行认领协同迁移脚本（新增 `messages.visitor_id` 字段及 3 张认领表）：

```bash
mysql -u root -p community_board < database/migration_add_claims.sql
```

> 说明：认领核验权限以留言发布者的访客标识（cookie `visitor_id`）判定。
> 升级前发布的历史留言没有该标识，不显示认领协同入口；升级后新发布的失物招领留言自动支持。

## 数据库配置
编辑 `config/database.php` 文件：

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'community_board');
```

## 使用说明

### 前台功能

1. **浏览留言**：首页展示所有已审核通过的留言
2. **筛选类型**：点击类型标签筛选特定类型的留言
3. **排序方式**：支持按时间或热度排序
4. **发布留言**：点击"发布留言"进入提交页面
5. **查看详情**：点击留言卡片查看完整内容

### 后台管理

1. 登录后台管理系统
2. 查看所有留言（支持状态、类型筛选和关键词搜索）
3. 审核留言（通过/拒绝）
4. 删除不当留言
5. 查看留言详情

## 注意事项

- 安装完成后务必删除 `install.php`
- 修改默认管理员密码
- 确保 `uploads/` 目录有写入权限
- 建议配置 HTTPS 保障数据传输安全
