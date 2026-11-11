# 双模式厨房打印：1.0.0

要求 Core 1.2.83+、Commerce 1.0.8+，沿用官方签名安装与信任，不支持把 `official.*` 改名以绕过普通上传限制。插件发布不等于任何打印机型号的硬件认证。

## 支付及队列

复用 Core `PaymentRepository::trustedStatus()` 与 Commerce `order()` / `markOrderPaid()`，只处理餐厅自己映射的订单，核对金额、币种、净支付和退款。不另建支付系统，不读写 Commerce 私有表。

当前 Core 未提供可订阅的支付确认事件。支付返回页、后台同步、本地桥领取均可核对；为了桥离线时仍生成任务，随包提供 `bin/reconcile-print-jobs.php --once`。管理员授权后由服务器计划任务每分钟执行一次，每页最多核对 200 条并保存游标，扫描完成后从头复核。必须使用已安装 CMS 的 PHP 8.3+ 与站点用户。没有计划任务或桥在线时，不保证后台即时入队。停用插件或依赖、失去官方信任会拒绝执行。该脚本不直接打印、不发起付款或退款。

## 设备和安全

后台 `/admin/restaurant/printers`：模式 A 只允许经授权 Windows/Mac 打印队列；模式 B 允许 Windows/Mac 驱动或经核验 TCP 9100 协议。Mac `mac_queue` 为测试候选，使用系统队列提交嵌入字体的 PDF，需 Python 3.11+、ReportLab 及系统 STHeiti/Arial；不承诺厨房机认证。打印机目标仅存本地桥配置，CMS 保存岗位、适配器、设备状态和凭据哈希。桥 ID + 独立随机凭据不少于 32 字符；建议 64 个随机十六进制字符。切换/撤销设备请停用后再绑定新凭据。

通信必须 HTTPS；只有站点 URL 与连接来源都为回环地址时允许 HTTP 本机测试。不信任客户端 `X-Forwarded-Proto`。凭据限于绑定设备的领取、一次性输出闸门及状态回传，不能读取顾客账户或管理菜单。代理部署需保留可靠的服务器 HTTPS 状态，不得把所有外部请求标成回环。

- `GET /restaurant/bridge/bindings?agent_id=...`：绑定及心跳。
- `POST /restaurant/bridge/jobs/claim`：租约领取一条，120 秒，包含 UTF-8 正文与 SHA-256。
- `POST /restaurant/bridge/jobs/start`：再次核验付款/取消/退款和设备权限，一次性允许提交。
- `POST /restaurant/bridge/jobs/report`：`submitted`（已发送，不要求逐单人工确认）、`failed` 或 `needs_review`（结果不确定，须核实）；不把 TCP/Windows 队列接收冒充实际出纸。

机器请求使用 `Authorization: Bearer ...`；测试站有额外 HTTP Basic 时可使用 `X-Daiying-Bridge-Token` 承载设备凭据。老 `/restaurant/agent/*` 协议返回 410，不再派发任务。

## 人工兜底

岗位路由仍复用既有厨房、寿司台、饮料区配置。订单及任务唯一键防止重复通知产生重复票；创建票与队列原子提交。租约过期、发送中断、Windows 队列接收和网络发送成功都可能是未知结果，因此冻结为待核实，不自动补打。

后台 `/admin/restaurant/print-jobs` 显示失败、原因、尝试次数、待核实提示和操作审计。先查看实际纸票及 POS/Windows 队列，再填写原因、明确确认重印；每次批准创建关联原任务的新票并标记 REPRINT。同一批准重复提交不重复建任务。人工处理/实际出纸可现场确认，留痕。请勿把本机模拟票确认为真实打印。

取消/退款无法收回已经输出的纸票；出餐前必须复查订单看板。退款发生在最终闸门之后与物理输出之间仍有竞态，须人工停单。本候选对任何部分退款均保守冻结，不自动推算应出菜品。

已有 Canon MG2500 / macOS 队列的三语实纸测试及已付款订单纸票证据；此结果不适用于 Windows、POS、Epson 或热敏厨房机。正式组合、MySQL、服务器调度及各路径的当前验收结果，以对应发布测试报告为准，不把历史实测当作所有设备认证。
