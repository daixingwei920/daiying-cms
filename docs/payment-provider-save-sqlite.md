# 支付配置保存的 SQLite 事务修复（未发布）

基线为实际 origin/main `9a29ee2f981ea6353fc7f91abe9b983e0f1c9680`，独立干净 worktree `work/core-payment-save-lock`。本次仅修复支付配置保存，不修改网关协议、支付平台资质、权限、加密契约、公开接口或数据库结构，不合并、发布或部署。

## 线上证据及边界

只读核对 www.piank.cn 的1.2.91活动源码与发布版本一致；日志在 `2026-11-01T13:13:14+00:00` 记录 `Core payment provider settings save failed / SQLSTATE[HY000]: General error: 5 database is locked`。设置表为空，错误窗口没有成功保存审计。站点使用 SQLite DELETE journal，原生 busy_timeout 为60000ms；后续只读检查未发现持续内核锁。

该站 Nginx 配置使用 PHP8.5。**历史占锁进程未捕获，不能把 PHP8.3兼容问题认定为这次线上错误的唯一原因。** 没有重放生产保存、读取商户密钥、调整数据库模式、改配置、启用插件或执行支付。

## 修复

- 隔离环境真实复现：PHP8.3在执行 SQL `BEGIN IMMEDIATE` 后，PDO::inTransaction() 仍为false；原保存路径据此跳过提交/回滚，再从新连接核验时被自己的写锁阻塞。PHP8.5正常路径不触发这一差异。
- 仅将 paymentProviderSave 的事务所有权、开启、提交和回滚改为复用现有 PaymentTransaction，兼容原生PDO事务及SQLite受跟踪事务。配置、默认标记、密文和审计继续原子提交，异常原子回滚。
- SQLite主错误码5/6（含扩展错误码）的真实外部写锁返回 HTTP503、Retry-After:3及安全提示。不会盲目重试、绕过写锁或宣称未保存/已成功；提示返回设置页核对后重试，因为提交后核验也可能遇到并发占锁。
- 保留管理员、CSRF、Provider可用性、必需字段、默认方式资格、配置验证和密钥加密检查；其他数据库错误继续原失败处理。没有修改通用事务类、其他支付入口或既有迁移。
- 更新候选Core完整性清单，只改变 Admin/AdminController.php 一项哈希；已发布v1.2.91标签、正式包和生产Core不变。

## 验收

在授权更新服务器私有隔离目录 `/root/daiying-payment-save-lock-20261101-RS8ElzpZ` 执行，不使用生产数据库、商户配置或支付网关。

| 测试 | 结果 |
| --- | --- |
| 原版PHP8.3完整Application/管理员保存 | 真实复现HTTP500/database is locked，保留基线失败 |
| 原版PHP8.5正常保存 | 通过；外部写锁错误原为通用500 |
| 修复后PHP8.3及8.5完整Application/管理员保存 | 每个版本7组通过：普通保存、默认方式、声明式密钥、503不改数据、解锁后重试/密钥保留、审计失败原子回滚、CSRF/配置拒绝 |
| payment_sqlite_transaction_contract | 27项通过 |
| payment_provider_config_currency_filter | 6项通过 |
| payment_configured_redirect_policy | 12项通过 |
| payment_checkout_recovery | 11项通过 |
| payment_refund_webhook_idempotency | 22项通过 |
| provider_capability_gate_hardening | 9项通过 |
| plugin_decoupling_foundation | 32项通过 |
| release_gate_v1_contract | 4项通过，仅在授权服务器产生临时测试签名密钥；没有本机签名密钥生成或导出 |

初次Gate契约在不含Git元数据的源码导出目录中因错误原因检查不匹配而失败；保留该记录，改在真实Git历史的隔离clone运行后通过，未改变Gate校验。契约测试通过不代表正式准确提交Release Gate或签名更新包验收。

知识库同步状态：本次修复为未发布维护候选，与已发布1.2.91证据分开记录；同步候选来源与内部维护知识，不自动导入生产客服、索引或任务。正式发布、MySQL保存实测、生产升级和实际网关交易均未执行，piank.cn需在后续授权的官方更新后复测。
