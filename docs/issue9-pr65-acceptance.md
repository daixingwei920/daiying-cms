# PR #65 隔离验收补充

执行时间：2026-10-24 UTC。Owner 授权运行隔离 Release Gate、原生更新包升级/回滚、真实 AI 客服和 Logo 端到端；禁止合并、正式发布及生产部署。

## 固定来源与最小修正

实际受测运行源码：`aa1fde0bfcbef7e98098021ffd3e7501829aa99c`，服务器独立 clean clone；PHP 8.5.9、SQLite。测试服务只监听 127.0.0.1，结束后停止。文档及知识包后续提交不改变此运行源码。

发现新增 `scripts/prune_crawler_log.php` 不在既有更新包允许清单内，旧站升级后无法取得该入口。提交 `aa1fde0` 将同一清理操作接入已支持的 `php cli.php seo:crawlers:prune`，保留原清单和全部验签/完整性检查。实际通过原生更新包安装后执行该命令，过期行被物理清除。

候选版本号仍为 1.2.88，仅用于本次隔离验收；没有移动正式 v1.2.88 标签或替换其已发布包。本轮验证 **正式 1.2.87 → PR65 候选**，不能据此声称已发布 1.2.88 能直接更新到同版本候选。后续发布须另行确定新版本、取得授权并通过原有正式流程。

## 实跑结果

| 验收 | 实际结果 | 证据边界 |
| --- | --- | --- |
| `php tests/release_gate_v1_contract.php` | PASS | 授权更新服务器执行，包含不完整 Core 包拒绝断言 |
| `php tests/release_phase0_gate.php` | PASS | 指定提交安装/更新包及篡改包拒绝测试 |
| `php tests/frozen_migration_checksums.php` | PASS | 原有冻结迁移校验 |
| `release_parity_gate.php --commit=HEAD --installer-zip=… --update-zip=… --update-metadata=… --require-signature=1 --json=1` | PASS，635项 | 完整文件清单、哈希、API兼容、迁移、签名及元数据；隔离测试签名 |
| 原生 Updater 升级/回滚/重试 | PASS，25项 | 下述真实版本切换及保留检查，SQLite |
| 原生 HTTP 真实 AI 端到端 | PASS，19项 | 客服、Logo、Core 注册/登录、导出及跨会员隔离 |
| 知识包构建与校验 | PASS | 44事实、22公开事实、45固定来源；源码复核和运行证据仍分别记录 |

签名仅在更新服务器隔离目录生成和使用。没有使用正式签名私钥；站点只信任临时测试公钥。安装器/原生 Updater/插件市场安装器均正常验签，没有硬编码信任、修改断言、绕过 integrity guard 或改生产信任根。

安装包 SHA256：`d880e72cc88f996390e23a7dfcbd04903a9bc0ddaf3e27150535cc6e91f4e6b3`。

原生完整更新包 SHA256：`ae34bcf77114061c127760d2e5bb44db7d59606d31df89a9b25d3aab709de10e`。

## 更新包升级与自动回滚

使用正式 1.2.87 安装包（提交 `a5031178b4b30d1bf50c289953ac9a76a0c3832b`，SHA256 `48f1257824abe9d88b61cd169c4405e64a857d68ea74090e42044f12b74e8e37`）新装两个独立站，只生成合成账号、文章、媒体、插件作品/积分和不调用网关的支付账本。

- 原生 dry run 保持原版本、配置和业务数据；真实升级进入 Completed，HTTP health 从1.2.87变为候选1.2.88；活动 Core 每个文件匹配冻结 manifest。
- 管理员、会员、中文文章、媒体、插件文件/数据、模拟支付账本与站点配置保留；实际 workspace GET/POST 都返回410；旧导航11个目的地及主题仍可用；新增CLI清理实际生效。
- 在第二站用合成 SQLite trigger 拒绝更新任务进入 `health` 步骤。故障发生在真实活动指针切换之后，原生 Updater 返回 RolledBack；HTTP health恢复1.2.87，原指针状态、web/CLI/registry支持文件及所有受检配置/业务数据恢复，退出维护/恢复模式。
- 移除该测试 trigger 后，以同一有效签名更新包重试，Completed/HTTP1.2.88且受检数据仍保留。

这里是实际更新包/Updater自动回滚，不是替换源码目录。回滚恢复旧版运行行为仅发生在合成隔离站；生产回滚是否恢复已退役能力仍须 Owner 决定。

## 真实 AI 客服与 Logo

在已真实升级的成功站，使用生产已安装插件的只读代码快照：AI 客服1.1.0、Logo1.0.1。通过全新私有离线测试目录生成测试授权及官方扩展信任声明，原生 `MarketPackageInstaller` 正常验证安装；生产市场目录、价格、授权和客户许可证没有写入。

AI 配置密文仅在服务器复制到隔离数据库，密钥只在服务器内存读取；没有复制生产数据库、会员资料、支付密钥或会话。隔离站禁用支付供应商，Logo测试策略设为免费。测试结束清除隔离AI凭证、生产密钥引用、临时签名私钥及合成授权信封。

客服通过真实后台表单设置 `gpt-4.1-mini`，手动录入虚构“星桥实验室”资料；无 FAQ、无模型 mock、无注入客户端。原生 `/api/ai-customer-service/chat` 在1.773秒返回 HTTP200/ok/grounded 和 manual/trusted 来源，实际回答：

> 星桥实验室开放时间为每周二下午14:10至16:40，预约代号是蓝鲸47。

隔离数据库保留一条原生 `knowledge.retrieve` 事件。现有客服插件调用未计量的 `AiService::chat`，因此不声称客服 token 账本验收通过。本次使用合成手动知识，不等于新V1产品知识包导入/自动同步验收。

Logo经过真实 Core 注册并返回 `/ai-logo`，使用同一会员会话提交“星桥”艺术字，HTTP执行调用 `gpt-image-1.5`，12.23秒完成。原生 PNG/SVG 下载均为HTTP200；PNG1024×1024，SVG为插件原生栅格描摹导出，非模型原生矢量。已直接查看生成图片，未将风格偏差或所有设计模式标为视觉PASS。插件仍要求用户逐字校对后导出。

图像任务与用量账本各只有一条成功记录：输入486 tokens，输出1393 tokens（total1879）；完成后再次execute返回同一作品，历史无重复。保留的老会员仍可登录，读取新会员作品返回404；匿名历史接口返回401。

PNG SHA256：`20f723c2f15f5cb14a34b1ff2804a9c0670d8d98e4e879e712cd2eabe3655501`；SVG SHA256：`6653a0e5b4384c15fce9abae6cc37388a00a66a3e88db90f69850efbc219c429`。

## 实际证据与保留门禁

[脱敏结构化验收证据](issue9-pr65-acceptance.json)包含25项更新检查、19项HTTP检查、实际客服响应、图像用量/文件哈希和生产保护前后摘要。

完整25文件证据包保留于本任务 `outputs/pr65-acceptance/safe-evidence.tar.gz`，包含635项parity结果、服务器Gate输出、真实Updater结果及AI产物。SHA256：`f4804025cd742f9f7a4fbcf4a0ad23120e71a7cfd48b4bcc83a59beb20b824c2`。原始日志/图片留在仓库外，遵守AGENTS禁止将日志、临时站点和签名输入放入Core提交/发布包的规则。

生产连接设置 `PRAGMA query_only=ON`，配置文件、活动版本指针、AI设置表、支付供应商设置表及两个插件manifest的前后SHA256全部一致；没有生产写入、支付网关下单、退款、正式市场目录更新、合并或部署。隔离PHP服务已停止。

正式 `scripts/release/preflight` / build / publish 要求 exact origin/main，本次按“不合并”指令没有执行，也没有绕过。正式签名/发布门禁仍保留。移动真机、真实支付、任意第三方主题、所有Logo模式和新V1知识包消费者导入未验收。
