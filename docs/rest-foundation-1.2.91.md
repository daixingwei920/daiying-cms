# Core 1.2.91 基础接口对照与补齐

本轮对照官方资料：

| 平台 | 基础能力 | 戴影核对及本轮处理 |
|---|---|---|
| [WordPress REST](https://developer.wordpress.org/rest-api/reference/) | 类型/媒体等资源与能力发现 | 已有内容、分类、标签、评论/用户/设置、SDK和支付/队列等基础服务；补充 content-types 与资源方法/鉴权说明 |
| [Strapi 文件上传](https://docs.strapi.io/cms/api/rest/upload) | multipart 上传与真实分页 | 补齐原501媒体上传、真实第二页和准确has_more；限制每页50 |
| [Drupal JSON:API 安全](https://www.drupal.org/docs/core-modules-and-themes/core-modules/jsonapi-module/security-considerations) | 复用实体/字段权限和验证 | 新媒体写入复用Core身份、CSRF、media.manage、原生上传校验；不自动公开插件私有结构 |
| [Directus Files](https://directus.com/docs/api/files) | 文件集合、单项读取和元数据更新 | 增加Active媒体单项读取及title/description/alt_text的PATCH，保留省略字段 |

这是基础能力差距的定向补齐，不是完整替代这些产品；原有SDK已经提供内容、会员、AI、支付、队列、定时任务、Webhooks、通知、缓存和扩展注册，不重复建设。细粒度第三方令牌发行、GraphQL、大批量任务、任意字段查询、远程URL导入等均不在本轮范围。

新增与兼容规则见 [REST API V1](../REST_API_V1.md)。基础接口不默认开放写权限；没有新增普通会员写入口、数据库迁移或支付业务特殊分支。既有Core、主题和插件接口保留。

验收入口：`python3 tests/rest_media_v1_http.py --commit <准确提交> --output /private/evidence.json`、`php tests/rest_api_v1.php`、`php tests/content_patch_release_regression.php`。原生HTTP仅使用临时SQLite和合成数据；不调用外部服务。正式签名、安装/升级/回滚和市场分发另行记录。
