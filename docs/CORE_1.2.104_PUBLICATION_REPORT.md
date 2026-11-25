# Core 1.2.104 正式发布

[GitHub Release](https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.104) 和官方稳定渠道均已发布。冻结标签 v1.2.104 指向合并提交 `c99d3e9746d2381594f9f3b4276d1e460274d8b7`。

Core 增加单任务调度入口。独立 worker 可以分别领取发布和写作任务，并沿用启用状态、到期时间和数据库任务锁检查。默认调度行为和数据库结构保持。支持 Core 1.2.80 起升级，最低 PHP 8.3。

完整隔离回归 264 个步骤通过，覆盖 PHP 8.3、8.5、SQLite 和 MySQL。签名包一致性 647 项通过。两套 PHP 各 71 项从 1.2.103 原生升级、回滚和重试检查及 8 项签名安装环境的单任务调度检查通过。匿名发布和下载验证 16 项通过。

- 正式安装包 SHA-256 `374a626e0dcc0da8f934ab26bdb1004c07311ad6b2f9f4e474e0455cad5d3dd3`
- 原生更新包 SHA-256 `908ffc2c81e4886bf51da2eae68a72063aec7e51168b3db2256df5f6ae5f608c`

本轮只发布 Core。站点升级、独立 worker 切换及现场排期验收另行执行。
