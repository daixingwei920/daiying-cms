# 退款回调幂等与状态同步

2026-10-18，Core 1.3.0-alpha.5 开发修复；正式版仍为 1.2.86，未部署生产。

## Core 公共契约

退款身份使用 provider_id 与 provider_refund_id，不使用事件 ID 充当经济记录身份。同一退款可以先由 API 响应入账，再收到多个不同事件；这些事件只能复用同一条账本。复用时必须核对原付款、金额和币种，冲突保持拒绝，不允许把另一订单或另一金额静默视为重复。

回调支持 pending、completed、failed、cancelled。pending 不计入已退款金额、不撤销权益；pending 进入终态时，在原付款行锁/SQLite immediate 事务内更新原记录，完成金额仍不能超过实收。完成后的重复通知、迟到的 pending 通知不重复入账，也不回退完成状态。回执与账本共享事务，保留调用方事务所有权。

同事件 ID 携带不同内容仍拒绝；不同事件 ID 但相同经济退款可以正常处理。API 原记录的业务幂等键、请求哈希和原因保留，不被回调重写。退款全部完成后的权限撤销仍由 Core 原有逻辑执行。

## Stripe Provider 配套

Core 不处理 Stripe 专属 ID。独立 Stripe 插件将签名验证后的 PaymentIntent 通过 Stripe 官方 Checkout 查询接口解析为本站 Provider 所属的唯一 Checkout ID，再交给 Core。退款 ID、真实状态、金额和币种必须保留并校验。映射不唯一或币种/金额冲突拒绝处理；无本站 Checkout 的合法通知只记为中性通知。

charge.refunded 是汇总通知，不再把 Charge ID 和汇总金额当成另一笔退款。付款被拒绝的一次尝试也不能把仍可重试的 Checkout 关闭。Stripe 端点需要订阅 refund.created、refund.updated、refund.failed（以及既有付款完成/过期事件）；不得只订阅 charge.refunded。此次不修改正式端点配置。

## 范围与保守边界

本修复不发起退款，不新增支付网关、后台退款入口或数据库迁移。Logo 插件“不支持主动退款”规则不变。测试仅使用合成签名/账本，Provider 创建退款的方法禁止调用，不操作真实支付或退款。

已完成账本收到相矛盾的终态（例如 completed 后 failed），仍保留失败回执供人工核对，不擅自冲回金额、恢复付款状态或重新授予权益。Stripe 的银行退回等场景确实可能需要后续对账；本次没有扩大为跨业务权益恢复机制。该保守边界不能表述为所有支付方式的完整退款生命周期已验收。

测试覆盖原 API 入账后 created/updated、相同事件重放、同退款跨事件、pending 结算、延迟通知、身份/金额冲突、超额保护、调用方回滚、6 进程并发，以及 Stripe 原生签名控制器。实际结果见独立交付报告；Fixture 不是支付平台沙箱实付或真实结算验收。

知识库：统一 V1 源事实与派生文档同步，开发修复仅 ADMIN_DEVELOPER；生产导入、索引重建和消费者应用未执行。

## 官方依据

- [Stripe Webhook 重复与顺序规则](https://docs.stripe.com/webhooks#handle-duplicate-events)
- [按 PaymentIntent 查询 Checkout](https://docs.stripe.com/api/checkout/sessions/list)
- [Refund 字段和状态](https://docs.stripe.com/api/refunds/object)
- [失败退款与银行退回](https://docs.stripe.com/refunds#failed-refunds)
