# Core 1.2.89 正式发布与公开验收

Owner 于2026-10-24批准按冻结正式签名包发布到GitHub和官方更新服务，并明确禁止升级生产站。本次仅发布Core，没有发布插件、主题或支付组件。

## 发布结果

- 正式版本：**1.2.89**，标签 `v1.2.89`，冻结提交 `3dcc700174abd1b9a7d2959a09881f247026de1c`；GitHub Latest已指向该版本，标签仍精确指向冻结提交。
- [GitHub Release](https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.89) 于2026-10-24T04:26:00Z公开，七个附件原样上传。
- [官方更新元数据](https://updates.daiyingcms.com/api/v1/products/daiying.cms/latest?channel=stable&current_version=1.2.88&php_version=8.3.0)已选择 `daiying.cms:1.2.89:stable`，于2026-10-24T04:25:38Z完成原生ReleaseManager事务发布。
- [完整安装包](https://github.com/daixingwei920/daiying-cms/releases/download/v1.2.89/daiying-cms-1.2.89-stable.zip) SHA256：`7831edfee8c8d3161aa8ca92f026019b99355ba764e6a98dfe002373cd7f052a`。
- [完整Core更新包](https://github.com/daixingwei920/daiying-cms/releases/download/v1.2.89/daiying-cms-core-update-1.2.89.zip) SHA256：`23ee08234d1726e100208ec4dddd68a031f8c1f6d1dfdcb019af9614e9cc9cc1`。

这两份ZIP与Owner验收的正式签名包逐字节相同，没有重建、重新打包或覆盖旧版本。官方目录仅按既有流程签署新版本注册表元数据，原包内签名及GitHub签名sidecar不变。公钥SHA256指纹保持 `8ee3baa72f432a925d18df9d9f03b2a02b4702d78a56fbc937b82c3ae4303f00`，私钥未导出。

## 公开实测

公开下载/版本一致性 **28项PASS**，公开签名与绑定检查 **19项PASS**：

- 不携带Authorization头，实际匿名获取GitHub公开Release/Latest和七个附件；HTTP200、文件大小、API digest及每份SHA256均与冻结文件一致。
- 实际匿名下载官方更新包，与GitHub更新包逐字节相同。下载包包含完整420个声明文件及55个必需迁移，ZIP清单、所有文件哈希与签名manifest一致。
- 两份GitHub官方签名sidecar、官方公开注册表签名及payload digest通过固定官方公钥验证；API版本/产品/渠道/兼容下限/文件列表等字段绑定到签名payload。
- GitHub与官方更新服务的实际下载包均通过原生 `UpdatePackageReader` 对update.json/signature.bin验签；公开安装包manifest绑定冻结提交及实际ZIP哈希。
- 使用全新的合成测试site_id，1.2.80/81/82/83/84/85/86/87/88均选择1.2.89及同一更新包哈希；1.2.79保持旧兼容1.2.81，1.2.89不重复提示更新；PHP8.2测试站不能选择1.2.89。
- 最低升级源、硬下限及迁移下限保持1.2.80，最低PHP8.3.0。

版本选择测试不是逐一实跑全部历史来源升级。已验收的正式preflight/build/verify、签名parity635项、SQLite原生1.2.88→1.2.89升级/自动回滚/重试25项及正式HTTP新装证据见[准备报告](CORE_1.2.89_PREPARATION_REPORT.md)。本轮不重复这些已PASS且包未变化的昂贵检查，也不再调用AI或真实支付。

公开验证脚本最初按tag查询GitHub草稿得到404；草稿列表/精确release ID正常，七个附件上传校验通过后才公开。没有重复创建Release、移动标签、重打包或重试生产发布。

## 生产保护、备份与回退边界

生产站发布前后均为 **Core1.2.88 / NORMAL / maintenance=false**，真实公开 `/health` 验证通过。配置、活动Core指针、AI设置、收款设置、支付/退款记录、已有AI任务/用量/插件文档、受检插件源文件与Cron摘要前后一致。现有客户授权逐行哈希不变，所有非Core产品记录保持相同，Core售价、授权及商业链接等字段保留。

发布前已对官方分发库进行只读一致性备份并验证integrity_check=ok，私有路径：`/root/daiying-publication189-20261024-fHeMWO/backup`。原生发布事务核对既有签名信任根、包哈希、manifest、兼容性及Web用户读取权限后提交；未修改分发服务源码或配置。

如需撤回，应另行批准后按审计记录撤销该新增release、调整Core current_version与GitHub Latest；不要覆盖此后新客户数据、移动旧标签或重建冻结包。本次没有执行回退。

**未部署或升级生产站，未变更收款设置、发布插件/主题、创建真实支付、调用AI或发起退款。**

## 文档与知识记录

更新当前AGENTS基线、文档索引及统一V1知识包，固定到真实 `v1.2.89` 和完整提交；原1.2.88证据保留为历史发布记录。源码事实仍保持SOURCE_REVIEWED/NOT_RUN_THIS_TASK，公开运行与发布证据独立记录。没有导入生产知识库、重建索引或应用到客服消费者。

[脱敏发布证据](CORE_1.2.89_PUBLICATION_EVIDENCE.json)记录实际检查、公开产物与生产保护摘要。完整原始证据及实际下载文件保留在本任务 `outputs/publication-1.2.89/`，数据库备份、日志、密钥和会话不进入Core提交或发行包。文档后续提交不改变正式标签、冻结运行提交和签名产物。
