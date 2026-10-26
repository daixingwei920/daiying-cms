# Core1.2.90 正式发布报告

日期：2026-10-26（America/New_York）。状态：**PUBLISHED_PUBLIC_VERIFIED**。

PR70和准备报告PR71均已合并。按Owner授权，包含PR70的新1.2.90候选已原样发布到GitHub及官方更新服务；标签`v1.2.90`固定指向`449f92a258f2d02f78cb19f590c308ec268a1d43`。没有重新构建/重签包或移动标签，原c09b5ea冻结1.2.90包及旧证据保留。

## 公开下载、签名和版本

- [GitHub Release](https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.90)及Latest均为正式stable1.2.90，七个附件完整；[安装包](https://github.com/daixingwei920/daiying-cms/releases/download/v1.2.90/daiying-cms-1.2.90-stable.zip)可匿名下载。
- 官方更新服务发布条目为`daiying.cms:1.2.90:stable`；原生ReleaseManager公开可见性、manifest、SHA和兼容检查通过。
- **29项匿名公开检查PASS**：GitHub七个附件逐字节匹配冻结候选与API digest；官方更新包与GitHub更新包逐字节相同；manifest423文件/55项required migrations及全部负载哈希正确。
- **19项独立验签PASS**：固定官方公钥验证两种sidecar、公开registry签名及其字段/哈希绑定，Core原生Reader验证两个实际下载的更新ZIP。公钥指纹`8ee3baa72f432a925d18df9d9f03b2a02b4702d78a56fbc937b82c3ae4303f00`未变，私钥未导出。
- 全新合成site ID的版本选择：1.2.80–1.2.89选择新1.2.90；1.2.79不选择它；当前1.2.90不重复升级；PHP8.2不选择它。最低源/硬下限/迁移下限仍1.2.80，最低PHP8.3.0。选择检查不冒充所有历史版本实际升级。

| 产物 | 已发布SHA256 |
| --- | --- |
| daiying-cms-1.2.90-stable.zip | `62501532a9b73327d35535d6a52c895408393546cdfb984e3c55d5d7965d5b4d` |
| daiying-cms-core-update-1.2.90.zip | `0ad3dabdf064894733a88ca3c497075dcdf0fa6e6bd285caf11a85e1bfe1c427` |

同一冻结包已有正式preflight/build/verify、638项parity、SQLite原生升级/回滚/重试35项、HTTP新装6项和下载后验签6项证据。本次公开下载SHA证明字节未变，复用这些证据，没有重复昂贵Gate或付费模型调用。[准备报告](CORE_1.2.90_PR70_PREPARATION_REPORT.md)保留全部范围与限制。

## 官网下载缓存

公开发布后，官网因15分钟GitHub版本缓存暂显示1.2.89。核对实际主题源码哈希后，备份并仅将这一份公开release缓存标记过期，由既有主题正常重新读取GitHub；未部署主题/Core或改网站配置。

`/`及`/download`均实测HTTP200、显示1.2.90并指向本次正式安装包。缓存备份位于本次私有发布备份目录；没有清空全站缓存或重建知识索引。

## 生产变化及Owner手动升级

发布前备份与第一次发布后保护检查通过：生产为1.2.89/NORMAL，配置、指针、AI/支付/退款、原授权/批次/发卡库存及无关产品保持不变。

后续严格保护检查报告Core版本和活动指针变化，立即停止写入并只读调查，保留失败日志，没有改写原基线或关闭保护。Owner随后明确确认由本人手动升级。原生记录为管理员操作Completed，1.2.89→1.2.90，时间`2026-10-26T04:46:07+00:00`（当地00:46:07）。

只读复核确认生产health1.2.90/NORMAL/maintenance=false，356个活动Core文件逐一匹配已发布来源。除版本及活动指针这两项Owner升级结果外，其他受检生产字段、既有授权、授权批次/库存、商业规则和无关商品均保持原值。**Codex没有执行生产Updater、部署、回滚、支付/退款或模型请求**；Owner手动升级与本次发布操作分别记载，不能继续宣称生产始终未升级。

## 备份、知识同步和边界

官方分发库在修改前通过原生SQLite一致性备份，integrity_check=ok；私有恢复点为`/root/daiying-publication190-20261026-tC208f/backup`，包含分发库、服务源码及单个公开版本缓存备份。未把数据库或配置下载到工作站。发生分发故障时应先停止分发并按原生发布管理流程处理；完整数据库恢复须核对并发数据并由Owner授权，不能覆盖之后产生的交易或授权。

统一V1及README/CHANGELOG更新为已发布1.2.90，保留1.2.89历史及原冻结包来源。源码复核、真实公开验收和Owner手动升级分别记录。自动同步仍IMPLEMENTED_ISOLATED，生产知识导入、索引重建和Core优化器未接入。

独立客服1.1.1-dev.2仍未随Core包安装或在本轮发布；知识闭环7/7等已接受证据不等于该插件已正式上架。MySQL完整往返、真机及token账单不作为本轮PASS。冻结ZIP中的README仍含发布前1.2.89基线描述，未重写签名包；当前仓库README和本Release说明已更新，机器版本一致性以实际config/manifest/tag及签名元数据验证。

首次GitHub草稿按tag查询返回404，改用既有release ID查询后核对七个附件，没有重复创建发布。该草稿阶段情况和生产严格检查的Owner升级差异均保留，未将失败改写为PASS。

[结构化验收证据](CORE_1.2.90_PUBLICATION_EVIDENCE.json)及任务本地附件保存29项公开检查、19项验签、下载文件/SHA、生产保护及Owner升级只读核对。新旧冻结包均保持原字节；报告提交不移动发布标签或替换公开附件。
