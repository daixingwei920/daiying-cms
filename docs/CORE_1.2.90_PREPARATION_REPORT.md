# Core 1.2.90 正式签名候选验收报告

日期：2026-10-24（America/New_York；服务器原始日志为UTC）。状态：**SIGNED_CANDIDATE_ACCEPTANCE_PASSED_NOT_PUBLISHED**。

Owner明确批准合并PR #67、准备下一版本、完成正式签名与Release Gate；真机验收暂缓。完成报告后停止，不正式发布或部署生产。

## 来源与版本

- [PR #67](https://github.com/daixingwei920/daiying-cms/pull/67)已按正常流程合并。冻结main为`c09b5ea10f31cf0203a5cf4b73df1d628c8cf546`，候选版本1.2.90。
- 版本准备提交`9a15b24`、候选知识提交`8e574f4`随PR合入main；更改版本配置、CHANGELOG和授权边界，没有顺带增加其他功能。
- 相对已实跑的`7ef7704e89a9c5d1ac60581aa611e8920582f0e5`，Core运行文件、完整性manifest、全部迁移、后台资源、CLI和web入口逐字节相同。既有93项蜘蛛专项、65项SEO/GEO、40项导航及受控采样/模拟浏览器证据继续适用，不冒充本轮重新实跑。
- 仍保留收集样本口径、非阻塞锁丢样说明、未验证UA来源、隐私限制和原有鉴权/API；采集不是完整访问量、搜索收录或AI引用。

## 实跑验收

| 检查 | 实际结果 | 范围 |
| --- | --- | --- |
| 正式`release/preflight` | PASS | clean/exact origin/main、未占用版本标签、冻结迁移、通知、Plugin SDK、V1、Phase0、源码parity；公开API兼容v1.2.89 |
| 正式`release/build` | PASS | 同一冻结main的完整安装包与完整Core-owned更新源，不从diff拼包 |
| 既有官方服务器签名器 + 原生验签 | PASS | 同一官方Ed25519信任根，完整更新包422文件、55个必需迁移；私钥只在服务器内存使用 |
| 正式安装/更新包签名parity | **637项PASS** | 同源、完整manifest/哈希、签名、迁移及兼容性；require-signature=1 |
| 正式`release/verify` | PASS | 1.2.90候选签名包与私有准备元数据一致，不冒充线上latest |
| 正式1.2.89 → 正式签名1.2.90原生Updater | **35项PASS** | 两个全新隔离SQLite站，dry-run、升级、切换后故障自动回滚、同包重试及业务保留 |
| 正式签名完整安装包原生HTTP新装 | **6项PASS** | /install GET200→POST302、health190/NORMAL、355个Core哈希、默认首页200和workspace410 |
| 下载后的独立公钥/哈希复核 | **6项PASS** | 两种产物官方公钥验签、固定SHA、冻结commit绑定、原生UpdatePackageReader |
| 生产/分发保护前后摘要 | PASS | 配置、活动指针、AI设置、支付供应商/退款和全部受检分发表一致 |
| V1知识包 | PASS | 冻结源码校验45事实/22公开/49来源；交付文档新增候选事实来源后再生成与校验。源码复核不冒充运行PASS |
| 移动真机 | **DEFERRED_BY_OWNER** | 不作为本轮阻塞条件；没有真机截图、触摸或设备浏览器PASS声明 |

最低升级源/硬下限/迁移下限均保持1.2.80，最低PHP8.3.0。原55个迁移保留；没有新SQL迁移、down或DROP迁移。MySQL正式签名包往返本轮未测，不能将SQLite结果扩展为MySQL整体验收。

## 实际升级与自动回滚

使用正式1.2.89安装包（SHA256 `7831edfee8c8d3161aa8ca92f026019b99355ba764e6a98dfe002373cd7f052a`）新装success和rollback两站，只建立合成账号、中文内容、媒体、主题/插件及不调用网关的支付账本。测试前开启蜘蛛采集；生产配置未复制。

dry-run保持原版本和受检数据；实际升级Completed，HTTP health由1.2.89变为1.2.90，活动Core355文件匹配冻结manifest。配置、管理员/会员、中文内容、媒体、主题、插件文件/数据及合成账本保留。七种国内UA通过隔离HTTP各访问一次，全部200且留下七个canonical聚合，查询秘密和文章具体slug未写入日志；升级后的真实管理统计路由返回200并显示已采集口径。

第二站以合成SQLite trigger拒绝操作进入health步骤，故障发生在真实活动指针切换之后。原生Updater返回RolledBack，HTTP恢复1.2.89；原指针状态、web/CLI/registry支持文件、配置和受检业务数据恢复，维护/恢复模式关闭。仅移除该隔离trigger后，以相同正式签名包重试Completed/HTTP1.2.90。没有修改Core或绕过验签/完整性来完成回滚；未进行生产回滚。

## 冻结正式签名产物

| 产物 | SHA256 |
| --- | --- |
| `daiying-cms-1.2.90-stable.zip` | `cd81ba0073d41f8c8a0599541f8d0e29eb9bac9feefca061fe5c74eaf475e61d` |
| `daiying-cms-core-update-1.2.90.zip` | `7086c8e734d40cef3e9756450b4c6edf6c58508213956a58660f7f0874d94903` |

两种ZIP均有官方签名sidecar、SHA文件，安装包另有完整manifest。公钥指纹：`8ee3baa72f432a925d18df9d9f03b2a02b4702d78a56fbc937b82c3ae4303f00`。签名payload绑定上述冻结main和产物SHA；没有生成新正式密钥、导出私钥或修改信任根。

服务器私有冻结目录：`/root/daiying-release190-preparation-20261024-F0Gk9Q`。ZIP、签名及相关产物已设只读。后续报告与知识更新独立于冻结源码，不重建包、不移动标签。正式元数据的publication_status为SIGNED_NOT_PUBLISHED。

## 知识库同步状态

保留已发布1.2.89历史证据，新增1.2.90正式签名但未发布的候选记录、教程兼容/统计口径和本报告来源。构建/离线校验与真实运行证据分别记录；未发布的国内蜘蛛事实仍只面向管理员/开发者，不导出客服。

未导入生产消费者、重建索引、应用客服或部署自动同步；未重复付费AI、支付/退款或真实蜘蛛探测。统计缺样与来源身份限制不因正式签名而改变。

## 停止点与交付

公开GitHub Latest与官方stable仍是1.2.89，官网health仍为1.2.89/NORMAL/maintenance=false。没有v1.2.90标签或GitHub Release，正式分发目录没有1.2.90条目；没有正式发布、部署或升级生产，也未改变价格/授权和生产设置。

三个隔离HTTP端口均已关闭，服务器源码clean；两个临时只读分发库副本已删除，没有把数据库、原始日志或密钥带到工作站。完整42文件证据/正式签名产物包随任务交付：`outputs/release-1.2.90/signed-candidate-evidence.tar.gz`，SHA256 `96ad3a521cfb5f4c66ac89960dedd920829aa47ee5174a094763a6644ffdf0ca`。包含实际637项parity、完整构建/验签/Updater日志、回滚结果与保护摘要，不含私钥或数据库。

[结构化验收证据](CORE_1.2.90_PREPARATION_EVIDENCE.json)记录实际产物、检查和停止状态。报告单独通过文档分支提交，保持main冻结。**正式签名候选验收完成，等待Owner另行决定正式发布。**
