# 自动发卡 MySQL 原生预处理修复（隔离、未发布）

2026-11-06。独立 worktree `work/core-mysql-card-payment`，分支
`fix/webclip-mysql-card-payment`，从 clean Core 1.2.93 提交
`0e601035a1fc6c89d3b36aa767e735a44f1eb24a` 创建。
本记录不变更官方版本、既有发行包或生产环境。

## 问题与最小修复

`CardDeliveryRepository::attachPayment()` 在一条 UPDATE 中两次使用
`:payment_id`。Core 默认 MySQL 原生预处理（`ATTR_EMULATE_PREPARES=false`）
不接受这一绑定，checkout 在付款关联时抛 HY093，无法走完整发卡流程。
修复将 WHERE 中的参数独立命名为 `:expected_payment_id`，绑定同一个付款 ID。
保留“只能绑定空值或同一付款”的条件，不允许另一付款覆盖既有绑定。
没有开启模拟预处理、增加业务分支、修改迁移或改变支付状态规则。

## 已验证

- PHP 8.3.33 / MySQL 8.0.46，修复前定向复现 HY093；修复后 MySQL、SQLite
  各 11 项合约断言通过：首次绑定、同付款重试、不同付款拒绝覆盖、调用方事务
  与回滚、非法 ID 拒绝、无多余订单。
- `tests/commerce_v1_contract.php` 通过。
- `tests/release_gate_v1_contract.php` 4 项通过。最初源码归档缺少 Git 元数据，
  末项诊断断言失败；补齐真实 Git bundle/提交来源后原样通过，没有改 Gate。
  测试所需临时签名仅在隔离服务器内产生，不是正式签名或完整 Release Gate。
- 同一 Core 单文件补丁在独立 Core 1.2.90 测试副本通过 98 项 MySQL 原生功能
  断言及旧版插件升级保留检查。正式 1.2.90 发行包未变。
- 独立 Web Clip 同时修复了自身订单关联的排序规则冲突。两者组合在 Core 1.2.93
  / MySQL 默认连接排序规则、原生预处理下，通过原版码支付 0.1.0 的 160 项
  完整协议测试：支付宝、微信、QQ、异步通知、自动发卡、授权下载、通知重放、
  缺货补货恢复。仅 loopback 的网络命名空间和 TLS 模拟收银台，无真实支付。

Core 修复不包含插件代码、插件专用判断或第二套支付/库存系统。
此结果不证明未打补丁的已发布 Core 已修复，也不代替正式 Core 更新包验收。

## 发布与知识边界

没有合并、推送、正式签名、发布或生产部署。真实 IPA、实体设备仍未验收。
后续发布需要独立批准和准确 Core 候选的正式 Gate、签名及原生升级/回滚验收。

知识库同步状态：更新本维护记录及文档入口；统一 V1 产品事实 JSON 与其派生物
不变，因为本轮没有发布版本、增加能力、改变操作入口或支持承诺。已发布文档
不得把隔离修复当作线上现状。未运行知识构建、导入、索引或消费者应用。
