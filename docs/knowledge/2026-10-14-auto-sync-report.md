# 统一知识库自动同步隔离验收

日期：2026-10-14，按 Owner 所在 America/New_York 记录。开发基线为 main 的 `a2ef67ed5445fe248f308d826c0ca73e6f32d6a5`，正式产品事实基线仍为 Core 1.2.83 / `76d4d8ed4b4aa470146c0a0c582061b256d1348b`。

本轮落实独立同步工具和隔离插件适配器，没有改动 `system/`、`content/` 或 `config/`，没有部署生产、发布新 Core 或插件版本。统一名称与原有 27 条产品事实保留。

## 实现与复用

- 复用 Phase 7.1 的分类、插件/主题识别与 PHP 符号抽取；去掉按路径名或提交自动晋级 verified 的旧行为。原工具 SHA-256 和客服 1.1.0 文件锁定信息保存在 `tools/knowledge-sync/reuse-lock.json`。
- 复用 main 的 `scripts/product_knowledge.py` 进行事实版本和来源校验；补充不可变 Git 读取模式和明确的隔离实现状态。
- 独立执行器位于 `tools/knowledge-sync/sync.py`：发布事件持久收件箱、任务队列、快照、消费者确认、日检/周检、有限退避重试和 JSON 状态报告。
- 客服适配器复用既有 1.1.0 Repository；隔离数据库使用插件真实迁移建立，读取验证使用真实 Retriever。不复制 WIP 插件进 Core，不重写 Core 表或历史迁移。
- 新增全新隔离环境初始化器、未安装的 systemd user 模板和使用说明。新环境标记、数据库标记、站点 ID、插件代码哈希及输出范围均检查；生产模式被拒绝。

## 验收结果

本轮相关测试使用真实临时 Git 仓库、SQLite 数据库、插件源码及进程执行。时间推进负例使用可控时钟；另有真实前台定时进程测试，不能把两者混称为已安装系统定时器。

| 范围 | 隔离验证 |
| --- | --- |
| 发布触发 | GitHub published 事件文件入口执行；草稿、预发布、失败/未发布、外站和版本不符拒收 |
| 事件可靠性 | 工作锁忙碌和来源离线时事件仍留在收件箱，后续进程恢复处理；重复事件去重，事件 ID 冲突拒收 |
| 定时检查 | 前台 daemon 实际运行 3 次定时循环；日/周进度重启后持久；单个来源故障不使其他来源重复巡检 |
| 增量与撤回 | 同一内容去重；未变 Git blob 复用；文章变 Pending 自动撤回；源码、API、迁移、文件删除/改名进入变化清单 |
| 版本与审核 | 发布/知识完整 commit 分开记录；拒绝过期版本、错误查询版本及未复核事实；dirty 工作树不晋级 |
| 失败重试 | 消费者真实写入失败、有限指数退避、上限终止、人工重试、持久 running 状态回收；来源检查另有独立重试预算 |
| 部分成功 | 一个消费者成功、另一个失败时不推进全局成功游标，报告保留各消费者实际状态，恢复后幂等重试 |
| 消费者漂移 | 文件和原生插件知识行篡改可被巡检发现并修复；修复待完成时共享 query 拒绝使用该快照 |
| 受众与检索 | P0/P1/P2 共享查询排序、无匹配拒答；Draft/Pending/C/D、未知状态和 INTERNAL 不进入同步知识 |
| 插件原生集成 | 真实 Repository 写入、Retriever 读取、重复导入不增行、跨站不泄露、来源撤回与事务失败回滚 |
| 不可变性 | 来源和消费者配置随任务固定；快照哈希校验；新增消费者可得到独立同步任务 |

最终执行命令：

```sh
KNOWLEDGE_PLUGIN_ROOT=/path/to/locked/official.ai-customer-service \
  python3 -B tools/knowledge-sync/tests/test_sync.py
python3 -B scripts/product_knowledge.py
php -l tools/knowledge-sync/customer_service.php
php -l tools/knowledge-sync/tests/plugin_fixture.php
git diff --check
```

本轮 17 项隔离回归全部通过（31.639 秒），插件测试已实际执行，无 SKIP。产品事实本身的安装、恢复、真实支付和模型回答测试仍是 NOT_RUN_THIS_TASK，不能因同步测试通过而晋级。

## main 知识包完整隔离实跑

使用 main 上已有的 27 条 V1 知识和公开 v1.2.83 Release 元数据，回放发布入口；没有发布真实版本或向生产发事件。

- 发布源码 commit：`76d4d8ed4b4aa470146c0a0c582061b256d1348b`。
- 知识 commit：`a2ef67ed5445fe248f308d826c0ca73e6f32d6a5`。
- 来源清单：506 个范围内 Git 文件；来源哈希及 PHP 符号仅留在隔离 INTERNAL 清单，不注入客服上下文。
- 隔离原生客服数据库得到 22 条启用记录，真实 Retriever 查询 SQLite 返回 2 项匹配。
- 同一任务的文章优化上下文文件完成写入，共享 query 返回 8 项匹配；两个消费者均确认成功，任务为 succeeded。
- 公开 Release 仅作事件元数据回放；没有接入线上发布 workflow。前台定时验证结束后进程退出，未安装服务或系统 timer。

回归输出 SHA-256：`004e5c49062f5a7a6d77623ea9f1f15754357742662c8ff036453e3336cd7f0f`。main 隔离验收摘要 SHA-256：`c40de6a53f32841ae3d1097b499a08b735e45dae36d5b13ada0f73d3ab032e46`。

本地运行证据保存在本任务的 `outputs/knowledge-sync-main-isolated/`：`acceptance-summary.json`、`state/status.json`、发布事件、带哈希快照、消费者文件和隔离数据库。数据库/队列未提交 Git；可使用 README 的初始化器与命令重新生成。

## 当前限制

1. `IMPLEMENTED_ISOLATED` 只表示本轮工具、入口和隔离流程实现及验证；生产部署为 NO。原 AI 笔记本索引、正式发布服务器、GitHub 定时 workflow 和系统调度均未接入。
2. 不使用 Codex 自动编造事实，也不凭新 commit 判定功能完成。源码变化会生成 INTERNAL 待复核来源；与事实不一致时保持 needs_review，需更新和复核知识后再同步。
3. 原 Core 优化器 UI 仍为共享知识未接入；提供的上下文文件/query 可供获授权的独立工具复用，不宣称原 UI 已使用它。
4. 旧客服全部检索路径未重构。其 FAQ 快捷路径、最多 500 条候选和 soft trust 加权仍在；严格层级适用于本工具的数据准入与共享 query，不是旧插件整条回答链的验收。客服模型与 Core AiService/AiGateway 未改动，未发起付费调用。
5. 当前原生适配器只验证 SQLite 和锁定的客服 1.1.0 文件；MySQL、其他插件版本与实际站点导入需单独适配验收。
6. 未恢复原始 332 条问答，未用另一批 1000 条替代。跨消费者没有全局事务，必须按每消费者成功状态评估部分应用。

## 知识库同步状态

- 已更新：V1 包修订 `2026-10-14.2` 及派生文件、架构状态、AGENTS.md 隔离边界、文档索引、操作说明和本验收记录。
- 未改动：27 条产品事实的描述与固定源码证据，原因是本轮新增的是独立同步能力而非 Core 产品功能；其运行测试状态保持原样。
- 已同步到隔离消费者：22 条 USER-FACING 知识进入原生客服 fixture，另一个消费者收到同版本上下文；有独立成功游标与运行状态证据。
- 未同步到生产：生产知识表、现有笔记本索引、真实客服和原优化器 UI 均保持原状态，原因是本轮明确禁止生产部署及 Core 改动。
- 遗留：生产接入授权、原 Core 扩展点决策、旧客服检索策略、非 SQLite 适配与原始 QA 恢复。下一次每周一致性检查仍应不晚于 2026-10-21；未实装调度前按 AGENTS.md 由开发会话补查或报告逾期。
