# 官网品牌与内容上线记录

Owner在审阅候选和生产版本纠正后，于2026-10-24批准“没问题就更新官网”。部署于2026-10-24T23:06:44Z完成；公开与浏览器验收通过，最后边界核查于23:11:01Z通过。

## 已应用内容

- [官网首页](https://www.daiyingcms.com/)展示“戴影CMS（Daiying CMS）”、清晰定位、真实隔离后台截图、下载/安装入口和面向访客的功能文案。
- [戴影CMS是什么](https://www.daiyingcms.com/what-is-daiying-cms)为原生CMS page，ID92，公开发布并加入原生Sitemap。支持品牌三种写法及可核查官网、GitHub入口，没有新增主题路由。
- 主题从1.0.8更新到1.0.9，仅5个运行文件：`theme.json`、`templates/home.php`、`templates/_theme.php`、`assets/brand.css`、`assets/admin-preview.png`。
- 仅7项公开设置变化：`site.name`和本主题的`hero_title`、`hero_subtitle`、`hero_description`、`site_description`、`site_keywords`、`docs_url`。

冻结主题运行来源：`28c2a940477f89cfd7e193e27f2aa1f44641b4bc`；完整19文件候选ZIP SHA256：`a4afbb5f9e82da75acc6f9c601ad54db5f82ecb7197ed38277ff55257bc1b2da`。文档记录更新不移动此来源或重建该包。主题未另行签名上架官方市场。

## 备份与保护

部署前服务器私有备份：`/root/daiying-brand-update-20261024-X0AVaZ/backup`，包含完整原主题tar、原配置、一致性SQLite备份及原始文件/保护数据摘要；SQLite integrity_check为ok。备份及生产密钥未带回本机。

执行前再次校验Core指针、配置、主题文件哈希和新slug；slug或URL映射被占用则拒绝覆盖。配置采用限定键更新及原子替换，页面通过原生ContentRepository创建。

执行后与备份基线比较确认：Core1.2.89指针与完整性不变，导航配置及CSS/JS不变；既有内容、支付Provider、付款/退款、卡密商品/库存、授权、会员、AI配置和插件文档等保护记录摘要一致。未发起付款、退款或AI生成；没有更改DNS、robots/AI政策、价格或授权规则。

恢复准备限于本轮文件、7项设置及将新增页面改回draft；不得整库恢复覆盖后来的业务数据。未实际触发生产回滚。

## 实际验收

- 前置隔离检查：Core1.2.88和1.2.89原生主题ZIP安装，每版本21项，共42项PASS。完整候选19文件与两个隔离安装逐文件一致。
- 生产公开HTTP28项PASS：首页与新页、健康、Sitemap、robots、博客、文档、安装教程、下载页及4个资源均HTTP200。
- 新页规范地址指向自身、robots为index,follow；首页只有一个可检索品牌H1和一个title；已有JSON-LD仍可解析。
- 正式Core1.2.89安装包链接及SHA256保留；新PNG/CSS与冻结包哈希相同，原导航CSS/JS哈希相同。
- Sitemap从140到141个网址，旧网址全部保留；robots原始字节未变。
- 原生浏览器1280×720桌面、390×844模拟手机视口：首页和新页、真实截图加载、首屏按钮、导航展开/子链接跳转、桌面Escape关闭均通过，无横向溢出。模拟视口不是实体手机验收。
- 生产健康仍为Core1.2.89、NORMAL、maintenance=false；没有升级、降级或重新发布Core。

原始JSON、HTML和实际截图在任务的 `outputs/brand-presentation/production-*`。本次Core仓库仅README、规则和说明文档变更；没有Core运行修改，不重复完整签名Release Gate或新版本包升级/回滚验收。

## 知识库同步状态

README、官网主题说明、原生页面输入及本上线记录已同步。无新增Core能力、API或数据库契约，V1事实源仍适用；派生和一致性检查PASS，无派生差异。未导入客服消费者、重建索引或启用自动同步。

公开页面、实际索引和AI引用是不同验收项。外部站长平台提交、GSC索引/重定向清单、AI自然检索命中仍未在本轮验证。
