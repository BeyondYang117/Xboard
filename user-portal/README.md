# Xboard 用户主题 · V2

Vue 3 + TypeScript 用户中心，版本 `0.2.6`。提供三种视觉风格，共用真实 Xboard API：

- **星旅**：日系轻幻想，天空、远山与旅行者，默认风格。
- **夜航**：哥特悬疑，纸张、墨绿、月下庄园。
- **简洁**：清晰的面板排版与可选品牌色。

插画为项目原创 SVG。界面使用系统字体，不请求外部字体服务。支持浅色、深色、移动端导航、键盘操作与减少动效偏好。

## 安装

这是通过「主题管理」安装的主题包，与功能插件分开管理。

1. 部署本次仓库的后端改动，并按现有项目流程清理应用缓存。
2. 在后台「主题管理」上传 `release/XboardPortal-0.2.6.zip`，启用 `XboardPortal`。
3. 在主题配置中选择视觉风格、默认明暗、标语、封面与客户端下载地址。站点名称、Logo 和注册策略沿用站点配置。
4. 用测试账户完成登录、订阅与支付渠道验收后，再开放给用户。

ZIP 根目录包含 `config.json`、`dashboard.blade.php` 与 `assets/`，不需要上传源码或 node_modules。升级包需提高版本号；已有主题配置会保留。生产构建只加载真实接口界面，不包含演示入口。

本次后端改动须一同部署：

| 文件                                                                                  | 用途                                                                                             |
| ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| `app/Services/ThemeService.php`                                                       | 切换时同时更新前台实际读取的 `frontend_theme` 与管理端的 `current_theme`，新资源就绪后清理旧资源 |
| `app/Http/Controllers/V1/User/UserController.php`、`app/Http/Routes/V1/UserRoute.php` | 增加 POST `/api/v1/user/logout`，仅撤销当前登录令牌                                              |
| `app/Http/Controllers/V1/User/StatController.php`                                     | 日用量只读取每日记录，避免月汇总重复计入                                                         |

`0.2.1` 还需同步部署 `app/Http/Controllers/V1/User/CommController.php` 与 `app/Http/Resources/PlanResource.php`：用户配置接口提供后台 APP 的客户端名称 / 版本和下载地址，`UserController` 的当前订阅套餐说明与套餐列表共用模板渲染，保留原有价格格式。

仅上传主题 ZIP 到未包含这些修改的旧版本，不能保证完整兼容。本次开发没有修改或启用现有生产主题。

## 0.2.6 零容量套餐被误判售罄

数据库迁移将 `capacity_limit` 默认设为 `0`，字段定义为「不限制人数」。原 `PlanService::hasCapacity()` 只把 `null` 视为不限量，将 `0` 当成已满，导致后台已开启显示和新购的套餐仍不在用户接口中返回。主题从当前订阅补回套餐后，用户可能只看到自己的月付套餐，长期分类数量为 0。

**这次必须部署后端文件 `app/Services/PlanService.php` 和 `app/Http/Resources/PlanResource.php`，仅上传主题 ZIP 无法恢复被接口过滤的套餐。** 按现有部署方式重载常驻 PHP 服务，随后在用户端点「刷新套餐」或刷新页面。

修复后 `null` / `0` 都表示不限制人数，资源接口统一返回 `null`；正数容量仍按有效订阅人数判定是否已满，隐藏与关闭新购的套餐仍不公开。没有批量改写套餐配置或扩大正数容量。主题同步允许容量为 0 的套餐购买，并新增刷新套餐入口。

回归数据包含月付 5.90 元、长期流量 28.90 元、长期套餐已有 3 位有效用户且容量采用数据库默认值 0。修复前用户接口返回空列表，修复后返回两种套餐；正数限量售罄仍被过滤。

## 0.2.5 套餐分类

「套餐选购」下分为「订阅套餐」「长期流量」，两个入口都显示套餐数量。订阅类只显示月、季、年等周期；长期流量只显示一次性价格，不受月付筛选影响。只有长期流量可选时自动打开该分类。若同一套餐同时配置周期与一次性价格，会分别进入对应分类，结算周期保持与所选分类一致。

卡片显示实际流量重置规则，长期有效不意味着流量一定不重置；跟随系统但旧接口无法提供规则时显示「以站点规则为准」。分类不改变后台购买、续费、售罄或结算规则。本次分类调整仅需升级主题包。

## 0.2.4 套餐展示修复

套餐页默认显示「全部套餐」，同时展示周期套餐和一次性长期流量套餐。每张卡片按自身支持的周期报价，多周期套餐可单独选择，购买时传递卡片选中的真实周期；顶部仍可筛选月付、年付和长期流量。重置包仍通过当前订阅专用入口购买。

主题兼容旧版订阅接口返回的原始套餐说明模板，在富文本清理前替换流量、速度、设备数和重置规则。可同步部署 `CommController.php`，让用户配置接口返回系统重置规则；旧接口无法提供规则且套餐跟随系统时，显示「按站点规则」，不猜测重置时间。

`PlanResource.php` 同步将元转换为整数分，避免 19.90 元在套餐接口中返回浮点尾差。

后台仍仅向用户返回已显示、允许销售且容量充足的套餐。创建了套餐但关闭「显示」/「销售」或容量已满时，不会出现在公开套餐列表中；当前隐藏套餐只作为当前用户的续费入口合并展示。

## 0.2.3 升级缓存修复

入口 JS 与 CSS 改用 Vite 内容哈希文件名，打包时从构建清单写入 Blade，避免浏览器或 CDN 命中旧的 `portal.js` 后继续运行旧代码。登录 / 注册页底部显示实际前端构建版本（不取服务器注入的版本）。

升级后打开登录页，底部版本应与安装包一致（当前为「主题 v0.2.6」）。若仍是旧页面，清理 CDN 的首页 HTML 缓存并刷新；静态资源已换地址，无需关闭整站缓存。PHP 使用常驻进程或 OPcache 时，按现有部署流程重载服务。没有这个版本标记就不能确认新前端已经加载。

## 0.2.2 紧急修复

- Turnstile 异步脚本改用 `onload` 回调，不再调用 `turnstile.ready()`；重复挂载共用加载任务，加载失败可在重新进入注册页时重试。reCAPTCHA 继续等待其 `ready()`。
- 退出时无论接口成功、旧后端返回 404 或网络失败，都会清除当前标签页的登录凭证与账户数据并返回登录页。服务器未确认撤销时会明确提示，不能视为服务器令牌已失效。
- 仅升级本次主题 ZIP 即可修复这两个前端问题。要保证服务器撤销当前令牌，仍需同步部署上述 `/user/logout` 后端改动，并按部署方式清理路由缓存、重载常驻服务。
- 若线上仍提示路由不存在，先用 `php artisan route:list --path=api/v1/user/logout` 确认实际运行版本，再执行项目现有部署流程。勿将重置订阅链接或删除全部会话作为退出的替代操作。

## 客户端与套餐说明配置

Windows、macOS、Android 优先读取后台「APP 设置」中的 `*_version` 和 `*_download_url`。例如填写 `v2rayN` 与下载 URL，用户端就显示 `v2rayN` 并使用该下载地址；字段名称仍沿用后台的「版本」。后台未配置时才使用主题下载地址，再未配置则使用内置客户端。

配置只有名称时不会拼接其他客户端的下载链接；下载地址无效时显示联系支持提示。自定义客户端仅在名称与平台匹配已支持的客户端时提供一键导入，v2rayN / v2rayNG 等客户端提供复制订阅与手动添加说明，避免唤起错误应用。

套餐说明中的 `{{transfer}}`、`{{speed}}`、`{{devices}}`、`{{reset_method}}` 会替换为套餐实际权益与流量重置规则，包括已隐藏但允许续费的当前套餐。跟随系统的重置规则以系统设置为准；替换不改写数据库中的原始模板。

## 已接入功能

- 登录、注册、邮件验证码、找回密码、邮件快捷登录与退出。
- 真实用户、订阅、余额、套餐权益、到期、流量重置与每日计费用量。
- 客户端下载、复制订阅链接与客户端导入入口。
- 套餐周期、优惠码、续费、流量重置、服务端结算明细、订单记录、取消与状态轮询。
- 支付二维码、本地生成二维码、跳转支付与余额抵扣后的零元结算；支付状态以服务端为准。
- 公告、帮助文章搜索和详情、工单创建 / 回复 / 关闭、邮件提醒偏好保存。
- 加载、空内容、失败重试与会话过期处理。

登录凭证保存在当前标签页的 sessionStorage；订阅链接不会持久化到 localStorage。主题偏好保存在 localStorage。接口使用同源 `/api/v1`，不需要把用户凭证发送到独立前端域名。帮助与公告富文本经过 DOMPurify 清理。

## 验收边界

- Turnstile、reCAPTCHA v2/v3 已实现接入，仍需使用站点自己的域名和密钥验收；本地测试关闭 CAPTCHA。
- `StripeCredit` 内嵌信用卡支付暂未接入卡片 SDK，界面会禁用该方式；可使用二维码或跳转支付渠道。
- 真实第三方支付、回调链路、邮件送达与系统客户端导入需在目标环境验收。打开客户端不等于已连接。
- 未配置客户端下载信息时，内置链接指向官方发布页；运营者可在后台 APP 设置中配置本站客户端，主题下载地址作为备用。自定义封面使用可公开访问的 HTTPS 图片。
- 用户名、套餐和交易数据由现有 Xboard 接口提供；本主题没有添加签到、积分或虚假在线状态。

## 本地开发与构建

```sh
cd user-portal
npm ci
npm run dev
```

默认 `http://127.0.0.1:5173/` 为演示界面，使用示例数据，可在顶部切换三种皮肤与订阅状态，不调用真实业务接口。

需要接入开发后端时：

```sh
VITE_API_PROXY=http://127.0.0.1:8000 npm run dev
```

访问 `http://127.0.0.1:5173/?live=1`。此模式会真实登录、创建订单和提交工单，应连接测试环境。

```sh
npm test
npm run package:theme
```

打包前执行 Vue / TypeScript 检查，产物为 `release/XboardPortal-0.2.6.zip`。`npm run build` 单独输出静态资源到 `dist/`；正式使用应通过主题包安装，由 Blade 注入站点配置。

## 隔离集成测试

需要 Docker。测试镜像使用 PHP 8.3，仓库挂载为只读；数据库、主题安装目录、缓存与 public 目录均位于一次性容器的 `/tmp`，不接触项目真实数据库。

在仓库根目录构建镜像：

```sh
docker build -t xboard-portal-test:local -f user-portal/tests/Dockerfile .
```

运行后端测试：

```sh
docker run --rm -v "$PWD:/app:ro" \
  -e APP_ENV=testing \
  -e APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE= \
  -e CACHE_DRIVER=array -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \
  -e LOG_CHANNEL=stderr -e APP_SERVICES_CACHE=/tmp/xboard-services.php \
  -e APP_PACKAGES_CACHE=/tmp/xboard-packages.php -e VIEW_COMPILED_PATH=/tmp \
  xboard-portal-test:local php vendor/bin/phpunit --bootstrap vendor/autoload.php \
  --no-configuration --do-not-cache-result tests/Feature/Theme
```

启动浏览器集成环境（先生成主题包）：

```sh
docker run --rm --name xboard-portal-sandbox \
  -p 127.0.0.1:5182:8000 -v "$PWD:/app:ro" \
  -e PORTAL_SANDBOX=1 -e APP_ENV=testing \
  -e APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE= \
  -e CACHE_DRIVER=array -e DB_CONNECTION=sqlite -e LOG_CHANNEL=stderr \
  -e APP_SERVICES_CACHE=/tmp/xboard-services.php \
  -e APP_PACKAGES_CACHE=/tmp/xboard-packages.php -e VIEW_COMPILED_PATH=/tmp \
  xboard-portal-test:local sh -c \
  'php user-portal/tests/sandbox.php setup && test -f /tmp/xboard-portal-sandbox/ready && php -S 0.0.0.0:8000 -t /tmp/xboard-portal-sandbox/public /app/user-portal/tests/sandbox.php'
```

访问 `http://127.0.0.1:5182/`。一次性测试账户：`traveler@example.test` / `StarVoyage2026!`。该账户与余额仅存在于容器中；不要将此测试脚本部署为公网入口。停止并清理：`docker stop xboard-portal-sandbox`。

前端测试覆盖接口鉴权、支付响应、价格单位、订阅状态、流量倍率和富文本清理；后端测试覆盖 ZIP 安装 / 升级 / 切换、配置注入、当前会话注销和每日用量筛选。
