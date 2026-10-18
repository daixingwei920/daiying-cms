# 官方市场 API 插件启用兼容修复候选

状态：隔离 Core 分支，未合并、未签名、未部署。基线为官方主线的 1.2.85 候选提交 `d578a05dae9742d177167cc4665482cc80cc9ea7`；已发布基线仍为 1.2.84。

2026-10-18 对目标站只读核对：`daiying_global_city` 0.1.1 的安装记录为 `Installed / api / official_market / published`；插件清单声明 `migrations: []`。启用日志报 `Trusted market plugin migrations require an active official trust grant.`。Core 对所有已发布市场插件执行启用前可信迁移授权检查，导致没有迁移的普通 API 插件也被拦截。

本候选仅让普通、非官方保留 ID、无可信表前缀且无迁移的 API 插件跳过启用前迁移回填检查。`trusted_php`、官方保留 ID 及有迁移的可信插件继续走原检查。测试覆盖复现前失败、修复后启用，以及上述可信边界仍拒绝无授权启用；真实目标站启用、完整签名 Release Gate 与正式发布尚未验收。

2026-10-18 在官方更新服务器的独立临时目录，以 `d578a05` 完整主线快照叠加同哈希的三份候选文件，在 PHP 8.5.9 下运行：`market_api_plugin_enable.php`、`plugin_enable_runtime_version.php` 和 `release_gate_v1_contract.php` 均通过。最后一项仅是 Release Gate V1 合约回归，不等于正式版本的完整快照校验、签名或发布。临时目录及测试包已清理，未读取正式签名密钥、未修改生产服务。

统一 V1 产品知识包不记录这一安装器内部判断，当前没有对应事实；本记录作为维护候选的内部知识说明。未导入生产知识表、未重建索引、未应用到客服或 Core 优化器。
