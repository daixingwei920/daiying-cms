# Core AI 图像接口（开发版）

Core 1.3.0-alpha.1 / 图像契约 0.1.0-alpha。不是 Stable。1.2.85 维护签名包不含本功能。

## 架构与配置

文字链保持 AiService → AiGateway → AiProviderInterface / AiRequest / AiResponse。`text.generate` 是旧 `text_generation` 的兼容别名，不删除旧能力。图像使用 AiService::images(root) → AiImageService → AiImageProviderInterface；插件只取得 PluginContext::images() 的 PluginAiImageService，不持有 PDO、runtimeConfig 或密钥。

站点 AI 设置新增 image_model（默认空，关闭图像）、image_daily_limit（默认10）、image_minute_limit（默认2）、image_cost_micro（默认NULL，未知）。所有现有 Provider、base_url、加密 API Key、文字 model、timeout_seconds 保留；图像请求不能覆盖 Provider、Key、端点、模型或 Timeout。支持范围是官方 OpenAI 端点的 gpt-image-1.5。其他兼容接口与模型明确 `image_model_unsupported`，不根据名称猜能力，不进行图像云端 fallback。

AiImageProviderInterface::models() 声明每模型能力，assertSupported(config) 验证端点/模型组合，execute(request, config, inputPng) 返回服务端 PNG bytes 和 usage。文字契约不强迫适配器实现图像。OpenAiImageProvider 使用 /images/generations JSON 和 /images/edits multipart；PNG、n=1、三个画布尺寸和 low/medium/high 质量。base64、MIME、尺寸、响应体上限均验证。文档声明不等于任一账号已实测；models 元数据里的 account_verified 始终保守为 false，账号证据见测试报告。

官方资料：
- https://developers.openai.com/api/reference/resources/images/methods/generate
- https://developers.openai.com/api/reference/resources/images/methods/edit

## 插件 SDK

声明 capabilities: ai.use、ai.image。通过 Core 已认证的前台会员 + CSRF POST 调用：

```php
$job = $context->images()->submit($idempotencyKey, [
  'prompt' => '设计文字：戴影',
  'operation' => 'image.generate', // 或 image.edit
  'source_job' => '', // edit 必须为同插件同会员已完成作品
  'size' => '1024x1024',
  'quality' => 'low',
  'background' => 'transparent',
]);
$state = $context->images()->find($job['job_id']);
$history = $context->images()->history(); // 最近100项
```

imageBytes(jobId) 是同身份的服务端方法；插件下载控制器必须另外验证站点支付权益。返回状态不包含密钥、文件系统路径或原始请求。请求绑定插件 ID、会员 ID、当前插件版本；插件停用/卸载后拒绝访问与调度。

Core 不另外开放匿名 REST 图像入口。Logo 插件提供 /ai-logo/submit、status、history、preview、checkout、fulfill、download；写入接口一律 CSRF POST。预览加水印，下载验证所有权和 Core entitlement；未经支付无法经 /media/{id} 或公开 uploads 绕过。

## 任务队列、恢复、成本

先执行 `php cli.php migrate`，再以 Cron 或常驻调度器调用 `php cli.php run-ai-image-jobs 10`。例如每分钟一次；本任务不安装系统 Cron。worker 只消费 ai.image 类型，避免将其他插件队列误判为无 handler。队列采用原子 CAS claim；图像另有请求预占、文件锁与唯一幂等键。

queued → dispatching → response_ready → completed。明确拒绝进入 failed；网络超时、5xx、进程在 dispatch 后崩溃等无法确认是否计费的结果进入 uncertain。**uncertain 不自动重试**，管理员先核对服务商用量/请求记录。不能承诺网络中断下远端 exactly-once。

成功响应先以0600文件暂存到受保护 storage/ai-images，媒体入库失败可恢复，不再发起模型调用。只有未开始 dispatch 的 queued 任务可以安全重新排队。站点配置变化在调度前检测并拒绝。任务中不持久化 API Key。

用户UTC日配额与每分钟上限对所有插件合计，事务内锁定后原子预占，重复键不多占额。配额统计任务尝试，包含失败/结果未知任务，避免无限消耗服务商。配额不是扣款。

cms_ai_image_jobs 保存 usage、成本状态和管理员预估值（百万分之一 USD）；未知成本为 NULL，不冒充免费。成功调用复用 cms_ai_usage_ledger；结果不是服务商最终账单，供应商费用由其账单确认。cms_ai_audit_events 记录生命周期与用户，不记录 Key、原图或完整提示词。

## 媒体与限制

PrivateMediaStorageProvider 使用 storage/private-media（必须沿用站点 storage 的禁止直接访问规则），复用 MediaLibrary 的验证、去重、元数据与派生图。公共媒体控制器拒绝 private；普通媒体 VM 不返回私有 URL。默认公开 uploads 行为保留。PNG/WebP/AVIF 重编码明确保存 alpha，修复透明度丢失。

当前仅一个文档明确支持的模型组合；未实现其他 Provider、任意用户上传编辑、多输入/蒙版、原生矢量模型或真实支付网关新能力。SVG 由插件进行真实单色路径描摹，不嵌入位图，不冒充原生彩色 SVG。中文书法不能保证所有字准确，需逐字人工核对。私有媒体暂不纳入通用公开媒体导出恢复流程；站点备份应同时覆盖数据库和受保护 storage。

部署假设：单站Core数据库和受保护storage可由其worker访问。多节点需共享可靠的响应/私有媒体存储；本任务未验证分布式文件系统或多节点故障。
