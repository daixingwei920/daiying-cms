# Core1.2.90 合入PR70后的正式签名候选验收

日期：2026-10-26（America/New_York）。状态：**SIGNED_CANDIDATE_ACCEPTANCE_PASSED_NOT_PUBLISHED**。

Owner已接受PR70隔离验收、授权合并并明确目标仍为1.2.90。PR70已合并；没有准备1.2.91。本轮在独立目录生成新的1.2.90签名候选，原冻结1.2.90包、sidecar及证据归档逐字节保留。没有正式发布或部署/升级生产。

## 来源及两份同版本候选

- 新候选：干净且精确对齐origin/main的`449f92a258f2d02f78cb19f590c308ec268a1d43`，即PR70合并提交；服务器私有目录`/root/daiying-release190-pr70-preparation-20261026-LaF79i`。
- 原候选：`c09b5ea10f31cf0203a5cf4b73df1d628c8cf546`，保留在`/root/daiying-release190-preparation-20261024-F0Gk9Q`；本地旧目录`outputs/release-1.2.90`未覆盖。
- 新包仍是未发布的1.2.90，使用独立目录和完整commit/SHA识别。未来发布必须明确选择经Owner批准的那份产物，不能仅凭文件名或版本号混用旧包、签名或元数据。
- 相对已实跑的`6cb82a85f4560f9f8ef6b86e850a202b75208a53`，Core运行文件、manifest、迁移、后台资源及web/CLI入口逐字节一致，356个Core文件与manifest匹配。复用已接受的23项观测、93项蜘蛛、65项SEO/GEO和40项导航专项，不冒充本轮重跑。

## 本轮正式包实跑

| 检查 | 结果 |
| --- | --- |
| 正式release/preflight | PASS；clean/exact-main、未占用v1.2.90标签、迁移冻结、通知、Plugin SDK、Gate V1、Phase0、源码parity及v1.2.89公开API兼容 |
| 正式release/build | PASS；同一完整冻结来源构建安装包与Core-owned更新源，不从diff拼包 |
| 既有官方服务器签名器及原生验签 | PASS；更新包423文件、55项required migrations；同一Ed25519信任根，未生成正式新钥或导出私钥 |
| 签名安装/更新包parity | **638项PASS**；require-signature=1，同源、manifest、迁移、签名和私有准备元数据一致 |
| 正式release/verify | PASS；针对新候选的私有元数据，不冒充线上latest |
| 正式1.2.89→新签名1.2.90原生Updater | **35项PASS**；全新隔离SQLite站，dry-run、升级、切换后故障自动回滚、同包重试与数据保留 |
| 正式完整包原生HTTP新装 | **6项PASS**；安装、health1.2.90/NORMAL、356个Core哈希、默认首页及workspace410 |
| 下载后独立公钥/哈希复核 | **6项PASS**；两种产物、sidecar、固定公钥、来源commit与原生UpdatePackageReader |
| 原冻结包及生产/分发保护 | PASS；本地旧包/签名/归档8文件、服务器旧包/sidecar7文件SHA一致；受检生产/分发表摘要前后一致 |

最低升级源、硬下限及迁移下限仍为1.2.80，最低PHP8.3.0。没有新SQL迁移、down或DROP迁移；既有迁移文件未改。SQLite结果不扩展为MySQL完整往返验收，真机继续DEFERRED_BY_OWNER。

首次升级测试因未执行测试站初始化而启动失败，错误发生在Updater调用前；失败日志保留。将仅含主题标记的未初始化目录隔离保存后，调用既有原生安装夹具初始化两站，再以同一签名包测试通过。没有修改Core、重签包、关闭完整性或改动生产来修正夹具准备。parity的结构化JSON由已完成的原始CLI日志提取；未为输出格式重复运行已通过的昂贵Gate。

## 升级、回滚与保留范围

使用正式1.2.89安装包建立全新success和rollback站，只含合成管理员/会员、中文文章、媒体、主题、插件数据及不调用网关的支付账本。升级Completed后实际HTTP为1.2.90，356个活动Core文件匹配来源；配置、账号、内容、媒体、插件/主题及账本保留。七种国内蜘蛛UA聚合、后台统计、原导航和分组、CLI清理及已退役workspace410一并检查。

回滚站使用合成SQLite trigger在实际指针切换后拒绝health步骤，原生Updater返回RolledBack，实际HTTP恢复1.2.89。指针、支持文件、配置及受检业务数据恢复，维护/恢复模式关闭。只移除隔离trigger后，同一正式签名包重试Completed并恢复HTTP1.2.90；这不是生产回滚。

## 新签名产物与旧包保留

| 产物 | 新候选SHA256（449f92a） | 原冻结SHA256（c09b5ea，保留） |
| --- | --- | --- |
| daiying-cms-1.2.90-stable.zip | `62501532a9b73327d35535d6a52c895408393546cdfb984e3c55d5d7965d5b4d` | `cd81ba0073d41f8c8a0599541f8d0e29eb9bac9feefca061fe5c74eaf475e61d` |
| daiying-cms-core-update-1.2.90.zip | `0ad3dabdf064894733a88ca3c497075dcdf0fa6e6bd285caf11a85e1bfe1c427` | `7086c8e734d40cef3e9756450b4c6edf6c58508213956a58660f7f0874d94903` |

新包及sidecar已设只读。公钥指纹仍为`8ee3baa72f432a925d18df9d9f03b2a02b4702d78a56fbc937b82c3ae4303f00`；签名payload绑定新commit和实际SHA，publication_status为SIGNED_NOT_PUBLISHED。原证据归档SHA仍为`96ad3a521cfb5f4c66ac89960dedd920829aa47ee5174a094763a6644ffdf0ca`。

## 客服插件与知识同步边界

Core包包含通用蜘蛛观测，知识工具仍独立于Core运行时。已接受的相关性修复属于独立客服1.1.1-dev.2，运行提交`694b00b4864aa109a75d28f40fadbfa2c2ba4854`，不进入Core安装/更新包。本轮没有将插件源码包变成正式签名市场包或执行其发布。**仅安装Core更新不会自动安装客服修复、导入知识或部署同步服务。**

此前业务7/7、9项专项、21项回归、41项真实模型HTTP及旧FAQ撤回检查作为已接受证据保留，不重复付费模型调用。价格按审核快照，词法/多实体与500候选上限限制保持。没有修改商品价格、授权批次或库存。

统一V1及派生物更新新签名候选来源、旧包保留和Owner接受状态；源码/文档校验与上述实际运行证据分别记录。新1.2.90仍UNPUBLISHED，不准入为已发布客服事实；生产消费者导入、索引重建、自动同步部署和Core优化器接入均未执行。

## 清理与停止点

三个测试HTTP端口已关闭；两份临时分发数据库副本删除，服务器签名来源clean。生产配置、活动指针、AI/支付/退款和受检全部分发表摘要一致。官网health仍1.2.89/NORMAL/maintenance=false；GitHub Latest仍v1.2.89，没有v1.2.90标签，官方分发目录未新增1.2.90。为避免产生分发租约，本轮未调用公开latest选择接口，官方catalog基线由只读副本和前后摘要确认。

[结构化证据](CORE_1.2.90_PR70_PREPARATION_EVIDENCE.json)与本任务独立附件提供实际Gate、验签、升级/回滚日志和SHA清单，不含数据库、生产对话、凭证或私钥。报告通过单独文档分支提交，不移动已签名来源或重建包。**停在候选验收完成：未正式发布、未部署或升级生产。**
