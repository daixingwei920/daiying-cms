# Core 1.2.86 正式发布记录

Owner 于 2026-10-18 批准发布；GitHub 与官方更新服务均已正式发布。

- 冻结提交：`d311446607124fff21d76f524a4a11968ad4cd58`；官方标签 `v1.2.86`；版本准备 PR #52。
- [GitHub 正式 Latest](https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.86)。
- 官方更新服务：`daiying.cms:1.2.86:stable`，100% rollout。
- 最低直接升级来源 1.2.80，PHP >= 8.3，无新增数据库迁移。

## 修复范围

包含另一个窗口的 PR #47 无迁移普通 API 市场插件启用修复，以及 PR #46 Passkey 敏感操作重认证（经 #51 集成）。保留可信 PHP、官方保留身份和真实迁移的原授权检查；保留密码认证方式。四个变更的运行时文件已逐一确认进入正式更新包。图像 AI 和 Logo 插件未混入。

## 产物

| 产物 | SHA-256 |
| --- | --- |
| daiying-cms-1.2.86-stable.zip | `954e18b12221bbe12f178b81258ca6eee23b3bbc1dc2607efd176ef1e56587fe` |
| daiying-cms-core-update-1.2.86.zip | `28e6f9f837b3ca5fa76e91fee0d5a00bdbd7c6482360acb518085599bdb0d127` |

安装包 561 文件，Core 更新载荷 407 文件。服务器使用既有正式密钥签名，工作站未读取或导出私钥；公钥指纹 `8ee3baa72f432a925d18df9d9f03b2a02b4702d78a56fbc937b82c3ae4303f00`。

## 实际验证

- 干净 main 的完整 preflight/build 通过；包含冻结迁移、通知、Plugin SDK、Release Gate V1、Release Phase 0 和源快照 parity。需要临时签名密钥的测试仅在授权服务器隔离目录运行。
- 正式签名产物与精确提交的最终 parity 613 项 PASS；安装包 sidecar、更新包、公开 registry 的固定公钥验签及哈希通过。
- SQLite 原生全新安装：GET /install 200、POST 302、版本 1.2.86、数据库 integrity_check=ok。
- 原生 1.2.85→1.2.86 升级通过；新进程 health=ok / 1.2.86，内容、备案设置及主题文件保留。
- 在独立 fixture 注入准备阶段文件障碍，回滚通过；新进程 health=ok / 1.2.85。故障注入产生预期文件写入警告；不将此测试描述为激活后中断恢复。
- 更新 API：来源 1.2.80、1.2.83、1.2.84、1.2.85 均选中 1.2.86；1.2.79 选中旧兼容版本 1.2.81；1.2.86 无更新。
- GitHub 五个附件 digest、两个 ZIP 实际下载及官方更新服务实际下载哈希一致。
- 写入前创建一致性 SQLite 备份，备份完整性通过；事务发布前验证公开包目录 0755、文件 0644，Web 用户可读。

## 知识库同步状态与边界

V1 JSON、Markdown、USER-FACING 导出、管理员教程、更新说明和 AGENTS 基线已同步，保持来源审查和运行测试分离。知识包生产导入、索引重建、消费者应用均未执行。

官网发布后 health 仍为 ok / NORMAL / 1.2.84，maintenance=false。本次未升级生产站、未修改收款设置、未执行退款。MySQL 升级和实体设备人脸/指纹未实测；合成 WebAuthn 签名成功及防重放测试复用未变化 Core 文件的 #51 隔离证据。

已发布 1.2.85 标签和原包保持不变；发布后文档提交不会修改 1.2.86 标签或已签名包。
