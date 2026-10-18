# AI 图像与 Logo 插件实施前审计

日期：2026-10-18。基线：d578a05dae9742d177167cc4665482cc80cc9ea7，1.2.85 未发布；本开发分支不构成 Stable。

- AI：AiService → AiGateway → AiProviderInterface。文字 AiRequest/AiResponse 已公开，保留契约；AiModel 能力已有但未知模型并未严格拒绝，不可据此推导图像能力。
- 配置：SiteAiSettingsRepository 单站一组 provider/base_url/model，加密保存 API Key；timeout_seconds 原样保存。需要增加独立 image_model，保留文字 model 与 Timeout。插件不得持有 runtimeConfig 或密钥。
- 队列：QueueService 已持久化，但 claim 是先 SELECT 再无条件 UPDATE，缺少并发 CAS；running 无恢复机制。AiJobService 无结果存储，默认 AiService.gateway 未接入 ledger/queue，cli 无通用 AI worker。图像任务需持久状态与单次 dispatch 边界，超时/崩溃后未知结果禁止自动重发，恢复已落盘响应应不重复调用服务商。
- 媒体：MediaLibrary 验证 MIME/大小、去重和派生图；默认本地存储在公开 uploads，/media 也公开图片，不能依靠隐藏 ID 实现付费下载。需通用私有媒体存储与访问边界，原图仅经授权插件下载，公开预览使用水印衍生图。
- SDK：PluginContext 已有 ai/data/frontUsers/payments/queue；普通插件不得 PDO。新增 scope 约束图像 facade，复用 Core 注入 PDO/Media；业务插件独立目录，不混入 Core 发行内容。
- 支付：PluginPaymentService 已有 server quote、create/status/fulfill/isEntitled；Core PaymentService 支持 webhook receipt、退款与退款后权益撤销；普通插件无退款发起接口。采用先生成成功再买下载，失败不创建订单；订单/回调幂等复用现有 Core，不新建网关。退款由站点原有管理入口执行。
- 费用/权限：既有 AiUsageLedger、AiQuotaService、AiAuditLogger；现有 quota 只按插件统计请求，不足以并发用户配额。图像新增用户原子预占与模型费用策略，费用未知明确 null，不伪造真实账单；审计不写密钥和原图数据。

适配器范围：只对文档明确支持的 OpenAI GPT Image 1.5 提供 generation/edit 协议实现；其他兼容端点、服务商、模型默认拒绝，文档声明和真实账号调用验证分别记录。

Owner 已授权开发测试；不得修改生产站或配置，不得发布 Stable。另一个窗口负责 LocalPluginPackageInstaller 启用修复，本分支不修改该文件。
