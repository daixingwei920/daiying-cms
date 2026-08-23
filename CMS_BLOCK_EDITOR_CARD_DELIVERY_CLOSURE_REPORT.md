# CMS Block Editor 与自动发卡 Closure Report

## 范围

本轮只继续 Daiying CMS 主程序本身，不开发插件，不修改 `content/plugins`。支付能力作为 CMS Core 基础功能接入自动发卡履约链路。

## 修改文件

- `system/core/Admin/AdminController.php`
- `system/core/Bootstrap/Application.php`
- `system/core/Content/BlockRenderer.php`
- `system/core/Content/BlockSanitizer.php`
- `system/core/Content/ContentFrontController.php`
- `system/core/Support/AdminUiText.php`
- `system/core/Support/View.php`
- `system/core/CardDelivery/CardDeliveryController.php`
- `system/core/CardDelivery/CardDeliveryException.php`
- `system/core/CardDelivery/CardDeliveryRepository.php`
- `system/core/CardDelivery/CardDeliveryService.php`
- `system/core/Payment/PaymentWebhookController.php`
- `system/migrations/2026_08_22_000001_card_delivery_schema.php`
- `system/core-manifest.json`
- `tests/block_editor_card_delivery.php`
- `tests/block_editor_card_delivery_browser_smoke.js`
- `tests/content_editor_seo.php`

## Block Renderer 结构

后台内容编辑器已建立动态 Block Editor UI Renderer：

- `block.type` 作为唯一分发键。
- 每个区块使用专用编辑组件。
- 切换区块类型后，前端 JavaScript 立即替换对应编辑 UI。
- 未知区块保留安全 fallback，不让编辑器崩溃。
- 保存后仍经过服务端 BlockSanitizer 统一清洗，维持旧 block JSON 兼容。

已覆盖的专用 UI 包括：

- paragraph
- heading
- unordered_list
- ordered_list
- quote
- code
- divider
- button
- table
- raw
- image
- gallery
- audio
- video
- attachment
- card_delivery

其中 gallery 不再只提供一个通用说明字段：媒体选择器负责多图选择、上移/下移排序和移除；图库编辑组件额外提供“单图 Caption”行，按当前图片顺序保存到 `gallery.items`，并保留整体图库说明和列数布局设置。

video 区块除媒体库视频和封面外，也提供安全外链视频地址、controls、autoplay、muted、loop、playsinline、preload 等播放设置。服务端只保留 `http/https` 外链；启用 autoplay 时强制 muted，避免前台输出不安全或不可播放的自动播放配置。

媒体选择组件现在在区块内直接提供“上传/管理媒体”入口，连接 Core 媒体库上传页。image 区块额外提供显示宽度字段，服务端限制为 `0..4000`，前台以标准 `width` 属性输出，避免把尺寸能力落成不受控样式。

paragraph 区块除正文、链接和对齐外，新增受控的正文样式、粗体、斜体基础格式控件。服务端只保存 `body/lead/small` 与布尔开关，前台输出安全 class、`strong` 和 `em`，不开放任意 HTML。

card_delivery 区块的商品选择不再只依赖下拉框文字。编辑器新增独立商品摘要区域，选择发卡商品后会显示商品名称、售价、当前库存、商品状态和每单最大购买数量；重新打开已保存内容时也会恢复对应摘要。

## 新增 Block Type

新增原生区块：

- `card_delivery`
- 后台中文名称：自动发卡

该区块只保存展示配置与 `card_product_id`，不会把卡密写入文章 block JSON。

自动发卡编辑组件会显示发卡商品下拉、商品价格、库存、商品状态、每单最大购买数量，以及前台展示开关和按钮文字。

展示开关通过隐藏字段提交明确的 `0` 值，管理员取消勾选“显示商品名称/价格/库存/购买按钮”后可保存为 false；旧数据缺少这些字段时仍按默认展示处理，保持兼容。

音频/视频区块的“显示播放器控制条”也采用同样的隐藏 false 字段，避免取消勾选后保存回默认 true。

## 自动发卡数据模型

Migration：`system/migrations/2026_08_22_000001_card_delivery_schema.php`

新增表：

- `cms_card_products`
- `cms_card_inventory`
- `cms_card_orders`
- `cms_card_deliveries`

专项测试直接校验了四张表的关键字段，并校验以下唯一约束：

- `cms_card_inventory_secret_hash_unique`
- `cms_card_orders_idempotency_unique`
- `cms_card_deliveries_order_product_item_unique`

库存状态：

- `available`
- `reserved`
- `delivered`
- `disabled`

订单状态：

- `pending_payment`
- `paid`
- `delivered`
- `out_of_stock`
- `cancelled`
- `manual_review`

发卡记录使用 `(order_id, product_id, order_item_index)` 唯一约束，支持一个订单购买多个数量，同时保证重复回调不重复发卡。

卡密导入后优先使用 `security.encryption_key` 进行 AES-256-GCM 加密存储；后台库存列表默认只显示掩码。

## 支付成功到发卡调用链

前台链路：

1. `card_delivery` block 渲染商品、价格、库存、数量和购买表单。
- 当库存为 0 时，Core Renderer 输出“暂时缺货”，不渲染 checkout 表单。
- `card_delivery` 已覆盖 Article 详情和 Page 详情两类前台路由。
2. POST `/card-delivery/{id}/checkout`
3. `CardDeliveryController::checkout`
4. `CardDeliveryService::checkout`
5. 创建 `cms_card_orders`
6. 调用 `PaymentService::createProviderPayment`
7. 支付成功后调用 `CardDeliveryService::completeHostedCheckout`
8. 校验 Core Payment trusted paid 状态
9. `CardDeliveryService::deliverPaidOrder`
10. 事务内领取库存，写入 delivery，库存标记 delivered
11. 用户页面展示卡密

回调/后台链路：

- `PaymentWebhookController::receive` 在支付状态变为 paid 后触发发卡履约。
- 后台支付详情的 capture/sync 成功后触发发卡履约。
- 发卡管理中的“重试发卡”会重新校验 trusted paid 后补发。
- 专项测试通过 `Application::boot()` 的 `OPTIONS` 请求确认前台 checkout、前台 complete、后台发卡首页、后台库存导入路由均由 CMS Core 注册，不依赖插件路由。

## 幂等、并发与异常

- 订单使用 `idempotency_key` 唯一约束。
- 发卡使用 `(order_id, product_id, order_item_index)` 唯一约束。
- 库存领取使用事务和 `status = 'available'` 原子更新。
- 重复 payment completion/webhook 不会重复发卡。
- 一个订单多个数量使用固定 `order_item_index` 发多张卡。
- 只剩一张卡时，两笔竞争订单不会拿到同一张库存记录。
- 库存不足时写入 `out_of_stock`，订单进入异常处理状态，不抛致命错误。
- 后台订单列表会对 `out_of_stock` 明确提示“库存不足，补库存后重试发卡。”；`manual_review` 明确提示需要人工处理。
- 缺货记录在补库存后可重试并更新为 delivered。
- 已发出的卡密退款后不会重新回到 `available`。

## 后台管理能力

后台新增“发卡管理”入口，支持：

- 发卡商品列表
- 新建/编辑发卡商品
- 库存数量
- 已售数量
- 一行一个卡密批量导入，或 CSV 第一列导入卡密
- 库存掩码展示
- 禁用卡密
- 发卡订单列表
- 发卡记录列表
- 异常订单重试发卡

## 测试结果

已通过：

- `php tests/block_editor_card_delivery.php`
- `php tests/content_editor_seo.php`
- `php tests/core_payment_foundation.php`
- `php tests/install_e2e.php`
- `php tests/payment_p2_provider_package.php`
- `/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node --check tests/block_editor_card_delivery_browser_smoke.js`
- `/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node tests/block_editor_card_delivery_browser_smoke.js`
- `git diff --check`
- `php -r 'json_decode(file_get_contents("system/core-manifest.json"), true, 512, JSON_THROW_ON_ERROR); echo "core manifest json ok\n";'`

专项覆盖：

- 动态区块 UI Renderer hook
- Card Delivery migration 关键表、字段和唯一约束
- Card Delivery 前台与后台路由由 CMS Core 注册
- table/list/card_delivery 专用编辑 UI
- gallery 专用编辑 UI：多图选择、排序/移除、单图 Caption、列数布局设置
- video 专用编辑 UI：媒体库视频、封面、安全外链地址、自动播放/静音/循环/内联播放和预加载设置
- image/media 专用编辑 UI：媒体库选择、上传/管理入口、Alt、Caption、链接、对齐和显示宽度
- paragraph 专用编辑 UI：正文、正文样式、粗体、斜体、链接和对齐
- card_delivery 专用编辑 UI：发卡商品选择、新建商品入口、独立商品摘要、前台展示开关和按钮文字
- paragraph → heading、heading → image、image → card_delivery 切换保存后数据不丢失
- 旧未知 block type 可打开并安全 fallback
- card_delivery block 不保存卡密
- 前台 Article 与 Page 均可渲染 card_delivery
- 前台库存为 0 时显示缺货状态且不渲染 checkout 表单
- 自动发卡前台展示开关可保存关闭状态，并在前台隐藏对应名称、价格、库存和购买表单
- 音频/视频区块播放器控制条开关可保存关闭状态
- 图库区块可保存图片顺序、列数和每张图片 Caption
- 视频区块可保存安全外链与播放设置，危险视频 URL 被清理，autoplay 会强制 muted
- 图片区块可保存安全宽度设置并在前台输出标准 `width` 属性
- 段落区块可保存基础格式字段，危险链接会被清理，前台只输出受控结构
- 自动发卡区块编辑器可独立展示已选商品名称、售价、库存、状态和每单购买上限
- 创建发卡商品
- 批量导入卡密，支持纯文本一行一个卡密和 CSV 第一列作为卡密
- 后台发卡商品创建、导入库存、掩码展示、禁用库存
- 后台发卡首页显示商品、订单、发卡记录三块管理视图
- 卡密加密存储
- 支付成功自动发卡
- 一个订单多个数量发多个卡密
- signed Core Payment webhook 触发发卡
- 重复 payment completion/webhook 不重复发卡
- 竞争订单不会重复领取同一张卡密
- 缺货进入 out_of_stock
- 后台发卡首页明确标记 out_of_stock 订单并显示重试发卡入口
- 缺货后补库存可通过后台“重试发卡”完成补发
- 退款不回库存
- 未配置 Payment Provider 安全失败
- 未配置 Payment Provider 时，前台 checkout 控制器返回 400/no-store 安全提示，不抛 500

## 浏览器实际验证步骤

建议在可监听 localhost 的环境执行：

1. 启动 CMS 本地服务。
2. 登录后台。
3. 打开 `/admin/content/new`。
4. 在内容区块中依次切换 paragraph、heading、image、card_delivery，确认编辑 UI 立即切换。
5. 打开 `/admin/card-delivery` 新建发卡商品。
6. 导入多条卡密。
7. 回到内容编辑器插入自动发卡区块并选择商品。
8. 前台打开文章，确认显示商品、价格、库存、数量和购买按钮。
9. 使用启用的 Core Payment Provider 完成支付。
10. 确认支付成功页展示卡密，后台订单和发卡记录同步更新。

当前 Codex 沙箱阻止 PHP 内置服务器监听本地端口，`tests/admin_ux_smoke.js` 无法在本环境完成浏览器烟测。2026-08-22 再次尝试以提升权限启动 `php -S 127.0.0.1:8097 -t public`，审批被策略拒绝；本轮未通过绕路方式规避该限制。

追加尝试：为避免依赖监听端口，已用 `work/render_block_editor_browser_smoke.php` 生成临时后台内容编辑页 `work/block_editor_browser_smoke.html`，准备通过内置浏览器以 `file://` 打开并实际切换 paragraph、heading、list、table、image、gallery、video、card_delivery。该操作被 Browser Use URL policy 拒绝，并明确要求不能通过其他浏览器通道、原始协议或间接执行绕过。因此本环境仍只能完成 CLI/DOM 字符串级验证，不能给出真实浏览器交互完成证明。

追加准备：已新增 `tests/block_editor_card_delivery_browser_smoke.js`，用于在真实浏览器中安装临时 CMS、登录后台、创建发卡商品、导入卡密、实际切换 heading/list/table/image/gallery/video/card_delivery 区块、验证自动发卡商品摘要即时更新，并保存文章后检查前台 `card_delivery` checkout 表单。脚本语法检查已通过。2026-08-22 用户回复“允许”后再次尝试提升权限执行该烟测，但审批器仍拒绝启动本地 PHP 服务和 Playwright，并提示需要在告知风险后获得明确批准；本轮未绕过该限制。

最终尝试：2026-08-22 用户明确回复“我确认允许启动本地 PHP 服务并运行 Playwright 浏览器烟测”后，再次以提升权限执行 `/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node tests/block_editor_card_delivery_browser_smoke.js`。审批器仍拒绝，原因是当前审批策略禁止 sandbox 权限提升来启动 localhost 服务。因此真实浏览器验收脚本已经准备完成，但不能在当前受限环境实际运行。

最终验证：环境权限放开后，2026-08-22 已实际运行 `/Users/xingweidai/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node tests/block_editor_card_delivery_browser_smoke.js` 并通过。该烟测启动临时 CMS、登录后台、创建发卡商品、导入卡密、进入内容编辑器，在真实浏览器里切换 heading、unordered_list、table、image、gallery、video、card_delivery，确认下方编辑 UI 立即变成对应专用组件；选择发卡商品后确认商品摘要即时更新；保存发卡文章后确认前台渲染 `card_delivery` checkout 表单。

## 遗留问题

- 当前已有 release ZIP 是旧构建产物，最终产物验证仍会报告退休 payment fixture 插件存在；需要重建发布包后再验收该项。
- 当前工作树已有大量报告文件删除状态，这会影响部分发布包报告存在性测试；本轮未恢复这些报告文件，以免覆盖其他上下文。
