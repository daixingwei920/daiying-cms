# 2026-10-17 维护候选知识一致性复核

来源为 `candidate/core-1.2.85-code`，完整 commit
`0e341cf27c2cc602df07383142fdddc63ff2b53a`。本次仍沿用统一 V1 JSON 事实源，
候选状态为 CANDIDATE_NOT_PUBLISHED，不把版本号等同于正式发布。

与此前 1.2.83 来源相比，知识证据涉及的变化文件为 CHANGELOG、配置示例、
Application 和 ContentFrontController；复核其版本、首页及内容页的全站备案
上下文变化后，刷新证据哈希和行号。其余已引用文件内容未变。

新增 `site.icp_registration` 和 `ai.gpt5_parameter_compatibility` 两条候选事实，
以 ADMIN_DEVELOPER 记录，暂不纳入客服公开事实；保留已经发布的固定链接书名号
知识，并在技术债台账纠正“尚未实现”的旧判断。生产客服模型和配置未改变。

`python3 scripts/product_knowledge.py --write` 与无参数检查：PASS，29 条事实、
22 条公开事实、31 个固定来源文件。此结果只证明来源/派生一致性，功能测试与
浏览器验收见 [维护候选报告](../release/1.2.85-maintenance.md)。

知识库同步状态：JSON、Markdown、公开导出与本次相关教程已更新。未导入生产
知识表，未重建索引，未应用到客服或 Core 优化器，未部署自动同步或定时任务。
历史自动同步 DESIGN_ONLY 和 Core 优化器共享知识 NOT_IMPLEMENTED 保持原状。
