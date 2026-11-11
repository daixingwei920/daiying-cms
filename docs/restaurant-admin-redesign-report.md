# 餐厅后台改版交付记录

> 历史开发记录。Owner随后授权的1.0.2发布范围以[1.0.2发布准备](restaurant-storefront-1.0.2.md)为准：POS仅测试环境，网站原打印保留；本文件中的早期完整模式测试不是正式POS验收。
日期：2026-11-11（America/New_York）。状态：**本地候选已实现并完成隔离软件/浏览器验收，未发布、未部署takeout或其他网站**。

## 实现

- 基线为已发布1.0.1对应的main合并提交`05d0f951bfce396deb2e19b0796a06bbe8484811`，另建干净工作目录后开发；没有另建第二套餐厅插件。
- 源码：`/Users/xingweidai/Documents/Codex/2026-10-09/daiying-cms-official-restaurant-daiying-restaurant/work/restaurant-admin-usability/content/plugins/official.restaurant`。
- 店面设置拆为基本资料、营业时间、自取与配送、税费与收款、门头与照片、三语介绍、收银系统七组。
- 用时间选择器、开关、金额输入、邮编列表和照片位置滑块替代主界面的原始设置文本。支持增加/移除时段与照片，保存成功提示及保存后留在当前分组。
- 菜品编辑支持分类建议、可点击菜名、图片地址、推荐到首页；原有规格和额外资料保留，不再要求填写展示数据文本。
- 统一餐厅导航、卡片、表单和表格容器；订单状态、设备连接方式及菜单识别说明改为日常用语。没有重做CMS导航外壳。
- 继续使用原有设置服务、保存路由、权限、CSRF、订单、支付、税费和打印业务。新增的表单适配器只合并它负责的字段，保留未知设置、其他语言及未编辑的旧内容。
- 没有改Core、Commerce、主题、插件身份、售价或授权规则；后续POS开发增加了餐厅插件自己的005迁移；版本仍是基线1.0.1，源码为未提交候选，不能以此覆盖已发布包。

## 实测

| 项目 | 结果与范围 |
|---|---|
| 新设置/菜单测试 | PHP8.3、8.5各31断言PASS：实际控制器保存、金额换算、营业时段、照片/语言保留、16类错误输入不写入等 |
| 原有首选语言契约 | PHP8.3，28断言PASS |
| 插件契约、主题契约 | PASS |
| 税费、订单凭证 | 本轮41、31断言PASS；后续仅显示调整，无相关业务变化，未重复昂贵测试 |
| 打印桥 | 最终53断言PASS；保留原始因旧“模式A/B”文案断言失败的日志，改为核对原有连接值和不可用POS选项仍禁用，再实跑通过 |
| 真实浏览器点击保存 | 本机示例SQLite + 实际插件控制器；西语保存回显、配送/预约开关、4.25配送费保存并刷新、午餐/晚餐时段删除重建和保存PASS |
| 增删行 | 时段删除再新增使用唯一编号；照片删除再新增使用新编号，未覆盖其他行 |
| 手机宽度 | 390×844模拟视口；七个设置分组、菜单、打印页面共9个页面/面板检查，文档宽度均390，无整页横向溢出。不是物理手机验收 |
| 可访问性 | 原生标签/按钮、可见焦点、分组及状态提示；一次Tab/回车切换营业时间面板成功。不代表完整WCAG审计 |
| 源码检查 | PHP语法、JavaScript语法及git diff --check通过 |
| 正式管理员登录、takeout部署、真实支付、真实POS、真实打印机 | 未执行，不标PASS |

本地预览：http://127.0.0.1:19101/admin/restaurant/storefront 。仅监听127.0.0.1；显著标注示例资料、无真实支付/打印/POS。预览调用真实插件控制器及原生CSRF类，但不冒充完整Core管理员认证验收。

预览若进程停止，启动命令（路由文件为绝对路径）：

```sh
/opt/homebrew/opt/php@8.3/bin/php -S 127.0.0.1:19101 /Users/xingweidai/Documents/Codex/2026-10-09/daiying-cms-official-restaurant-daiying-restaurant/outputs/restaurant_admin_preview_router.php
```

截图/浏览器证据：`/Users/xingweidai/Documents/Codex/2026-10-09/daiying-cms-official-restaurant-daiying-restaurant/outputs/restaurant-admin-preview`。

测试日志/候选文件哈希：`/Users/xingweidai/Documents/Codex/2026-10-09/daiying-cms-official-restaurant-daiying-restaurant/outputs/restaurant-admin-tests`。

## POS结果与实际限制

详见[官方资料研究](restaurant-pos-integration-research-2026-11-11.md)。Square/Clover代码与隔离验证见[POS实现报告](restaurant-pos-implementation-report-2026-11-11.md)，真实账号验收仍待应用和测试商户；Toast准入可并行申请；Lightspeed、Aloha、MICROS需先确认产品与资格。它们是常见候选，不是已证明的市场份额排名。

后续按Owner授权实现Square/Clover授权、门店、菜品对应、测试单、自动传单及出单方设置；软件验证使用明确标识的本地替身响应，没有真实品牌授权、商户订单或设备验收。缺少开发应用和测试商户，真实连通仍未验证。网站已收款订单须在POS记录为外部已收款，不能再次扣款；默认出单方必须明确以避免双重厨房票。

照片仍需从媒体库复制地址，没有新增媒体上传器。滑块数值保存和布局已测试，未用真实门头照片验收裁切。隔离预览使用Core1.2.97外壳；takeout的Core1.2.84上新版样式及原生签名安装仍待后续独立验收，不能用本地预览代替。

## 知识库同步状态

已更新插件`ADMIN_GUIDE.md`、POS研究、当前交付记录、文档索引和本周餐厅范围的候选核对说明。未更改Core中央知识JSON及其发布导出：本次为未上线插件候选，不能提前作为客服已发布事实。构建生产知识包、导入、索引和消费者应用均未执行。
