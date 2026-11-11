# 餐厅收银系统接入研究

> 历史开发记录。Owner随后授权的1.0.2发布范围以[1.0.2发布准备](restaurant-storefront-1.0.2.md)为准：POS仅测试环境，网站原打印保留；本文件中的早期完整模式测试不是正式POS验收。
核查日期：2026-11-11（America/New_York）。美国餐厅场景；仅查阅官方资料，没有登录商户账号、申请开发者协议、连接营业设备或发送外部订单。

实现进展见[POS实现报告](restaurant-pos-implementation-report-2026-11-11.md)。本页保留品牌准入与官方资料研究，不代表真实设备验收。

## 结论

可以把店家日常接入简化为「选品牌 → 登录并允许连接 → 选门店 → 核对菜单 → 确认接单方式」。但前提是戴影先建立对应的官方应用、取得必要准入并通过实际接单验收；不是让店家填写一串接口参数，也不是只加一个连接按钮。

本次选取美国餐厅常见生态中的Square、Clover、Toast、Lightspeed、Aloha/NCR Voyix、Oracle MICROS。没有获得同一统计口径、可独立复核的美国餐厅POS市场份额，因此不声称某品牌排名第一或给出无依据的占有率。以下是接入可行性排序，**不是市场占有率排名**。

## 各品牌的实际条件

| 品牌 | 店家可看到的操作 | 我们必须先完成的事项 | 本次状态 |
|---|---|---|---|
| Square | 登录Square、同意权限、选择门店 | 注册官方开发应用及回调地址；订单、取餐/配送、菜单和外部已收款记录适配 | 代码与本地隔离验证已完成；真实账号未验证 |
| Clover | 安装/允许应用、选择门店与出单设备 | 官方应用及授权流程；菜单/规格映射、外部收款记账、设备和打印适配 | 代码与本地隔离验证已完成；真实账号未验证 |
| Toast | 在Toast选择合作应用并启用门店 | 合作伙伴/定制接入批准及写入权限；仅标准只读资格不够 | 需要厂商准入；未申请 |
| Lightspeed | 先选产品系列，再启用获准的连接 | 区分K/L/O等系列，确认对应开发者/商户资格；不能把所有系列当成一个接口 | 产品系列与准入待确认 |
| Aloha / NCR Voyix | 门店服务商协助启用已兼容的应用 | 确认Aloha具体产品、版本和合作方式；官方特定接入申请要求Aloha Takeout 19.x或以上，不能泛化为所有Aloha产品均可用 | 服务商及版本待确认 |
| Oracle MICROS | 由门店/合作方开通应用，再选择门店 | 确认Simphony等具体产品、合作计划和交易服务；不能承诺所有老MICROS均支持同一连接 | 合作与产品资格待确认 |

Square允许通过店家登录授权获取访问许可。[官方授权说明](https://developer.squareup.com/docs/oauth-api/overview) Clover同样要求商户授权，提供有期限令牌和续期流程。[官方授权说明](https://docs.clover.com/dev/docs/use-oauth)

Toast的标准接入是只读，写入或定制接入需联系其代表；合作伙伴需正式审核，店家能在获准的应用市场启用连接。[标准接入范围](https://support.toasttab.com/en/article/Standard-API-Access)、[合作伙伴申请](https://support.toasttab.com/en/article/Customer-Requesting-API-Credentials?lang=en_US)、[店家启用合作应用](https://support.toasttab.com/en/article/Set-Up-Toast-Partner-Integrations)

Lightspeed K-Series准入面向伙伴和批准商户。[官方开发者准入](https://api-portal.lsk.lightspeed.app/quick-start/intro) NCR的特定站点接入需申请并核对版本。[官方接入登记](https://www.ncrvoyix.com/about/vendor-integration) Oracle有正式Simphony合作接入计划。[官方合作说明](https://www.oracle.com/food-beverage/restaurant-pos-systems/pos-integrations/partners/)

## 轻松连接需要藏在界面背后的工作

1. 店家只选择品牌，随后在品牌自己的官方页面登录并确认权限。应用注册、加密凭据保存、令牌续期和固定回调由我们实现；不让普通店家配置开发参数。不把令牌放到公开店面设置或前端。
2. 自动列出获准门店，让店家点击选择。凭据与本店绑定；不能读取其他门店资料。
3. 获取菜单后按菜名、分类、规格和加料建议匹配，显示未匹配项，店家点击确认。不能静默覆盖现有网站菜单或价格。午餐、晚餐、儿童餐的独立记录仍分别匹配。
4. 仅发送网站已经确认收款的订单。网站收款继续复用Commerce及原有支付插件；收银机侧应记为外部已收款，而不是再次刷卡。Square提供外部付款记账方式，Clover也有外部收款记账入口；必须分别适配，而不是误用再次扣款的入口。[Square外部付款记录](https://developer.squareup.com/docs/payments-api/take-payments/external-payments)、[Clover外部收款记账](https://docs.clover.com/dev/reference/ordercreatepaymentfororder)
5. Square已付款且带履约信息的订单可以进入其接单界面，但须验证相应订单条件。使用非Square支付服务时，官方文档提示Orders API可能产生交易费用；不能对店家承诺接入后没有额外费用。[订单接收条件](https://developer.squareup.com/docs/orders-api/what-it-does)、[订单服务及外部支付费用说明](https://developer.squareup.com/reference/square/orders-api)
6. 选择「由收银系统出单」或「由网站打印助手出单」，明确唯一默认出单方，避免同单两张厨房票。Clover有官方打印入口，但创建订单并不必然让所有POS或厨房设备自动显示/打印，必须做设备验收。[官方打印说明](https://docs.clover.com/dev/docs/printing-orders-rest-api)、[订单常见问题](https://docs.clover.com/dev/docs/orders-faqs)
7. 以网站订单和目标门店作为去重依据；保留外部订单号和发送结果。网络超时且无法确认结果时先核对，不能盲目重发。店家能直接看到“已送达收银机”“等待确认”“需要处理”。失败时网站看板仍可人工接单。
8. 不自动退款。人工退款、取消和厨房处理分别显示；取消不能撤回已打印的小票，也不能把POS状态简单反写为网站已经退款。

## 推荐执行顺序

工程判断：先用Square或Clover完成一条真正可用的「登录授权—选门店—菜单匹配—网站已付款订单入机—出单」链路；Toast商业准入可并行申请。之后按实际使用的Lightspeed系列、Aloha或MICROS产品单独做适配。

当前缺少各品牌正式开发应用、批准资格及可用测试商户。对营业中的设备和账号，需要店家明确授权后才能连接。没有这些前提，本地界面只能展示准备状态，不能把静态页面、模拟授权或测试数据库标成连接成功。

本次没有实现通用POS令牌存储、订单投递器或新支付系统，没有把任何聚合平台设为插件强制依赖，也没有修改售价或授权规则。
