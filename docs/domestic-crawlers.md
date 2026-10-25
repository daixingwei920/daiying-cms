# 国内蜘蛛识别与统计界面（未发布候选）

Owner于2026-10-24要求“补齐国内蜘蛛识别和统计界面”。基于已发布Core1.2.89的冻结main `3dcc700174abd1b9a7d2959a09881f247026de1c`，分支 `feat/domestic-crawler-statistics`。本轮仅隔离开发、测试和提交审阅，不合并、签名发布或部署生产；配置版本仍为基线1.2.89，不代表本候选已经发布。

## 使用

后台SEO分组新增“蜘蛛记录与统计”，原 `/admin/seo/crawlers` 路由和站点设置入口保留。先在站点设置勾选“记录蜘蛛访问聚合统计”；新增规则只对启用后的新请求生效，旧日志中没有采集的数据不会补造。

页面可按今天/最近7天/最近30天、国内/其他平台标记、蜘蛛声明和HTTP状态筛选。汇总、UTC按日趋势、蜘蛛排名与明细使用同一筛选范围；请求数为hits合计，聚合行数、蜘蛛种类和独立访客不是同一指标。明细每页50行，翻页保留筛选，页码越界归到最后一页；切换筛选表单重置页码。

配置只保留7天时，即使选择30天也只显示现存7天。采集关闭时显示关闭提示和保留数据，空筛选显示操作提示，不把空表解释为“未抓取”或“已收录”。界面有未验证来源说明，不提供IP/原始UA/查询/完整文章URL。

## 有限UA词表

保留原9类canonical名称，新增6类，共15类。国内平台标记共7类：

| 平台标记 | canonical agent | 兼容别名 |
| --- | --- | --- |
| 百度 | Baiduspider | 原名、-render、-image、-video、-news |
| 搜狗 | Sogou web spider | web/head/inst/Orion/Pic/Test的空格或连字符形式，New-Sogou-Spider |
| 360搜索 | 360Spider | 原名、-Image、-Video |
| 神马 | YisouSpider | 忽略大小写 |
| 字节标记 | Bytespider | 用途未声明，不推断是搜索还是训练 |
| 头条标记 | ToutiaoSpider | 兼容公开登记的UA标记 |
| Petal标记 | PetalBot | 兼容公开登记的UA标记 |

识别最多读取UA前512字节，忽略UA内http(s)文档链接，使用token边界，大小写不敏感；不接受普通浏览器名、附着前后缀或无依据的新机器人名字。别名合并到同一canonical agent，沿用旧JSON字段及聚合键；既有Baiduspider/GPTBot等记录仍可读取，无需迁移。

“国内平台”只是公开UA标记的分组，不是请求IP所在地，也不验证运营方身份。所有显示始终为“来源未验证”。旧AI搜索/训练robots策略的覆盖范围不扩大到这些新增标记；不新增robots策略、在线DNS/IP认证或防火墙规则，不声称可识别没有公开可靠UA的国内AI产品。

UA依据：搜狗[官方说明](https://zhanzhang.sogou.com/index.php/help/spider)、360[官方蜘蛛说明](https://www.so.com/help/spider_ip.html)与[变体说明](https://www.so.com/help/help_3_2.html)、神马[官方指南](https://zhanzhang.sm.cn/open/optimizaGuide)。兼容标记另参照上游项目的一手UA登记：[Matomo词表](https://github.com/matomo-org/device-detector/blob/b6616b08266969f65e519c4ef2c18cd3b00b3d7b/regexes/bots.yml)及[crawler-user-agents](https://github.com/monperrus/crawler-user-agents/blob/master/crawler-user-agents.json)。上游登记不等于运营方身份或用途认证；部分旧运营方文档已不能直接访问，未据此赋予“官方已验证”标签。

## 兼容性与实现边界

- `CrawlerRegistry`为内部有限词表，`CrawlerStatistics`为内部读取/呈现类，不增加公共SDK或机器写入接口。
- `CrawlerLog`只替换识别入口；2000聚合行/512KiB、1–30天、私有文件、非阻塞锁、过期清理和GET/HEAD公开路由限制不变。统计不等于完整访问日志，竞争可能丢样。
- 统计渲染跳过未知或畸形记录，防止无效hits膨胀统计或存储HTML进入页面；过滤非法参数返回400/private,no-store。管理员和settings.manage检查保留。
- 支持子目录的表单、设置、重置及分页URL；样式使用已有后台框架，更新静态资源版本避免旧缓存。新增Core文件纳入完整性manifest。
- 不增加SQL、数据库迁移、外部依赖、请求期间网络调用、AI调用或生产访问。新UI以合成105条聚合演示，不冒充真实国内蜘蛛访问。

## 验收记录

本机PHP8.5.8 / SQLite合成环境，实际结果：

| 检查 | 结果 |
| --- | --- |
| `php tests/crawler_statistics.php` | 92项PASS：UA别名/边界/长度、合并聚合、隐私、筛选交集、UTC趋势、保留期、畸形输入、分页、子目录、空状态 |
| `php tests/seo_geo.php` | 65项PASS：原有SEO/GEO与鉴权回归、新后台路由筛选/400私有响应 |
| `php tests/navigation_groups.php` | 40项PASS：导航及相关后台兼容 |
| `php tests/crawler_log_concurrency.php` | PASS：4进程尝试400次，JSON有效、有界且不超计数；本次保留109次，其余竞争丢样 |
| 隔离HTTP /articles/public，7种国内UA模拟请求 | 全部200，聚合hits增加7，查询秘密未写入日志 |
| 原生桌面浏览器 | 合成管理员登录→SEO新入口→国内筛选→360+4xx组合→重置→下一页；筛选合计正确、分页50行、汇总不随页码改变，无页面横向溢出 |
| Core manifest / PHP lint / diff检查 | 355个Core文件清单和哈希匹配，7个新增/改动PHP文件lint通过，冻结迁移无改动 |
| `php tests/release_gate_v1_contract.php` | 授权更新服务器PHP8.5.9、从干净3dcc700克隆应用本次限定patch，实跑PASS；含不完整包拒绝断言 |

服务器Gate的输入patch SHA256：`121cafc9771187fa1356416393ad5b86d75bef836eb0c56725dd8322cf0d4f70`；没有在本机生成私钥，没有绕过签名/完整性断言。此为开发阶段的V1合约测试，不是新版本完整正式签名/发布Gate635项验收。

截图及原始证据在本任务 `outputs/domestic-crawlers/`；截图数字为合成样本，不是真实蜘蛛流量。现有磁盘数据结构未变且不增加SQL，本轮不重复MySQL迁移验收。以上为首轮开发验收边界；本轮补充已完成只读访问调查和测试签名升级/回滚。真机、PR67正式签名及收录/AI引用效果仍未验收；见下文。

## 知识库同步状态

沿用根目录V1知识包，新增国内蜘蛛统计的内部候选事实并固定到本次源码提交；不将未发布功能导出给客服。原SEO/GEO、主题插件API、robots策略和迁移契约未扩展，相关既有文档保留原样，本说明记录新增兼容规则和界面教程。

知识包会运行 `product_knowledge.py --write` 及无参数校验；源码复核的NOT_RUN_THIS_TASK与上表实际运行结果分别记录。没有导入生产知识库、重建索引、应用到客服消费者或部署自动同步。

## PR67本轮补充验收

本轮冻结运行源码`7ef7704e89a9c5d1ac60581aa611e8920582f0e5`；保持Draft，未合并、未发布、未部署。界面明确显示“已采集请求数”，并说明丢样数量未知，不能推算完整访问量或采集率。主线官网/README修改已完整保留。

93项蜘蛛专项、65项SEO/GEO、40项导航回归通过；最终Phase0和测试签名parity637项通过；188与当前189两个源版本的隔离原生升级/自动回滚/重试各35项通过。正式preflight按规则拒绝非main提交，未绕过正式签名门禁；真机验收由Owner选择暂缓。

只读日志中百度26次声明、36039次、搜狗0；其中百度20次来源通过crawl.baidu.com正反向对照，36039次均匹配官方公布IP段。空聚合不能证明未访问；当前生产词表尚不识别360/搜狗，历史缺失不能全归因为竞争丢样。详见[补充测试矩阵、风险和回滚证据](pr67-supplemental-acceptance.md)。
