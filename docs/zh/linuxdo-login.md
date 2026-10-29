# Linux DO Connect 授权登录

需要本仓库后端和 XboardPortal **0.2.7 或以上**。默认关闭，配置完整后登录页自动出现「使用 Linux DO 登录」。无需新增 Composer 依赖。

## 配置与部署

1. 在 [Linux DO Connect](https://connect.linux.do/) 创建应用，填写本站信息，回调地址严格填写：

   ```text
   https://你的用户端域名/auth/linuxdo/callback
   ```

2. 在服务器 `.env` 中填写：

   ```dotenv
   LINUXDO_ENABLED=true
   LINUXDO_CLIENT_ID=应用的ClientID
   LINUXDO_CLIENT_SECRET=应用的ClientSecret
   LINUXDO_REDIRECT_URI=https://你的用户端域名/auth/linuxdo/callback
   LINUXDO_MIN_TRUST_LEVEL=0
   ```

   `MIN_TRUST_LEVEL` 可设 0–4，例如 2 表示只允许等级 2 及以上的 Linux DO 用户。Client Secret 只保存在后端，不填入主题配置或前端文件。回调域名必须与实际打开的用户端域名一致（包括 www、协议和端口）；本站 sessionStorage 按源隔离。生产环境使用 HTTPS，本地只允许 `http://localhost` 或 `http://127.0.0.1`。

3. 部署后端代码并在实际应用运行环境执行：

   ```sh
   php artisan migrate --force
   php artisan optimize:clear
   ```

   新迁移 `2026_10_29_000001_add_linuxdo_identity_to_users.php` 为 `v2_user` 增加唯一的 `linuxdo_id` 和可更新的 `linuxdo_username`。按现有部署方式重建配置缓存并重载常驻 PHP / Octane 服务。集群实例需要共享 Redis 缓存及数据库，授权状态不能存入每个进程独立的 array 缓存。

4. 在后台「主题管理」上传 `user-portal/release/XboardPortal-0.2.8.zip`，启用或升级 XboardPortal。入口使用内容哈希资源，登录页底部应显示「主题 v0.2.8」。

5. 使用真实 Linux DO 测试账号验证授权、首次绑定、再次登录与取消授权。本文实现已通过模拟 Linux DO 接口的本地自动化测试；真实 Connect 应用的回调白名单、Client ID / Secret 和网络可达性需要在目标环境验收。

## 用户行为

- **已绑定**：点击授权并同意后登录原本站账户，保留订阅、余额和账单。
- **查看绑定**：主题 0.2.8 起，在「我的账户」查看 Linux DO 绑定状态、用户名和用户 ID；需要同时部署新版用户信息接口。用户名在每次 Linux DO 授权登录后更新，站点关闭授权入口后仍可查看已保存的绑定信息。
- **首次授权**：显示 Linux DO 用户名，选择输入本站邮箱密码绑定已有账户，或切换「创建新账户」。不会仅凭 Linux DO 返回的邮箱、名称或用户名合并账户。
- **创建账户**：用户填写真实邮箱和密码；保留现有邮箱验证码、CAPTCHA、邀请码、邮箱白名单、IP 注册限制、试用套餐及注册插件钩子。Linux DO Connect 不提供可代替本站邮箱验证的真实邮箱，故不生成虚拟邮箱。
- **关闭注册**：阻止创建新账户；已有本站账户仍可绑定，已绑定账户仍可登录。
- **限制**：Linux DO 账户必须已激活、未被禁言、满足最低信任等级，本站账户不得被封禁。每个 Linux DO 身份只能绑定一个本站账户，每个本站账户只能绑定一个 Linux DO 身份；当前版本不提供用户自助解绑或替换。
- **未完成绑定**：可以取消，返回普通邮箱密码登录。绑定过程中可找回密码，回到登录后继续绑定。

## 接口与授权保护

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| POST | `/api/v1/passport/auth/linuxdo/start` | 接收当前标签页随机 verifier，生成五分钟有效的 state 和授权 URL |
| GET | `/auth/linuxdo/callback` | 校验并消费 state，服务端换取 access token、查询身份，重定向回本站 |
| POST | `/api/v1/passport/auth/linuxdo/exchange` | 当前标签页用 verifier 兑换结果；已绑定返回登录数据，否则返回首次绑定状态 |
| POST | `/api/v1/passport/auth/linuxdo/complete` | 用 `action=login` 验证本站密码并绑定，或 `action=register` 按原注册策略创建并绑定 |

授权端点固定为 `https://connect.linux.do/oauth2/authorize`、`/oauth2/token` 和 `/api/user`。令牌交换使用 Basic 认证与表单请求，带连接和请求超时，不跟随上游重定向；第三方 access token 不发送至浏览器、不持久化。

state 与授权结果各有效五分钟，state 单次使用；登录或绑定成功后消费结果票据。结果票据通过 URL fragment 返回，并立即从地址栏清理，必须配合发起标签页 sessionStorage 中的随机 verifier 使用。票据消费使用缓存锁，绑定使用身份锁、数据库事务与唯一索引，避免重放和并发重复绑定。接口有 IP 频率限制，成功响应与回调禁止缓存。本站登录凭证沿用现有 Sanctum 机制，只存入当前标签页 sessionStorage。

不要在反向代理、APM 或日志中采集 token 响应、请求体中的密码 / verifier 或完整授权回调查询参数。接入代码的错误信息不输出上游 token 内容或 Client Secret。

## 排查

- **按钮不显示**：检查 `/api/v1/guest/comm/config` 的 `data.is_linuxdo`；false 表示未启用或凭据 / 回调配置不完整。确认后端、主题版本与重载后的配置。
- **提示标签页不匹配或授权过期**：从用户端同一标签页重新点击入口；确认回调与当前用户端同源，浏览器允许 sessionStorage，且服务器共享缓存正常。
- **Connect 提示 redirect_uri 无效**：Connect 应用白名单与 `.env` 的地址必须完全一致，并确保反向代理把 `/auth/linuxdo/callback` 转发给 Laravel。
- **授权码验证失败 / 无法连接**：检查 Client ID、Secret、服务器到 Connect 的 HTTPS 出站连接与回调耗时；不要把密钥粘贴到前端或公开日志。
- **新用户无法创建账户**：检查本站注册开关、邀请码、邮箱及 CAPTCHA 设置。这些限制仍然适用。

协议行为参考 [Linux DO Connect](https://connect.linux.do/) 和 [New API 的 Linux DO provider 实现](https://github.com/QuantumNous/new-api/blob/main/oauth/linuxdo.go)。本地集成测试以 provider HTTP fake 代替真实第三方授权。
