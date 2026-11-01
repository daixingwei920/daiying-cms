# V1 候选知识来源协调（2026-10-31）

范围：本次管理界面候选与已合并的历史优化器退役事实。未修改线上、已发布包/标签/签名，未导入知识、重建索引或部署消费者。

原事实来源是 `2b4fe067be2312ee7e0574c347c8a7e65d5ac11f`；严格工作区校验在 Application.php 报漂移。逐项对照实际引用文件，仅四个路径变化：AdminController.php、Application.php、View.php，以及已经删除的 HistoricalArticleSeoBackfillService.php。除历史优化器退役记录外，已有事实描述不变，重新确认现有锚点；新增 UI 候选仅供管理员/开发者审查。

按照既有架构的“不可变候选提交”机制，将 V1 来源固定到 `746ebaab6eb08a9242bfd707a9ff269ca5a85cc1`。source_tag 使用完整 Git commit 引用，不创建或移动版本标签。来源版本仍为 1.2.90，但 release_status 与 candidate_evidence 明确 UNPUBLISHED，不表示另发了同名版本。release_evidence 字节语义保持相同；原 candidate_evidence 和 development_candidate 分别保留为 published_baseline_evidence / published_baseline_development_evidence。

历史优化器变为 removed_candidate / ADMIN_DEVELOPER，停止导出给客服。新 UI 记录为 implemented_candidate / ADMIN_DEVELOPER。构建后共48条事实、21条公开导出、57个固定来源文件；所有来源复核仍标记 SOURCE_REVIEWED / NOT_RUN_THIS_TASK，实际运行测试另附 PR 证据，避免将资料复核当作功能 PASS。

工具仅修复候选横幅中写死旧 Issue #9 报告路径的问题，改为使用候选 runtime_report。validate 的默认工作区严格校验、哈希、路径、提交引用、行号、锚点及公开资格检查保持原样。

## 已执行

- `python3 scripts/product_knowledge.py --write`：生成 Markdown 与 USER-FACING 投影。
- `python3 scripts/product_knowledge.py`：严格校验通过。
- `python3 tests/product_knowledge_provenance.py`：9项通过；使用仅本地共享对象的临时 clone，验证真实 Git 来源及来源漂移、哈希错误、提交引用错误、不安全路径、内部误导出、退役记录排除、候选警告和已发布证据保留。

知识包构建/检查：PASS。知识导入、同步服务运行、索引和消费者应用：NOT_RUN；无生产状态变化。服务器 Gate 与原生 HTTP 的准确提交证据单独附 PR，正式 preflight 的未合并阻断不能豁免。
