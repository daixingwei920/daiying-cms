# Core 1.2.87 插件付款恢复

服务端 registerSubject 报价可附带 success_url、cancel_url、product_name；前端不能指定报价或返回地址。旧解析器保持兼容。返回地址须HTTPS；本机HTTP只允许在Stripe Test使用，Live拒绝。Logo从branding.current读取站点名称和URL，不增加密钥或收款设置。

待付款链接通过现有站点密钥AES-256-GCM加密存入支付metadata，AAD绑定request_hash；普通metadata继续脱敏。通过已有会员/插件订单绑定恢复精确链接（含fragment），不再创建Checkout。已支付不返回链接；篡改、串单、错误密钥、不安全重定向fail-closed。不能用支付返回查询参数授予下载权益，仍需已验签回调、Core账本与履约。

Stripe 1.2.9仅在Test接受已验证rkcs_test_受限沙箱密钥；不增加退款入口或第二套网关。
