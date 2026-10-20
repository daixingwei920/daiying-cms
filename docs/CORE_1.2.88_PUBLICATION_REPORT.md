# Core 1.2.88、Logo 1.0.1 与 Stripe 1.2.9 正式发布

Owner 于 2026-10-20 批准发布；已按 Core → Stripe → Logo 的依赖顺序发布，并完成公开下载和市场验收。

- Core：`v1.2.88` / `d64732de86ab70c09af0655c66d99da3f57f9220`，[GitHub Latest](https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.88) 与官方更新服务均为 1.2.88。
- Stripe：`official.payment.stripe:1.2.9:stable`，冻结源码 `ea732d39a9805bda25c4bac0f6a7a9710f489953`。
- Logo：`daiying.ai-logo:1.0.1:stable`，冻结源码 `cfd88bd0ac1e15850b93f57d6a6cb9abd0d5d0a2`；最低 Core **1.2.88**，PHP8.3。Logo 保持 **360元、单站、终身授权及终身更新**；Stripe 保持39元终身。

## 产物与验收

| 产物 | SHA256 |
| --- | --- |
| daiying-cms-1.2.88-stable.zip | `77012a3cb53b55fb204c9b8f57d0f13ae5541a63e9b6063cc70c1299c94642a6` |
| daiying-cms-core-update-1.2.88.zip | `78c4dd318d9827199002f3dac96c7c55371b0d8173f9871719c7a0af616c387e` |
| official-payment-stripe-1.2.9.zip | `9e7917718a332d8fe372842ef925474a281a6a3e4cf5fcbf2c81d312e32085c0` |
| daiying-ai-logo-1.0.1.zip | `677b48fccf9282c3d1bd2f9a9c15c48bd8e254d6ebf86f8a51c634049f46ebf8` |

复用验收后的冻结包；没有重建或覆盖旧版产物。Core正式签名与Release Gate635项通过，补核公共接口与v1.2.87兼容；SQLite/MySQL升级、数据保留、故障回滚及同包重试各22项通过。最新包真实生图、会员权限/免Worker整合、市场授权及原位升级、Stripe真实沙箱与六次并发事件验收此前通过；包和运行时代码未变，本次不重复触发AI或支付。

本次公开分发实测：

- GitHub七个附件实际匿名下载，响应、API digest与逐份SHA256均匹配；标签指向精确冻结提交。
- 官方更新服务的实际公开更新包下载与GitHub更新包相同；三份公开注册表签名、两份GitHub侧签名和两端下载更新包的原生验签，通过固定官方公钥验证，私钥未导出。
- 八个新站点版本选择：1.2.80/83/84/85/86/87可选择1.2.88；1.2.79保留旧兼容1.2.81，1.2.88无更新。版本选择不代表已逐一实跑全部历史升级来源。
- 市场search/version/latest的版本、包哈希、价格、授权要求和最低Core一致；Logo1.0.1在Core1.2.87明确不兼容。
- 两款付费包匿名下载403。分别生成一枚专用于此次非销售验收、仅绑定隔离站的授权码，未进入客户库存或创建订单，密钥未导出。公开原生POST授权200、真实包下载200且哈希相同、令牌重放403；实际公开下载包通过原生MarketPackageInstaller在隔离Core1.2.88安装并启用，Stripe官方trusted grant有效，/ai-logo HTTP200并读取测试站名称。
- 历史客户授权码未修改；旧售价、购买/支持链接和商业字段保留。

## 已知限制与测试脚本修正

分发服务原有公开付费授权POST在超出单站上限时返回通用HTTP500/server_error；原生仓库同一请求明确为license_site_limit_reached，第二站未获得授权。正常有效授权、下载、安装通过。此为既有拒绝场景的错误提示/HTTP状态不足，未作为新版本功能修复，也未修改分发服务源码。不能将500本身当作权限有效的证据；本次以原生精确拒绝原因交叉核对。

验收脚本最初期望具体拒绝文本，后改为交叉验证原生原因；同一专用验收授权码继续使用，没有重复发客户码。脚本另曾未关闭SQLite查询游标就启动第二个Application连接，造成隔离测试锁等待；已停止该专用进程、关闭游标并完成验收，没有修改或重新发布Core/插件包。艺术SVG是可编辑路径轮廓，不是可直接改字的文本对象；中文准确性仅代表已检查样本，下载仍须人工校对。真机、所有模型与所有历史数据库环境没有穷尽验收。

## 官网保护、备份与回退

官网发布前后均 **Core1.2.87 / NORMAL / maintenance=false**。本次没有升级正式站，也没有部署主题、插件或Worker。收款设置、AI设置、支付/退款表、config、活动Core指针、Logo服务文件、Cron及三个受保护失败作品前后哈希相同，原有客户授权不变。没有创建支付、调用AI或发起退款。

分发库在写入前一致性备份并验证integrity_check=ok：`/root/daiying-publication188-20261020-3uPxkI/backup`。逐包使用现有ReleaseManager/ReleaseSigner事务发布；校验官方信任根、签名、manifest、哈希、兼容性、Web用户读取及文件权限。若后续需要回退，应先暂停新授权分发，按备份和审计记录撤销新增release并恢复相应current_version，GitHub切回上一Latest；不要覆盖期间新客户数据或移动旧标签，任何生产回退另须批准。

## 知识库同步状态

更新AGENTS正式基线、本发布记录、文档索引和本日知识核验记录；统一V1 JSON固定到真实v1.2.88与完整commit，并刷新Markdown/USER-FACING派生文件。43条事实、22条公开事实、42个固定来源文件离线校验通过；源码事实保持SOURCE_REVIEWED/NOT_RUN_THIS_TASK，实际运行和发布证据独立记录。

未导入生产知识库、重建索引、部署同步或应用到客服消费者。此次文档后续提交不修改v1.2.88标签、冻结提交或已签名产物。

本地证据：`outputs/publication-1.2.88/summary.json`、`public-verification.json`、`public-signature-verification.json`、`public-market-install.json`、`protected-after.json`、`full-production-protection.json`；冻结包与发布前证据位于`outputs/release-1.2.88-preparation/`，私有材料不纳入Git或发行包。
