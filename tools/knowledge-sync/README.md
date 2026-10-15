# 统一知识库隔离自动同步

沿用 `DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1`。本工具复用 Phase 7.1 的来源分类与 PHP 符号抽取、main 的产品知识校验器，以及客服 1.1.0 的真实 Repository/迁移/Retriever。原文件哈希见 `reuse-lock.json`。它不调用 Codex 或模型、不修改 Core、不连接生产和原 AI 笔记本索引，也不重建现有知识库。

## 当前能力

- GitHub `release.published` 文件事件入口：过滤非正式发布，核对仓库、tag、完整 commit 和组件版本；先持久化收件箱，来源离线或工作进程忙碌时保留事件。入口供可信本地执行器或既有发布流程调用，不是公网 HTTP webhook。
- 持久队列、互斥锁、重复事件和逻辑任务去重、有限指数退避、进程中断后恢复、人工重试、过期版本拒收。拒收和重试耗尽均留在状态报告。
- 发布 commit 与知识 commit 分开追踪；从 Git blob 抽取，不读取 dirty 工作树。首次建立来源清单，后续按 Git blob ID 复用未变条目，只重新读取变更文件，记录新增、更新、删除、改名与来源路径。
- 变化源码、API、迁移、插件/主题规范进入 INTERNAL 待复核清单；提交或路径名含 fix/security 均不能自动晋级。发布后的官方知识仍须版本和来源哈希一致，否则任务为 `needs_review`，保留原成功版本。
- 每日检查、每周问题记录与消费者实物一致性检查。发现消费者文件或本工具管理的插件知识行漂移时，记录问题并排队修复；发布和消费者各有成功游标。
- 同来源逻辑 ID 幂等更新；正文重复按 P0/P1/P2 保留较高优先记录；撤回时仅停用本工具管理的同站点、同来源知识，不清空历史表或无关知识。
- 原生客服 SQLite 隔离适配器，以及供文章优化工具复用的 USER-FACING 上下文文件和 `query` 入口。原 Core 优化器 UI 尚未接入，原插件的全部 FAQ/旧 chunks 路径也未改写。

## 创建全新隔离环境

要求 Python 3.10+、Git；原生插件测试还要求 PHP 8.3+、PDO SQLite、mbstring。`--root` 必须不存在；初始化器拒绝接管已有数据库。

```sh
python3 -B tools/knowledge-sync/init_isolated.py \
  --root /tmp/daiying-knowledge-isolated \
  --repository /path/to/daiying-cms \
  --knowledge-ref origin/main \
  --plugin-root /path/to/official.ai-customer-service
```

省略 `--plugin-root` 时两个消费者都为文件，不能把该模式的成功报告成客服数据库验收。插件文件必须与 `reuse-lock.json` 匹配；工具不下载插件、不安装到站点、不使用普通 ZIP 上传器绕过官方插件信任。

状态、快照、fixture 数据库、消费者输出均限制在新建隔离根内，且要求 `ISOLATED` 标记。`environment` 只能是 `isolated`，配置生产模式会失败。运行数据不得提交 Git。

## 发布触发

既有发布流程在正式发布成功后保存 GitHub 原生发布事件 JSON，调用以下入口。该步骤和模板尚未安装到任何生产发布服务器或 CI。

```sh
python3 -B tools/knowledge-sync/sync.py --config /tmp/daiying-knowledge-isolated/config.json \
  release --source cms --event-file /path/to/release-event.json
python3 -B tools/knowledge-sync/sync.py --config /tmp/daiying-knowledge-isolated/config.json work
```

最小事件形状：

```json
{
  "action": "published",
  "repository": {"full_name": "daixingwei920/daiying-cms"},
  "release": {
    "id": 123,
    "tag_name": "v1.2.83",
    "draft": false,
    "prerelease": false,
    "published_at": "2026-10-13T04:27:28Z"
  }
}
```

本地 Git 必须已包含该 tag/commit。事件先写入持久收件箱；`job=null` 表示仍在等待锁或来源恢复，不代表同步完成。`status` 中可查看收件箱状态。新提交而未发布的内容仅记录为待发布或待复核，不能凭定时器自动宣称正式发布。

教程或文章撤回、已复核知识提交等没有独立版本 tag 的变更，可由既有流程调用 `check-now` 后执行 `work`，立即检查已批准版本对应的最新知识提交。该入口不会将未发布的 Core/插件代码晋级为正式事实；变化路径仍记入问题清单。

## 定时检查与失败恢复

```sh
# 前台持续运行；隔离验证可加 --ticks 3 限定次数
python3 -B tools/knowledge-sync/sync.py --config /tmp/daiying-knowledge-isolated/config.json daemon --interval 30

# 单次检查与工作执行，供已有调度器调用
python3 -B tools/knowledge-sync/sync.py --config /tmp/daiying-knowledge-isolated/config.json tick
python3 -B tools/knowledge-sync/sync.py --config /tmp/daiying-knowledge-isolated/config.json check-now
python3 -B tools/knowledge-sync/sync.py --config /tmp/daiying-knowledge-isolated/config.json work
python3 -B tools/knowledge-sync/sync.py --config /tmp/daiying-knowledge-isolated/config.json status
```

默认每 86400 秒执行日检、604800 秒执行周检；持久游标避免重启后重复执行同一周期。`templates/` 提供未安装的 systemd user service/timer，需部署隔离节点时再审阅路径和环境。本轮未运行 systemctl、未创建系统 cron 或 Codex 定时任务。

默认最多 3 次尝试，退避为 30、60 秒。达到上限后保持失败，不会无限重试。故障修复后可明确执行：

```sh
# 原任务快照不变；只重置失败任务的尝试预算，保留审计记录
python3 -B tools/knowledge-sync/sync.py --config /tmp/daiying-knowledge-isolated/config.json retry --job JOB_ID
# 来源检查、发布收件箱分别恢复
python3 -B tools/knowledge-sync/sync.py --config /tmp/daiying-knowledge-isolated/config.json retry-checks
python3 -B tools/knowledge-sync/sync.py --config /tmp/daiying-knowledge-isolated/config.json retry-events
```

`needs_review` 不能人工重试跳过证据校验，需提交修正后的知识并重新触发。每个消费者独立原子写入；多个数据库/文件不存在跨消费者全局事务。一个成功另一个失败时，报告保留部分应用状态，全局成功游标不前进，重试会幂等执行，不能报告全部消费者已同步。

## 来源注册与文章格式

配置 `sources` 可注册独立的插件、主题、官方教程仓库；每项需唯一 `id`、Git 仓库路径、`github_repository`、`initial_tag`、`version_file`、`knowledge_ref`、`watch_ref` 和明确 `include` 范围。组件 `version_file` 可为 `plugin.json` / `theme.json`，Core 则为 `config/app.example.php`。

有正式产品事实包时设置 `package_path`；只有原始源码/文档时省略它，工具仅更新 INTERNAL 来源清单，不自动产生客服答案。官方教程和版本记录发生变化会被清单发现，相关事实仍要更新 V1 包并通过来源验证。

站点文章经授权从当前站点导出、审核分类并提交 Git 后，可用 `articles_path` 纳管。工具不会读取生产 Core 表。一个条目的最小格式如下：

```json
{"id":"75","title":"安装教程","content":"已审核的正文","status":"published","grade":"A","review_status":"reviewed","visibility":"USER-FACING","user_facing":true,"url":"https://example.invalid/articles/install"}
```

`reviewed` 映射 P1；明确为 `ordinary` 的普通已发布文章映射 P2；仅 A/B 可进入参考库。Draft/Pending/C/D、INTERNAL、未知审核或分类状态默认排除。字段必须来自实际审核/分类证据，不能把自动 A 级当成人工审核。原文作为待优化输入与作为参考知识分开。

## 共享查询与消费者边界

```sh
python3 -B tools/knowledge-sync/sync.py --config /tmp/daiying-knowledge-isolated/config.json \
  query --source cms --version 1.2.83 'SQLite 怎么安装'
```

只查询全部消费者确认成功的版本；要求调用方传入实际适用版本。按 P0/P1/P2 排序，返回来源和版本；无匹配返回 `grounded=false`，不调用模型补全。此接口及文件可供独立文章优化工具复用，未替代或改动原 Core 优化器。

客服适配器复用原 Repository，并以 `knowledge_sync` 来源类型和站点 ID 隔离管理记录，保留版本、来源 commit、知识 commit、证据路径和哈希。已安装插件的原 Retriever 可读到结果；其旧 FAQ 快捷返回、500 条候选上限及 soft trust 加权仍存在。本工具对同步数据和共享 query 强制执行策略，不声称旧客服全部入口已实现严格 P0/P1/P2。模型配置仍由 Core AiService/AiGateway 所有；本轮不更改 gpt-4.1-mini 或发起付费调用。

## 验证与状态报告

```sh
KNOWLEDGE_PLUGIN_ROOT=/path/to/locked/official.ai-customer-service \
  python3 -B tools/knowledge-sync/tests/test_sync.py
python3 -B scripts/product_knowledge.py
```

不提供插件路径会明确 SKIP 原生插件测试；不能将该结果作为真实插件通过证据。测试全部使用临时 Git 仓库、真实 SQLite 和明确的隔离数据，不安装或访问生产。

`state/status.json` 与 `status` 命令记录发布收件箱、队列状态、尝试次数、下次重试、来源/知识 commit、增改删/跳过数量、变化文件、消费者成功游标、漂移与周检问题。快照包含哈希并在使用前检查；状态报告不包含原始源码正文或模型密钥。

本轮验收结果与知识库同步状态见 [自动同步隔离验收记录](../../docs/knowledge/2026-10-14-auto-sync-report.md)。
