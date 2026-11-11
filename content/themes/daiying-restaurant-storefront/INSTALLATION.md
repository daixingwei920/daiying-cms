# 戴影餐厅外卖主题（Daiying Restaurant Storefront）安装

版本 1.0.0，免费；正式发布以官方市场实际状态及签名为准。要求 Core 1.2.83+。通过 CMS 正式主题安装器安装，先备份现有主题设置。建议安装 `official.restaurant` 1.0.0 和兼容 Commerce 1.0.8+；没有餐厅插件时主题只展示降级页面，不伪造订单功能。

- 站点配置 Logo 优先于门店及主题 Logo。门头图、桌面/手机焦点、品牌色、分区开关在后台设置；招牌采用 contain，避免严重裁切。
- 完整菜单、搜索、餐别价格、规格、加料、逐菜备注及购物车都使用餐厅公开接口。切换主题不删除插件购物车或订单。
- 页头只显示存在内容的导航。三语界面和语言选择保存在浏览器；餐厅内容翻译由商户编辑，不能把英文原文当作已核对翻译。
- 设置真实地址/电话、地图、规范 URL 和营业时间，核验 SEO。未提供数据时隐藏对应内容，不捏造联系方式、评价或评分。
- 手机有底部购物车；视口测试不等于实体手机验收。支付、税率、配送和预约规则由插件/Commerce 校验，不由主题计算。

English: Install with the supported theme installer. The site logo has priority. Configure real storefront media and SEO. The theme uses public Restaurant APIs only; switching themes does not erase orders or carts. Missing merchant translations/photos are not fabricated. Validate payments independently.

Español: Instala con el instalador de temas compatible. El logotipo del sitio tiene prioridad. Configura fotos y datos SEO reales. El tema usa las API públicas; cambiar de tema no elimina pedidos ni carritos. No se inventan traducciones o fotos. Verifica los pagos por separado.
# 首选语言

在后台「餐厅外卖 → 店面与营业设置」选择「首选语言」，支持 English、中文和 Español；未配置时默认英文。没有自行选择语言的顾客使用门店首选语言，已在浏览器中选择的语言优先并继续保留。后台更改首选语言不会重置顾客语言、购物车或订单。
