# 餐厅 Square / Clover 接入本地交付报告

> 历史开发记录。Owner随后授权的1.0.2发布范围以[1.0.2发布准备](restaurant-storefront-1.0.2.md)为准：POS仅测试环境，网站原打印保留；本文件中的早期完整模式测试不是正式POS验收。
日期：2026-11-11，America/New_York。Owner明确选择「尚无开发应用，先完成代码与本地验证」。

**结论：功能代码与隔离软件验证完成；真实品牌授权、POS接单及出纸尚未验证。不发布、不部署、不改变营业设备。**

## 源码和范围

- 正式产品仍为 `official.restaurant`，中文名「餐厅外卖」，没有另建插件或另一套支付。
- 候选源码绝对路径：`/Users/xingweidai/Documents/Codex/2026-10-09/daiying-cms-official-restaurant-daiying-restaurant/work/restaurant-admin-usability/content/plugins/official.restaurant`。
- 基线提交：`05d0f951bfce396deb2e19b0796a06bbe8484811`，已发布1.0.1对应main；本轮保留已有后台改版，新增POS实现。候选尚未提交，Manifest版本仍1.0.1，新增005迁移并将数据版本登记为`0.1.0-alpha.16`。这不是已签名的1.0.1市场包，禁止用同版本覆盖正式制品。
- 没有修改Core、Commerce或主题源码；没有推送、合并、更新takeout、发布市场或改变USD2,000/终身/每站及免费主题规则。
- 未制作新的正式安装包；本次没有原生签名、安装/升级门禁结果。

## 已实现

1. 真实的Square/Clover HTTPS适配器，而非只有按钮。品牌应用编号、测试/正式环境、授权网址、单次授权请求、PKCE、服务端换取并加密保存凭据。
2. 后台逐步操作：登录品牌 → 选择账号内的门店 → 对应菜品 → 选择厨房出单方 → 发送不出餐零金额测试单 → 现场确认 → 开启以后新订单自动接入。无应用时明确显示等待管理员开通。
3. 读取品牌菜单及Clover外部收款方式、税项、订单类型；只保存获准门店及菜单选项，不接受伪造的外部门店/菜品编号。不覆盖商户菜单或网站价格。
4. 复用既有餐车冻结价格和 `RestaurantCheckoutService::orderReceiptForCart()` 的Core真实付款账本核验。只有全额付款、未取消/退款/完成交餐的订单才能传送。规格/付费加料合入每份成交价，其名称和每道菜备注随订单传送；另携带数量、税额、配送费、顾客交付资料及预约时间。
5. POS仅登记外部已付款，不向顾客再次扣款、不执行退款。Square使用EXTERNAL支付来源；Clover使用商户自己开通的外部已收款方式。
6. Clover自定义订单创建后立即保存外部订单号，批量添加菜品，回读菜品/税额，再通过官方更新接口保存总额，最后登记并回读外部已付款记录。金额不符立即停止。
7. 单站仅一个自动接入目标。网站打印助手与POS二选一；POS为主时，原有网站自动打印任务不重复创建，原厨房票仍保留。选择网站打印助手时保留原打印队列，不调用Clover打印接口。
8. 统一传单记录、单次领取、连接处理锁、固定外部关联编号、已取得外部订单号的检查点、异常提示和操作审计。超时、进程中断及不确定结果进入人工核对，不盲目重发。断开只清理当前连接凭据。
9. 通过公开SDK注册每60秒运行的 `restaurant.pos_sync`，读取既有餐厅订单，不依赖顾客支付后返回页面。近期没有任务执行记录时禁止开启自动接单。另保留受原生安装/信任/维护状态保护的管理员CLI诊断入口。
10. 中文后台正常路径不要求编写设置文本、令牌或协议参数。Square即时自取/配送预计分钟可填写，预约订单沿用网站既定时间。连接页、菜单对应、操作结果及异常均使用日常用语。

## 使用的公开能力与新增持久化

- CMS：`PluginContext::adminRoute`（`restaurant.manage`，POST开启原生CSRF）、`assetUrl`、`secrets`、`registerScheduledTask`（显式声明`scheduler.register`）、原生`PluginSecretStore`加密、`View`和`CsrfToken`。
- 业务：既有餐厅Repository、Checkout真实付款查询、厨房票和PrintService；Commerce及PaymentRepository只经既有公开服务验证，没有插件运行代码直接读写Commerce私有表。
- 厂商：官方OAuth/刷新、商户门店、菜单、订单、外部付款及Clover打印接口。HTTPS校验开启，固定官方API地址、禁止跳转、限制响应大小、请求时间及读取页数。
- 插件自有005迁移：`restaurant_pos_connections`、`restaurant_pos_oauth_states`、`restaurant_pos_jobs`、`restaurant_pos_audit`；不清除已有餐车/厨房票。凭据不保存在公开店面资料或主题内。
- 其他品牌没有连接实现；没有POS私有数据库、未授权接口或公网打印机端口。

## 本轮实际验证

隔离回归目录：`/Users/xingweidai/Documents/Codex/2026-10-09/daiying-cms-official-restaurant-daiying-restaurant/work/restaurant-admin-regression`，Commerce1.0.8。最初源码目录中的旧Commerce不能满足结账依赖，未修改它，改用该已有隔离环境。

| 项目 | 实际结果 / 边界 |
|---|---|
| POS聚焦测试 | PHP8.5.8与PHP8.3.35各78断言PASS；真实餐厅、Commerce及Core账本代码 + 明确标识的品牌响应替身；不是品牌沙盒 |
| OAuth/隔离 | PKCE、单次状态、防重放、其他管理员/浏览器、外部门店/伪造菜品拒绝、凭据加密、刷新轮换、复制数据库到其他站点拒绝 |
| 订单与出单 | 原生计划任务实际调用、未付款/取消/部分退款拦截、冻结价格/三语备注、加米饭及非计税配送费合计$28.80、两种出单方、去重、超时不重发、金额不符保留外部编号、并发连接操作阻止、005重复执行保留餐车 |
| 相关回归 | 餐厅契约PASS；打印桥53、税费41、订单凭证31、后台设置31、首选语言28断言PASS；主题契约PASS |
| 真实本地浏览器 | 实际控制器页面、展开首次开通、保存虚构测试应用编号、重定向后出现测试环境登录入口，PASS；未点击真实品牌授权 |
| 手机 | 390×844模拟视口，页面宽度/文档宽度均390，无整页横向溢出；不是实体手机验收 |
| 三语 | 传单数据中的中文、英文、西语备注及测试票文本通过软件断言；品牌设备字体和实际纸票NOT_VERIFIED |
| SQLite | 自有迁移、现有业务账本及软件全流程实际执行PASS |
| MySQL | 使用兼容分支定义，未启动MySQL实跑，NOT_VERIFIED |
| HTTPS/API实际连通、品牌准入、真实授权/刷新、POS接单屏、厨房出纸 | NOT_VERIFIED；没有开发应用和测试商户，没有向品牌发送订单 |
| 正式安装升级、签名/发布门禁、takeout现场 | 未执行 |
| 最低Core 1.2.83运行环境 | 未单独实跑，本轮使用现有本地Core隔离环境 |
| SEO/性能 | 本轮只改后台和POS服务，无前台SEO改动；未进行新SEO、Lighthouse或负载验收 |

测试日志和源码哈希：`/Users/xingweidai/Documents/Codex/2026-10-09/daiying-cms-official-restaurant-daiying-restaurant/outputs/restaurant-pos-tests/`。

本地可审阅页面：`http://127.0.0.1:19101/admin/restaurant/pos`。这是仅本机、明确标识示例资料的预览，不是生产后台认证验收；预览禁止真实授权、传单、支付和打印。保存的虚构应用编号也不代表真实品牌应用。

运行截图：该目录内 `screenshots/desktop-setup.png`、`screenshots/mobile-setup.png`。截图来自本地实际控制器，不是设计图。

## 后续真实验收仍缺什么

- 创建Square测试应用、Clover支持PKCE的测试应用及各自测试商户，登记受支持的HTTPS回到网站地址、授予最小所需权限。真实品牌响应可能要求调整字段与商户设置，不能凭替身测试宣称兼容已完成。
- 用测试商户核对普通/规格/加料、税费、非计税/计税配送费、预约、即时预计时间、订单显示、外部付款登记；明确核对是否产生品牌服务费用。
- 现场分别验证POS/KDS/厨房打印机的接单和字体；API确认不等于出纸。尤其Clover的订单显示/默认出单设备和Square的履约/已付款接单条件，依商户设置验收。
- 已经传送的订单如取消、退款或修改，目前由店家人工通知厨房并核对POS，不自动回写取消或退款。结果不确定的传单也需人工核对，不提供冒险的一键重发。
- 当前仅美元、单站一个自动接入目标；有限分页，单单最多250份。后台菜品对应是人工确认，不包含自动双向菜单/库存同步。
- 待上述实测后，再定候选新版本、从干净提交构建并进行原生安装/升级及正式发布门禁。本轮未越过该边界。

## 官方资料与店家说明

[插件内安装使用说明](../content/plugins/official.restaurant/POS_INTEGRATION_GUIDE.md)；[官方接口研究](restaurant-pos-integration-research-2026-11-11.md)。资料链接均指向Square/Clover官方文档，详见两文；查阅日期为本报告日期。

关于Miyo’s：本聊天既有照片能确认Epson打印机、接口及网络设备，不能据此确认POS品牌，不能假定使用Square或Clover。
