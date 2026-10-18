# 品牌插件所需的 Core 最小公共契约

开发基线：1.3.0-alpha.3，依赖既有 alpha.1 图像服务。此文描述开发代码，未宣布 Stable 或生产部署。完整 Logo 插件独立打包，不进入 Core 发行载荷。

## 复用服务与身份

- 文字调用旧签名保持兼容；新增 `AiService::meteredRequest(AiRequest)` 是显式启用 Core 用量、配额与审计的文字调用。模型、凭据、认证、Timeout 仍由站点 Core 配置提供。文字账本保留既有价格字段语义；只有 token 用量可作为本次实际证据，不能把旧字段0当作真实供应商账单。
- `PluginAiImageService::importReference(bytes)` 在 Core 验证的 POST/CSRF/会员范围内，将 PNG/JPEG/WebP 旧图解码、归一为透明 PNG、存入私有媒体，返回可供 `image.edit` 使用的引用。限制8MiB、400万像素、每会员20次/UTC日、2次/分钟。它不调用模型、不收模型费用；失败导入仍占该次尝试配额。
- 不添加任意服务商通用图像能力。已验证适配器仍仅官方 OpenAI 端点的 gpt-image-1.5。插件不保存 API Key、不直接调用模型服务商。

## 原子插件文档

`PluginDataStore::document(type,key)`、`mutate(type,key,callback)`、`documents(type,limit)` 提供插件命名空间内可替换文档。新表 `cms_plugin_documents`；原 `put/all` 追加契约不变。原子回调在 SQLite BEGIN IMMEDIATE/MySQL事务与锁下执行，不得网络IO。异常、无效类型、非数组或2MiB上限会回滚。列表上限100条。

该契约供作品状态、幂等收据、积分账本与压缩资产缓存使用，普通插件无需原始PDO。卸载代码不删除文档、私有媒体或支付记录；清除数据须另行 Owner 确认。

## 扩展任务 Worker

```
php cli.php run-extension-jobs daiying.brand.design 10
php cli.php run-ai-image-jobs 10
```

首个命令启动当前启用插件，验证指定任务类型确由启用插件注册，再只处理该类型。Core 不写死 Logo 任务默认值、不运行未注册的其他任务。第二个命令继续处理图像任务。

插件标准矢量状态：preparing → queued → running → response_ready → completed。队列最多一次模型尝试；已落盘结构化响应可重建资产，不重调模型。请求状态不明或运行中断为 uncertain，不自动重放；queued/response_ready 可经已验证POST恢复。多条重复队列记录由插件原子claim保证只有一次模型调用。艺术/编辑任务沿用 Core 图像持久队列。

## 管理员应用站点品牌

`PluginContext::branding()->apply(logoPng, iconPng, name, idempotencyKey)` 需要 `site.brand.write`、Core验证的管理员POST+CSRF范围、当前启用插件版本、实际管理员及站点设置权限。会员和无验证后台范围一律拒绝。

只接受经过媒体验证的有界PNG，发布到公开 CMS 媒体，再在文件锁下备份配置，原子更新 site.name/logo_url/favicon_url。备份在 storage/backups/branding，0600；其余配置保留。幂等键内容冲突被拒绝，同键完成请求返回原收据。SVG/ICO不进入公开上传白名单。主题自定义覆盖品牌时仍需人工检查主题设置。

## 费用与测试边界

所有现金订单、可信回调、履约、全额退款撤权由现有 Core Payment 公共接口执行。插件积分是经营账本，不是第二套支付网关。积分充值复用 Core 订单；重复履约/扣费/退款幂等。未完成或失败作品不创建下载订单、不扣下载积分。服务商生成尝试费用与站长下载收入分开，未知账单不得显示为免费。

本机验证：Core相关13组回归与新增9项公共契约；插件50项原生安装/导出/收费/退款/恢复/隔离检查；6进程积分并发。真实 Provider 测试在服务器隔离库使用 Owner 指定站点既有配置，标准矢量/书法/编辑完成，120秒设置保留。Fixture支付不是实际商户结算。MySQL真实并发、支付沙箱签名回调/退款和Owner视觉批准仍单独记录；见插件中文交付报告。

## 公开站点品牌读取

`PluginContext::branding()->current()` 每次读取当前实例的站点配置，仅返回 `name`、`url`、`logo_url`、`favicon_url` 四个公开字段。它不返回数据库、密钥、邮件、支付或 AI 设置。前台无需获得品牌写权限或管理员身份；`apply()` 原有授权检查保持不变。插件必须转义名称并校验 URL，缺失名称使用中性文案，不能把发行方名称当作本站名称。多实例以 Core 提供的实例根目录隔离，应用品牌后的下一次读取立即反映配置更新。

插件文字请求应继承 Core 的服务商、模型、凭据、Timeout、token 上限与温度；图像请求继承 Core 图像模型及已验证能力，不把任意文字模型或兼容端点视为支持生图。经营站名称仅用于工作室身份和默认品牌；会员显式输入的设计品牌具有优先级，不能被经营站名覆盖。
