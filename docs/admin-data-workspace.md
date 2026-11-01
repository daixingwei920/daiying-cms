# 审核与更新数据界面（隔离候选）

Owner 于 2026-10-31 要求将审核、更新数据的长列表改为清晰的后台 UI。本候选基于合并 PR76 后的 `b8edbdce6c9008ddb50facfebb6c2617cde47b9c`，分支 `ui/admin-review-update-panels`。待审 PR 准备中；未合并、未发布或部署，不改变 Core 版本。

## 行为与边界

- 审核结果：顶部显示最终状态、静态扫描、AI 审核与风险等级。左侧分类切换问题、提交信息、原始报告；长问题逐条展开，完整说明与修改建议保留。
- 系统更新：检查、验证、执行和历史记录分区；检查结果、准备完成、验证摘要、执行结果使用统一摘要/详情布局。原始验证计划与执行 JSON 默认折叠、区域内滚动。
- 扩展市场详情的原始 JSON 同样默认折叠。
- 窄屏折叠分类菜单；断点变化时将菜单内的键盘焦点移到可见的分类开关，避免焦点落入隐藏内容；方向键/Home/End 可切换分类；使用 `hidden` 保留 DOM 与未提交输入，不重建表单。无 JS 时所有分类内容与表单均可读取。
- 未修改 UpdateService、签名验证器、包读取器、完整性保护、审核客户端、鉴权、CSRF 或数据库迁移。审核状态同步与审计沿用原路径。没有新的网络请求或后台任务。
- 静态扫描 PASS 不代表最终审核通过；选择/填入包路径不表示验证通过；演练不表示更新已执行。
- 新增纯展示类 `AdminDataView`，仅用于当前页面；公共插件/主题接口与存储契约不变。资产缓存版本同步更新。完整性清单通过既有 ManifestBuilder 生成。

## 验证

- `php tests/admin_data_workspace.php`：16 项通过，包含长文本、全部问题保留、HTML 转义、空/失败报告、状态区分、原始数据折叠、子目录链接、签名元数据展示。
- `python3 tests/admin_data_workspace_http.py --output /path/to/native-http.json`：一次性 SQLite、原生管理员登录与真实 HTTP handler；审核/更新服务仅为 loopback mock。验证原生报告渲染、完整性检查、鉴权/CSRF 拦截、兼容性阻止准备操作、兼容更新下载准备和哈希/字节保留、无签名非法包拒绝、缺失包拒绝、执行二次确认、失败不切换 Core 指针。支持 --commit 从不可变 git archive 运行并记录运行时哈希。外部调用为 0。
- 相关回归：`admin_layout_scroll_contract.php`、`market_review_submission_payment_provider.php`、`updater_runtime_boundary.php`；PHP/JS 语法与 `git diff --check`。
- 浏览器：1280px 默认桌面和 390×844 窄屏；单分类显示，长 JSON 高度 440px 且可滚动，无页面横向溢出；切换/缩放保留输入，键盘切换保留焦点，窄屏菜单折叠。属于模拟视口，未做真实 iPhone 验收。
- 成功验证/执行结果预览使用合成数据调用同一展示方法，**不代表真实签名安装或更新成功**。本次未运行服务器 Release Gate / 签名包正向安装，不在本机生成或导出签名密钥。

## 知识库同步状态

更新本候选说明与 docs 索引；统一 V1 事实源仍固定已发布 1.2.90，本候选没有晋级为已发布事实。未构建、导入、索引或部署到消费者。

`python3 scripts/product_knowledge.py` 当前失败：`source drift: system/core/Bootstrap/Application.php`。在原有干净历史优化器工作区重复检查得到同一错误；本候选没有修改该文件。这是既有来源漂移，保持失败状态，留待下一次获授权的事实/版本同步处理，不通过重写已发布事实掩盖。

外部交接证据位于当前任务 `outputs/admin-data-ui/`，不进入 Core 包。正式发布前仍需准确提交的服务器 Release Gate 和发布授权。
