# 蜘蛛来源核验（Core1.2.102 未发布候选）

Owner 要求补充真实来源核验，只有有证据的新请求才能显示已验证，并保持前台流畅。2026-11-21 Owner 授权与侧栏修复一起发布，须先完成完整测试和正式发布门禁；此前暂停已经解除，当前仍未发布。此文更新此前“全部来源未验证”的统计约定，不改 robots、权限、访问控制或收录规则。

## 标记含义

- **已验证 · 官方 IP 段**：连接地址命中该蜘蛛对应的官方 HTTPS 地址库，库在24小时有效期内。
- **已验证 · 双向 DNS**：连接 IP 的 PTR 主机名命中严格域名后缀，再查询该主机名的 A/AAAA，必须包含原始 IP。只有 PTR、名称相似或 User-Agent 自称均不成立。
- **待后台核验**：新来源已排队，尚无结果。
- **未通过核验**：已完成的官方地址匹配/DNS 查询未满足条件。它不是恶意判定，不自动封禁。
- **暂无法核验**：缺少公网连接地址/加密密钥、超时、存储故障、任务过期或容量不足。
- **暂不支持来源核验**：此版本尚未配置该蜘蛛的可靠核验规则，不意味着运营方没有公开资料。
- **历史未验证**：旧记录缺少来源证据，不能追认。一个蜘蛛名称下混有多种结果时显示已验证次数/总次数及分类明细，不能整行变绿。

来源证据只证明连接来自相应网络，不证明 UA 用途、页面收录、AI 引用或 robots 遵守情况。Anthropic 的三种 UA 共用官方网络表，不能据此证明其具体用途。私有聚合文件的可信度依赖站点文件权限，不抵御已能改写站点文件的攻击者。

## 官方规则（2026-11-20 核对）

| UA 标记 | 官方地址库 | DNS 后缀 |
| --- | --- | --- |
| Googlebot | https://developers.google.com/static/crawling/ipranges/common-crawlers.json | googlebot.com |
| bingbot | https://www.bing.com/toolbox/bingbot.json | search.msn.com |
| OAI-SearchBot | https://openai.com/searchbot.json | 无 |
| GPTBot | https://openai.com/gptbot.json | 无 |
| ChatGPT-User | https://openai.com/chatgpt-user.json | 无 |
| ClaudeBot / Claude-SearchBot / Claude-User | https://claude.com/crawling/bots.json | 无 |
| Baiduspider | 无 | baidu.com / baidu.jp |
| PetalBot | 无 | aspiegel.com / petalsearch.com |

当前搜狗、360、神马、Bytespider、头条仅识别 UA，不臆造可信规则。上表不是跨厂商的通用白名单；不同 OpenAI UA 的地址库不能互相借用。无重定向、访客指定 URL、代理环境变量或任意远程数据源。

规则依据：[Google 验证说明](https://developers.google.com/crawling/docs/crawlers-fetchers/verify-google-requests)、[Bing 验证说明](https://blogs.bing.com/webmaster/2012/8/How-to-Verify-that-Bingbot-is-Bingbot/)、[OpenAI bots](https://developers.openai.com/api/docs/bots)、[Anthropic 官方说明](https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler)、[百度说明](https://ziyuan.baidu.com/college/articleinfo?id=1198)、[PetalBot 官方说明](https://webmaster.petalsearch.com/site/petalbot)。Petal 官方示例使用 petalsearch.com，说明同时提及 aspiegel.com；仍须正向回查。

## 运行与性能边界

使用现有每分钟 Cron，以站点操作系统用户执行 `php /站点路径/cli.php run-scheduled-tasks`。CLI 注册 `core.seo.crawlers.verify`，间隔60秒；已有暂停状态保留。首次注册下一分钟到期。也可运行 `php /站点路径/cli.php seo:crawlers:verify` 单独处理一轮。本功能不配置服务器 Cron，也不启动常驻进程。

HTTP 采集只读取新鲜本地缓存/写入小型队列，不做 DNS、HTTPS、进程启动或新的数据库查询；普通访客不进入核验器。队列文件使用非阻塞锁，后台网络期间不持有状态锁。每轮最多4个来源、2次官方库下载，网络预算8秒；下载连接750ms/总计2秒，DNS 子进程1.5秒后终止。轮次额度不足保持待核验，超时来源轮转，不能饿死后续来源。

需要 PHP cURL（HTTPS 库）、OpenSSL（加密）和可用的 proc_open/dns_get_record（DNS 子进程）。缺少依赖或网络失败不降低核验标准。官方库最多6个、每库2,048个 CIDR、响应256KiB，硬有效期24小时，待检查时超过1小时尝试刷新、失败回退5分钟；刷新失败不续期。DNS 阳性缓存1小时、阴性5分钟。HTTP 中即使尚未运行后台也不能凭空显示成功。

只采信规范化公网 `REMOTE_ADDR`，支持 IPv4、IPv6 和映射 IPv4。访客的 X-Forwarded-For、CF-Connecting-IP 等不参与认证。代理/CDN 部署须由可信服务器配置先正确恢复真实连接 IP；本任务不修改生产代理配置。若仍得到私有代理地址，保持暂无法核验。

## 隐私、容量与兼容

聚合日志仍不保存原始 UA、IP、查询、凭据、正文或具体文章 URL，新增来源结果、证明类型/规则 ID、检查时间和随机待处理批次 ID。新、旧来源结果保持分离。已有数据不回填、不迁移、不删除。

后台需要暂存待查 IP：用现有站点 encryption_key 经用途隔离派生密钥，以 AES-256-GCM 加密；认证绑定 HMAC 来源 ID、UA 和随机批次。IP 不进入 argv、shell、任务 payload、UI 或完成缓存。完成后删除密文，仅留短期 HMAC 结果；DNS 主机名也不保存。私有状态文件 `storage/cache/seo-crawler-sources.json` 为0600，最多512KiB/256来源/512完成批次，待处理密文最长有效1小时。有效期不是无人访问时的物理删除保证：后台、后续采集或 `seo:crawlers:prune` 才执行删除；关闭采集后运行后台会清除待查 IP。

保留既有2,000行/512KiB有界聚合、1..30 UTC日保留和非阻塞丢样语义。来源容量满、存储锁冲突/损坏不会阻断网站，不推算完整流量或采集率。不开新表、不改已发布迁移，不改主题/插件 API。旧 Core 回退会忽略额外来源字段；核验缓存不参与授权。

## 测试边界

`php tests/crawler_source_verification.php` 覆盖纯规则、伪造、加密、容量、锁、超时轮转、旧数据和调度契约；`python3 -B tests/crawler_source_http.py --output /临时目录/report.json` 在临时完整站点测试真实 HTTP、CLI 和分钟调度流程。后者来源、地址库和 DNS 展示数据是合成数据，只有回环网络，不等同生产蜘蛛验收。

官方网络可达性与公开 DNS 示例另行记录，禁止在自动回归中依赖外网稳定性。正式签名、Release Gate 和发布必须先通过独立验收；本次发布授权不包含生产站点升级。
