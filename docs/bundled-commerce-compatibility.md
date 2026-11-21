# Core1.2.102 随包 Commerce 兼容修复

Owner 于2026-11-21授权把随包兼容修复纳入来源核验、侧栏修复的联合发布，全部完整测试及正式门禁通过才发布。

完整矩阵在 `official_restaurant_contract.php:255` 复现错误“餐厅结账需要官方 Commerce 1.0.8 或更新的兼容版本”。原主分支aeaf942在独立克隆中同样失败；相关Core支付、餐厅、Commerce和测试文件与候选一致。新装包实际包含餐厅1.0.2与旧Commerce基础版，后者缺少两个接口。失败证据保留在 `/root/daiying-release102-full-20261121-yvso8zkf`，不得改成历史PASS。

最小修复只在随包 `content/plugins/official.commerce/src/CommerceRepository.php` 补充两个辅助方法，复用已发布Commerce1.0.11源码已有接口的方式，不整体替换其源码或包：

- `updatePendingOrderClaim()`：SQL以订单ID与 `pending_payment` 状态同时约束更新；空值或超过原表128字节限制拒绝。MySQL原生预处理对同秒相同值返回零变更时，仅在重新读取仍为待支付且已有claim一致的情况下视为幂等成功。已支付、已完成、取消、失败和不存在的订单均不能改写claim。保留餐厅的金额、币种和版本能力保护。
- `orderPaymentState()`：委托Core `PaymentRepository::trustedStatus('commerce_order', 'order:'.$id, $currency)`；不会从Commerce自身paid状态推断可信付款，也不会混用其他订单或币种。

`tests/bundled_commerce_compatibility.php` 在隔离SQLite或显式私有MySQL环境覆盖12项，包括同秒重复调用、终态不可变、输入长度和可信支付边界；原餐厅及Commerce完整契约继续运行。所有支付行是合成账本数据，不注册或调用真实支付Provider。

无迁移、表结构、Core公有API或独立插件市场发布。本次Core新装ZIP携带修复后的基础版；Core更新ZIP仍按既有允许清单排除业务插件，所以不会覆盖客户已安装的Commerce/餐厅，也不替代独立插件升级。独立正式Commerce1.0.11包、签名及正式市场版本保持不变。
