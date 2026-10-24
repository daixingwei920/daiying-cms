# Issue #9 第一批隔离交付与验收记录

Owner 指令：2026-10-23 Issue #9 评论6378848739。本报告是候选代码审阅材料；未合并、未发布、未部署、未进行生产写入或破坏性迁移。sitemap 故障 NOT REPRODUCED，不修改 sitemap 生成逻辑。

## 代码与回滚边界

- clean clone 基线：a7f99ffdd61eace1b997516a2d91376f5f72f602，Core1.2.88。
- A：b75c8d4，完整退役会员协作入口/运行授权/专属服务与能力。所有方法和后代路径均在路由前返回410/private/no-store。旧管理员、会员会话和历史授权不能重新激活。历史迁移、表、列、草稿、授权数据保留。
- 契约先行：0458b32，冻结本次字段与输出行为。实施中补充了历史自动 seo_title 与文章标题相同的兼容规则，未扩展 SDK 或机器发布 API。
- B：3b7e7e5，实现既有站点设置中的 SEO/GEO 选项、TDK、Schema/plugin metadata 实际输出、公开内容 llms.txt、AI 爬虫声明与受限蜘蛛统计。补充测试及架构/API/插件/主题规范。
- A/B 可各自 revert；B 只增加可选配置与有上限的私有文件，不增加数据库迁移。A 回滚会恢复已退役协作入口，必须由 Owner 决定是否接受恢复该能力。不可静默回滚后重新开启。
- 源码备份 SHA256 及保留策略见 member-editorial-retirement.md。生产升级前仍需另行授权和生产备份。
- 所有已发布迁移已逐字节与基线比较，一致；没有数据库 down/DROP/历史记录清理。

## 已实现输出与已知限制

- 保留所有旧 SEO 键、管理路由及主题/插件/REST 签名。旧设置表单不会清空新增项；新配置默认关闭/继承旧行为。
- 独立 SEO 字段优先于模板；历史 Core 自动存入的“与文章标题相同的 SEO 标题”视作默认标题。没有历史用户手动输入同值的来源标记，因此开启标题模板时这两种情况不可区分；UI 已说明。关闭模板恢复旧输出，不改文章数据。
- Schema 只输出公开元数据，无 articleBody、私密作者或内部上下文；草稿、preview、noindex、付费正文页面不输出 Core Schema。完整 ThemeRuntime head 实测；绕过 ThemeRuntime 的首页 provider 自主管理 HTML，本轮未扩展其契约。
- llms.txt 最多读取200条最近更新的已发布文章/页面，排除 noindex、付费、损坏 meta、异站 canonical；仅列标题和链接，不导出正文。不是全站或自定义插件目录，也不保证收录或推荐。
- robots AI 搜索与训练策略分离，特定允许组重复私有路径禁止规则；全局 noindex 优先。它是抓取声明，不能替代鉴权或强制阻断不遵守规则的客户端。
- 蜘蛛统计为 UA 自称、来源未验证。只记录 UTC 日期、已知机器人名、公开路由类别、状态、次数；不记录 IP/原始 UA/查询/凭证。最多2,000聚合行、512KiB；保留1–30天，读取/写入时清理；锁竞争有意丢采样，不等于准确访问总量。
- 空闲站点物理删除需要主机定期调用 `php scripts/prune_crawler_log.php`。未声称已经为生产站配置定时任务。满容量、锁、损坏或不可写记录均不阻断正常页面；没有每次请求的外部 DNS/IP 验证。

## 实跑测试矩阵

环境：本机 PHP8.5.8 / SQLite3.53.4；临时、仅127.0.0.1监听的 MySQL9.7.1，原生 PDO prepares。临时 MySQL实例已停止并移除数据目录。合成账号/内容，不使用生产数据或付费模型。

| 命令/检查 | SQLite/本机 | MySQL | 实际边界 |
| --- | --- | --- | --- |
| tests/member_editorial_access.php | 287 PASS | 287 PASS | 2个根路径×4身份×7路径×5方法，以及登录、服务退役、历史数据保留 |
| tests/member_editorial_migration.php | 10 PASS | 10 PASS | 原迁移升级/幂等与历史记录保留；迁移未改 |
| tests/seo_geo.php | 63 PASS | 63 PASS | 实际 Application/主题输出、Schema/模板、权限/CSRF、旧表单、llms、robots、隐私/容量/锁/保留期、子目录 |
| tests/crawler_log_concurrency.php | PASS | 不适用 | 4个实际进程共尝试400次；JSON保持有效、有界且无超计数；非阻塞竞争会丢采样 |
| tests/issue9_source_roundtrip.py | PASS | 未跑该往返 | 原源码→候选→原源码，workspace状态200→410→200，身份/授权/草稿/迁移摘要相同 |
| tests/core_sitemap.php | 42 PASS | 未重复 | 既有sitemap/SEO回归 |
| tests/frontend_extension_api.php | 20 PASS | 未重复 | 插件前台注入契约 |
| tests/theme_api_v1.php | 15 PASS | 不适用 | 已有主题API兼容 |
| tests/rest_api_v1.php | 27 PASS | 未重复 | 原REST契约；未扩大媒体写入/机器授权 |
| tests/foundation_system_services.php | 35 PASS | 未重复 | 原系统服务/调度基础回归 |
| tests/core_ai_settings.php | 36 PASS | 未重复 | 站点AI设置与provider mock |
| tests/ai_images_direct.php | 19 PASS | 未重复 | 共享会员/直接持久AI执行，外部请求0 |
| tests/ai_foundation_v1.php | 18 PASS | 未重复 | AI基础公共契约 |
| tests/phase1_passkey_ai_writing.php | 17 PASS | 不适用 | 原Passkey/AI写作入口及源码契约检查 |
| PHP lint / git diff --check / manifest / published migration comparison | PASS | 不适用 | 当前改动范围；不替代正式Release Gate |

源码往返三阶段的数据摘要：3efc4d1dd59e932500fff5e44ab6724c97e92db7680318ff0ecfee0fb376a882。可运行测试脚本复现；该结果不是签名更新包/Updater回滚验收。

桌面 IAB 浏览器原生测试：合成管理员密码登录→后台菜单无会员协作→保存TDK→重新读取配置→实际文章浏览器标题改变；蜘蛛统计显示“来源未验证”；旧工作区显示退役；普通会员原登录表单仍可登录，文章评论区确认“当前以 Member 身份评论”。设置区、文章和统计截图保存在本任务 outputs/issue-9-evidence，未发布到生产。测试服务器已停止。

## 未通过的发布/外部验收门禁

- `tests/release_gate_v1_contract.php`：**NOT RUN / 待授权服务器执行**。脚本无条件生成 Ed25519 私钥；AGENTS.md 明确禁止在本机生成包括临时测试签名私钥。没有跳过鉴权、修改断言或声称 Release Gate PASS。
- 完整签名包、正式安装/Updater升级与回滚、发布一致性：NOT RUN。本轮源码/SQLite往返不能代替这些门禁。
- 真实 AI客服/Logo 安装环境端到端、付费模型、支付、移动真机、任意第三方主题：NOT VERIFIED。已有共享AI/会员测试通过不能代替这些验收。
- 生产部署/发布/合并均没有授权，也没有执行。

## 知识库同步状态

更新架构、API/SEO/主题/插件规范、退役说明、决策、版本及测试记录。沿用V1知识包，固定本候选源码提交，标明UNPUBLISHED；原release_evidence为历史发布证据。新增/退役候选事实仅ADMIN_DEVELOPER，源码复核记录保持NOT_RUN_THIS_TASK；运行证据在本报告单列。

本轮运行 `python3 scripts/product_knowledge.py --write` 与无参数校验。构建及无参数校验均 PASS：44 条事实、22 条公开事实、45 个固定来源文件；运行测试状态不混入源码复核。导入/索引重建/客服消费者应用/自动同步均未执行。
