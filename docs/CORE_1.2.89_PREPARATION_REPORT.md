# Core 1.2.89 正式签名候选验收报告

日期：2026-10-24（America/New_York）。状态：**SIGNED_CANDIDATE_ACCEPTANCE_PASSED_NOT_PUBLISHED**。

Owner 本轮授权：合并 PR #65、准备下一版本、正式服务器签名及 Release Gate；完成报告后停止。未授权正式发布或生产部署。

## 来源与交付

- [PR #65](https://github.com/daixingwei920/daiying-cms/pull/65) 已合并；冻结 main：`3dcc700174abd1b9a7d2959a09881f247026de1c`。
- 版本准备 `9db5691`：配置版本升为1.2.89、更新CHANGELOG与授权边界；知识包来源同步 `4780a92`。没有顺带修改其他运行代码。
- 功能为已验收的会员协作退役、可选SEO/GEO、公开Schema/llms.txt、AI爬虫策略及有界统计；保留普通会员、管理员、共享AI和历史业务数据。清理命令为 `php cli.php seo:crawlers:prune`。
- 干净服务器 main 克隆与 origin/main 完全一致，正式 preflight/build 按原规则运行；没有跳过分支、完整性、签名或包清单保护。
- 使用既有官方更新服务器的现有 Ed25519 签名器与信任根；没有生成新正式密钥，没有导出或复制私钥。产物只写入私有准备目录，没有调用 publish 或更新正式目录。

## 实跑验收

| 检查 | 实际结果 | 范围 |
| --- | --- | --- |
| `bash scripts/release/preflight <冻结提交>` | PASS | clean/exact origin/main、未占用标签、冻结迁移、通知、Plugin SDK、Release Gate V1、Phase0、源码parity |
| `bash scripts/release/build <冻结提交>` | PASS | 该提交完整安装包及Core所有权完整更新源；不从diff拼包 |
| 官方服务器原生构建/签名与 `UpdatePackageReader` 验签 | PASS | 完整更新包420文件，55个必需迁移；既有官方公钥 |
| 正式签名安装包+更新包 `release_parity_gate.php --require-signature=1 --json=1` | **635项PASS** | 指定提交、文件/哈希、签名、元数据、完整Core、与v1.2.88的公开接口兼容 |
| `bash scripts/release/verify 1.2.89 <签名更新包> <准备元数据>` | PASS | 使用未发布候选元数据，不冒充线上latest响应 |
| 正式1.2.88 → 正式签名1.2.89原生升级/自动回滚/重试 | **25项PASS** | 两个全新隔离SQLite站；真实HTTP版本及数据保留，见下文 |
| 正式签名安装包原生HTTP新装 | PASS | /install表单→POST302，health=1.2.89/NORMAL，workspace=410 |
| 下载到工作区后的两种产物公钥验签及哈希复核 | PASS | 只使用公钥；签名payload绑定冻结提交 |
| 55个已发布迁移逐字节比较 | PASS | 与v1.2.88迁移目录无差异，没有down/DROP |
| V1知识包生成及离线校验 | PASS | 44事实、22公开事实、45固定来源；未导入生产消费者 |

升级测试使用正式1.2.88安装包，SHA256 `77012a3cb53b55fb204c9b8f57d0f13ae5541a63e9b6063cc70c1299c94642a6`，只生成合成账号、内容、媒体、插件作品及不调用网关的支付账本。

dry run没有版本或数据变化；真实升级Completed，HTTP1.2.88→1.2.89，活动Core匹配每个冻结manifest哈希。配置、管理员/会员、文章、媒体、插件文件/数据、主题及合成账本保留；工作区GET/POST返回410，更新后CLI实际清除过期聚合行。

第二站通过合成数据库trigger，在真实活动指针切换之后拒绝进入health步骤，原生Updater完成RolledBack。HTTP恢复1.2.88，原指针、web/CLI/registry支持文件、配置及受检业务数据恢复，维护/恢复模式均关闭；移除测试trigger后，以同一正式签名包重试Completed/HTTP1.2.89且数据保留。回滚只发生在合成站；旧版会恢复原运行能力，生产回滚仍需单独决定。

## 冻结产物

| 产物 | SHA256 |
| --- | --- |
| `daiying-cms-1.2.89-stable.zip` | `7831edfee8c8d3161aa8ca92f026019b99355ba764e6a98dfe002373cd7f052a` |
| `daiying-cms-core-update-1.2.89.zip` | `23ee08234d1726e100208ec4dddd68a031f8c1f6d1dfdcb019af9614e9cc9cc1` |

两个ZIP均有官方签名sidecar及SHA256文件。最低升级源/硬下限/迁移下限均保持1.2.80，PHP最低8.3.0。公钥SHA256指纹：`8ee3baa72f432a925d18df9d9f03b2a02b4702d78a56fbc937b82c3ae4303f00`。

服务器私有冻结目录：`/root/daiying-release189-preparation-20261024-aXsImp`。正式ZIP及签名sidecar设置为只读。报告后续提交不改变冻结运行源码，不重建签名包，也不移动标签。

## 已有证据复用与未测范围

本次冻结提交相对已通过真实AI验收的 `aa1fde0bfcbef7e98098021ffd3e7501829aa99c`，Core、迁移、manifest、CLI及public运行文件逐字节一致，只有版本配置和文档/知识记录变化。因此保留[真实客服/Logo的19项验收证据](issue9-pr65-acceptance.md)，本轮没有再次调用付费模型；不把旧实跑改标为1.2.89新实跑。

本轮正式签名包往返为SQLite；之前的MySQL退役/SEO/迁移检查保持独立证据，未声称重新完成MySQL签名包往返。移动真机、真实支付、任意第三方主题和新V1知识包消费者导入未验收。

## 生产保护与停止点

生产与分发数据库均只读检查。生产配置、活动版本指针、AI设置、支付供应商设置和退款记录摘要前后一致；官方分发配置及全部受检目录/授权表的行数和摘要前后一致。临时只读分发库副本已删除，三个隔离HTTP端口已关闭，服务器源码克隆保持clean。

GitHub Latest与官方stable仍为1.2.88；1.2.89标签不存在、正式目录条目不存在。没有创建GitHub Release、上传正式发布附件、写入官方更新/市场目录、部署生产、创建真实支付或发起退款。

**停止点已达到：签名候选及Release Gate验收完成，等待Owner另行决定正式发布。**

[结构化验收证据](CORE_1.2.89_PREPARATION_EVIDENCE.json)保存实际检查、产物、版本状态及前后保护摘要。完整23文件证据/签名产物包位于本任务 `outputs/release-1.2.89/signed-candidate-evidence.tar.gz`，SHA256 `013c15a69352db9931c84f753df258dd9de46a7d301eb40d3cd16b02004cce44`。日志、数据库、会话和签名输入不进入Core提交或正式发行包。
