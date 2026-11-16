# 2026-11-15 AI 代运营 V2 Core 候选知识核对

范围：隔离分支 `feat/v2-capability-provenance`，Core 1.2.99 候选；正式发布基线仍是 1.2.98。本周针对本次接口变更核对了源码、SDK 参考、事件与能力登记、版本记录和知识包来源。没有生产变更。

- 已同步 `DAIYING_PLUGIN_API_REFERENCE_V1.md`、`DAIYING_EVENT_REGISTRY_V1.md`、`DAIYING_CAPABILITY_REGISTRY_V1.md` 和 `docs/plugin-sdk/README.md`：`ai.tool` 工具归属校验、来源由 Core 标记的同步唤醒事件、事件 ID 约束及未发布状态。`CHANGELOG.md` 已记录候选版本。
- 初次核对时统一 `DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1.json` 及其 Markdown、USER-FACING 导出仍钉在已发布 1.2.98 标签和提交。候选接口属于开发者资料，不应进入已发布的普通客服知识。初次执行 `python3 scripts/product_knowledge.py` 返回 `FAIL: source drift: config/app.example.php`：候选配置版本与钉住的已发布来源不同，校验按设计拒绝混用。经 Owner 批准正式 Gate 准备后，需将候选知识来源更新到精确候选提交，保留已发布 1.2.98 release_evidence，并重新生成派生文件；不能把候选包当作已发布或导入客服。
- 本次 SDK 文档和隔离报告是候选知识，不代表正式构建、导入、索引或消费者应用；四者本轮均未执行。自动同步未启用。正式发布时须按精确主线来源重新核对版本、来源与派生导出，并运行知识包生成及检查。

遗留：正式 Core Release Gate、服务器签名、原生签名升级/回滚和插件市场审核仍分别待验；本记录不能替代它们。
