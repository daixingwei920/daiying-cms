# 餐厅外卖：安装与验收 / Installation / Instalación

版本 1.0.0，正式发布须以官方市场实际状态及签名为准。保留 `official.restaurant` 身份；使用 CMS 受支持的官方签名安装流程或 Owner 授权的隔离测试分发。不要改名绕过普通 ZIP 上传器，不修改信任记录或 Core。

## 中文

1. 先备份网站数据库及扩展。要求 Core **1.2.83+**、PHP **8.3+**、官方 Commerce **1.0.8+**。使用 Stripe 时要求官方支付适配器 **1.2.8+**，并核验沙盒回调和人工退款。只准备订单不代表支付成功。
2. 通过官方授权安装器安装本插件，启用后设置门店名称、真实地址/电话、时区、营业时间、国家及交易区域、自取/配送范围、预约和税率。缺少税率不会被默认为免税。
3. 手工添加菜单，或在「识别菜单」上传菜单资料，核对识别草稿后导入。后台可修改分类、三语名称/说明、搜索别名、午餐/晚餐/儿童餐及各自售价。午餐没有额外限时。
4. 在「加」选项中设置米饭/肉类等每份加价。顾客只能选择后台定义的选项；不要食材等特殊要求填写每道菜备注，不自动减价。
5. 配套主题可单独安装；站点 Logo 优先。门头、桌面/手机焦点及分区可在设置中修改；没有照片时明确显示未提供，不生成虚假门店照片。
6. 在支付插件后台配置测试凭据及 Webhook，不把密钥写入菜单或主题。验证付款成功、失败、重复通知、不返回网站、取消及退款，再决定上线。
7. 无打印机时可用订单看板接单。打印桥是另行验收的实验能力：正常发送自动标记 `submitted`，只有失败/结果不确定需要人工处理；不可把它当作实体出纸认证。服务器订单核对器须由授权管理员设置定时运行。

### 订单凭证与交餐

顾客在原下单设备打开配套主题的「订单凭证」，通过持有的餐车凭证查看原Commerce订单号、真实厨房票号、付款确认及出餐/完成状态，支持刷新。付款完成页同时显示原订单号。店员在有权限的后台核对订单号/票号、姓名/手机号与真实付款状态；交餐后标记完成，已完成订单不能重新接单。未核实付款、取消或退款的订单不能直接标记交餐完成。此入口不是仅输入姓名/手机号即可公开查询的接口；换设备或遗失凭证须联系门店，跨设备邮箱/短信验证码查询尚未接入。

### 退款规则

退款仅由具有支付管理权限的店家/管理员人工发起，复用官方支付管理流程。顾客端不提供退款申请按钮或接口；付款成功、退出付款页、取消厨房票及打印失败均不得自动发起退款。厨房票取消不等于款项已退，人工退款也不得直接修改支付状态冒充到账。收到可信的人工退款结果后，仍须同步订单/厨房票并暂停尚未打印的任务；已经打印的票须由店家现场通知厨房。

## English

Back up first. Use the authorized official installer, not a renamed ordinary ZIP. Require Core 1.2.83+, PHP 8.3+, Commerce 1.0.8+ and, when using Stripe, its official adapter 1.2.8+. Configure the real restaurant, fulfillment, hours, tax and sandbox payment provider. Review recognized menu drafts before import; manual editing remains available. Edit categories, three-language content, search aliases and lunch/dinner/kids prices in the admin. Paid extras are server-priced; per-dish notes do not automatically reduce prices. Verify webhooks, no-return ordering, cancellation and refunds before launch. Printing is device-specific; the order dashboard remains a fallback.

Refunds are initiated manually by an authorized merchant/admin through the official payment-management workflow. Customers have no refund-request button or API. Successful payment, leaving checkout, cancelling a kitchen ticket or a printing failure must not trigger an automatic refund. Cancelling a ticket is not a completed refund. Continue to reconcile trusted refund results and stop unprinted jobs; notify the kitchen manually if a ticket has already printed.

## Español

Haz una copia de seguridad y usa el instalador oficial autorizado, sin cambiar el identificador del complemento. Requiere Core 1.2.83+, PHP 8.3+, Commerce 1.0.8+ y el adaptador oficial Stripe 1.2.8+ si utilizas Stripe. Configura restaurante, horarios, recogida/entrega, impuestos y pagos de prueba. Revisa el borrador reconocido antes de importar; también puedes introducir el menú manualmente. Edita categorías, tres idiomas, alias de búsqueda y precios de almuerzo/cena/niños. Los extras tienen precios definidos por el restaurante; las notas no descuentan automáticamente. Verifica notificaciones de pago, pedidos sin regreso al sitio, cancelaciones y reembolsos antes del lanzamiento. La impresión requiere probar cada dispositivo; utiliza el panel de pedidos como alternativa.

Solo el restaurante o administrador autorizado puede iniciar un reembolso manual mediante la gestión oficial de pagos. El cliente no dispone de botón ni API para solicitar reembolsos. Un pago exitoso, abandonar el pago, cancelar un ticket de cocina o un fallo de impresión no deben generar reembolsos automáticos. Cancelar un ticket no equivale a devolver el dinero. Sincroniza los resultados de reembolso confirmados y detén los trabajos aún no impresos; avisa a la cocina si el ticket ya se imprimió.

## 支持与边界

餐厅插件售价为 USD 2,000，终身使用，每站授权；配套主题免费。终身使用不代表终身更新或售后 SLA；更新及服务条款以购买合同为准。尚未指定统一购买入口时，不承诺自动付款发码。报告问题时提供版本、已脱敏的订单/任务编号和错误；不要发送支付密钥、顾客资料或设备凭据。本包不包含门店私有菜单、照片、测试数据库或凭据。

License: USD 2,000, lifetime use, one site. The companion theme is free. Lifetime use does not promise lifetime updates or a support SLA; use the purchase agreement. Licencia: USD 2,000, uso de por vida, un sitio. El tema es gratuito. No implica actualizaciones de por vida ni un SLA de soporte.
