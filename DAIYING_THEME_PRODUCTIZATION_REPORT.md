# Daiying 小说 / 影视主题产品化报告

日期：2026-09-03

## 范围

- 小说主题：`content/themes/daiying_novel`，版本 `1.0.0`
- 影视主题：`content/themes/daiying-video`，版本 `1.0.0`
- 影视采集插件：`content/plugins/official.video-collector`，版本 `0.2.1`
- 小说采集插件保持上一轮完成状态：`official.novel-collector` `0.4.4`

本次未修改 Stripe、PayPal、Cloudreve 插件和支付 URL 安全策略。

## 关键修复

- 将小说主题从本地开发形态改为市场正式主题，依赖从 `local.novel-collector` 修正为 `official.novel-collector`。
- 将影视主题从本地开发形态改为市场正式主题，依赖 `official.video-collector`。
- 新增小说主题 URL helper，集中生成小说详情、章节、搜索、书架、TXT 下载入口，避免模板里散落旧 `/novel/{slug}` 路由。
- 新增影视主题 URL helper，集中生成影视详情、播放、搜索、分类入口，避免模板里散落旧 `/video/{slug}` 和分类硬编码路由。
- 小说主题补齐首页、详情页、阅读器产品功能：
  - 推荐、更新、新书、完本、排行等首页分区。
  - 最新更新使用紧凑表格展示。
  - 详情页支持开始/继续阅读、书架、TXT 下载、最近 100 章、目录分页。
  - 阅读器支持白色、护眼、夜间主题，字号调整，全屏，阅读进度保存和恢复。
  - 匿名书架使用 `localStorage`。
- 影视主题补齐首页、短剧页、详情页、播放器产品功能：
  - 首页展示热门、热播、电影、电视剧、短剧、动漫、综艺、最近更新。
  - 影视卡片展示海报、标题、年份、类型、状态/更新进度。
  - `short-drama.php` 改为独立页面，不再 `require home.php`。
  - 详情页选集按 80 集分页，避免长剧集挤满页面。
  - 播放器优先健康线路，支持 HLS/MP4/安全 embed，线路切换和一次自动 failover。
- 影视采集插件增加 `/videos/search` 前台路由，并给公开列表增加 `type` 和 `q` 过滤，供影视主题搜索和分类页闭环使用。

## 测试

- `find content/themes/daiying_novel content/themes/daiying-video -name '*.php' -print0 | xargs -0 -n1 php -l`
- `php -l content/plugins/official.video-collector/plugin.php`
- `php -l content/plugins/official.video-collector/src/VideoSystem.php`
- `php tests/video_collector_smart_mode.php`
- `php tests/novel_collector_frontend_contract.php`
- `php tests/theme_productization_contract.php`
- `git diff --check`

结果：全部 PASS。

## 旧标识和旧域名检查

- 未发现主题或影视插件代码残留 `local.novel-collector`。
- 未发现主题或影视插件代码残留旧式 `/novel/`、`/video/`、`/movie/`、`/tv/`、`/short-drama/`、`/anime/`、`/variety/` 硬编码路由。
- 未发现本次范围内残留 `www.daxingwei.cn` 或 `www.daixingwei.cn`。

## 市场包

- `/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-plugins-market/outputs/daiying_novel-theme-1.0.0-product.zip`
  - SHA256: `a9cebbe0946439853fc6b96444010604639d6aae148db992e113ec9263dfbb8a`
- `/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-plugins-market/outputs/daiying-video-theme-1.0.0-product.zip`
  - SHA256: `2f36725f852ca142ebe0a39a1a1029f4c7215bb8bf640e447f3cf8120414a930`
- `/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-plugins-market/outputs/official-video-collector-0.2.1-smart-mode.zip`
  - SHA256: `4d9a39f1d64bf7e7fe3701f9ac26d42c5bb7e1c70e565a1575647f5ab902b24d`

包内 manifest 校验：全部 PASS。
