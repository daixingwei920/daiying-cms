# 餐厅外卖1.0.2正式发布

2026-11-11（America/New_York）。原生发布时间2026-11-12T03:57:15+00:00，即纽约时间2026-11-11 22:57:15。

## 已发布内容

- 官方市场stable：「餐厅外卖」`official.restaurant` 1.0.2，`LICENSE_REQUIRED`，USD2,000／终身使用／每站。
- 后台分组设置门店、营业时间、自取/配送、税费、门头照片和三语介绍；菜单提供分类建议、图片和首页推荐，保留已有规格、付费加料和备注。
- Square/Clover **仅测试环境**：授权、门店、可搜索菜品对应、明确不出餐测试单及测试传单。后台和服务端均禁止正式POS环境；实际品牌账号、设备屏幕和纸票尚未验收，不用于营业接入。
- 开启测试传单须确认使用测试站、测试资料和测试付款。测试POS不接管网站打印队列；原有购物车、Commerce付款、订单和厨房票流程保持原接口，不再次收费、不自动退款。
- 免费「戴影餐厅外卖主题」仍1.0.1，本轮没有主题源码变化或重复发布。

## 来源与正式包

- 实现PR：<https://github.com/daixingwei920/daiying-cms/pull/99>。
- 受测提交：`2d2a7f9981c208d2b0d4048381f78a77151037f8`。
- main合并：`08f9603dc6e790cadc38c16bd1d2bedd9b056087`；完整Git文件树与受测提交一致。
- 正式包：`official-restaurant-1.0.2.zip`，117616字节；30个扩展源码/文档文件与干净main逐项一致，另含原生approved信封，共31项。
- SHA-256：`bff09d362bf53f03849046ef5e2f2cd9e9018d6625af070e1df7a5d243ae5a70`。
- 官方Ed25519公钥ID：`8ee3baa72f432a92`；使用原生SignatureVerifier验证正式载荷、签名及包SHA通过，没有在本机生成或导出签名私钥。
- [官方版本记录](https://updates.daiyingcms.com/api/v1/products/official.restaurant/latest)、[原生审核发布记录](https://updates.daiyingcms.com/admin/review/sub_e621eb416a8806699c8aeec3?published=1)。

## 实际门禁与边界

| 项目 | 结果 |
|---|---|
| Core1.2.98、Commerce1.0.8、SQLite；PHP8.3.33和8.5.9原生新装/1.0.1→1.0.2升级 | 均PASS |
| 精确文件、旧业务数据保留、SQLite与Core完整性 | PASS |
| 隔离单站授权、第二站拒绝、绑定站点且单次使用的下载凭证 | PASS，合成授权，不生成生产码 |
| 原生启用/启动 | 四个新装与升级环境均PASS，41条餐厅路由、能力/CSRF及计划任务实际注册 |
| POS聚焦 | 本地PHP8.3/8.5各84断言及服务器对应套件PASS；品牌响应为明确标识的替身，不是真实品牌验收 |
| 相关回归 | 后台设置、首选语言、餐厅/主题契约、税费、订单凭证、打印桥、Release Gate V1在两服务器PHP版本均PASS |
| 浏览器 | 测试限制与无production选项；390×844文档宽度390，无整页横向溢出，不是实体手机验收 |
| 正式市场 | 原生真实管理员/CSRF审核与签名发布；中文搜索显示收费1.0.2，匿名下载HTTP403 |
| 数据保护 | 新一致备份验证；既有授权/批次、其他商品、全部旧发布记录不变；本次生成生产授权码0个 |
| MySQL、最低Core1.2.83、真实品牌/设备/付款、性能负载 | 本轮未实跑，不标PASS |

运行依赖最低Core1.2.83，最高不填写；市场最低PHP8.3.33。这不是Core升级硬性最低版本配置，未改变Core1.2.80规则。

静态扫描保留既有菜单OCR的proc_open WARNING。人工核对固定工具路径、参数数组、超时和输出限制后批准；AI在官方服务器停用，保留MANUAL_REVIEW_REQUIRED，不伪称AI通过。

本次未升级takeout或其他网站，未修改Core/Commerce源码、生产支付或授权数据，没有真实下单、收款、退款或打印设备调用。正式制品已冻结；不得把旧无信封候选或历史测试报告当作最终正式包或当前真实POS验收。

[后台使用说明](../content/plugins/official.restaurant/ADMIN_GUIDE.md) · [POS测试连接说明](../content/plugins/official.restaurant/POS_INTEGRATION_GUIDE.md)
