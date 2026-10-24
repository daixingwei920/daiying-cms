# Issue #9 batch B — frozen implementation contract (2026-10-23)

Scope: existing Core settings and public SEO outputs; no new SDK, machine-publish API, authentication system, schema migration or business module. Base: Core1.2.88; this is an unpublished candidate. Sitemap stays unchanged. Batch A is separately revertible.

## Settings (existing /admin/settings GET/POST)

Existing `seo.robots_index`, site fields and SEO routes retain meaning. Add only optional values under seo:

| Key | Default | Validation / behavior |
| --- | --- | --- |
| title_template | empty | at most 300 UTF-8 bytes |
| description_template | empty | at most 600 UTF-8 bytes |
| keywords_template | empty | at most 300 UTF-8 bytes |
| schema_enabled | false | boolean |
| llms_enabled | false | boolean |
| ai_search_policy | inherit | inherit / allow / disallow |
| ai_training_policy | inherit | inherit / allow / disallow |
| crawler_log_enabled | false | boolean |
| crawler_retention_days | 7 | integer 1..30 |

Templates support only {title}, {site_name}, {description}, {keywords}; no evaluation, HTML, controls or unknown variables. Explicit content/taxonomy SEO fields win, then a configured template, then existing fallback. Unconfigured templates preserve old output. Partial old settings submissions preserve added options unless the new section marker is present. Mutation requires administrator session, settings.manage capability and CSRF; statistics use the same permission.

## Theme/plugin output

Existing TemplateContext::seo and plugin SEO registration signatures do not change. Existing keys remain. Internal Core render hints stay inside the seo array. Core ThemeRuntime enhances full HTML heads without changing installed theme files; it replaces configured TDK tags once and injects safely encoded JSON-LD once. Empty settings preserve output. No injection into fragments or non-HTML outputs. Homepage providers that bypass ThemeRuntime own their rendering and are explicitly outside automatic enhancement.

Schema: WebSite on home, Article on a published article, WebPage on published pages, CollectionPage on archives. Use only public name/description/canonical/site name; no articleBody, draft, preview, paid-content body, private user data or invented author. No schema on noindex, preview or paid pages. Existing schema with the same type suppresses the Core default; existing plugin providers are invoked with a curated public context, exceptions isolated. Full-page themes are tested; arbitrary third-party templates still need acceptance.

## Public text endpoints

robots.txt keeps existing global rules. Global noindex wins over all per-bot policies. ai_search_policy applies OAI-SearchBot and Claude-SearchBot; ai_training_policy applies GPTBot and ClaudeBot. A specific allow repeats private-path exclusions because specific agent groups do not inherit the wildcard group. This is robots guidance, not HTTP authentication/firewall enforcement. It does not promise recommendation, inclusion or traffic.

GET/HEAD /llms.txt returns text/plain Markdown when enabled, otherwise 404; global noindex returns an empty public-content section. Limit to 200 recently updated published public items; query uses a fixed limit, then excludes noindex, paid or malformed metadata, and foreign canonical aliases. List only escaped titles and canonical site URLs; no excerpts/body/private metadata. Never use a request Host to form absolute URLs. Subdirectory-aware. No new route may be used as a page slug.

llms.txt is a community proposal, not a ranking or crawler compliance guarantee. Providers and custom plugin pages are not auto-discovered.

## Crawler records/statistics

Opt-in local JSON aggregates in storage/logs/seo-crawlers.json; not publicly served. Identify a bounded set of bot tokens from at most 512 UA bytes, always source_unverified. Never persist IP, raw UA, query, credentials, exact private paths or response bodies. Store only UTC day, known bot name, coarse public route category, response status and bounded hit count. Label the UI "UA 自称，来源未验证"; no official-source badge. Spoofed UA changes counts only, never grants access.

Hard limits: 2,000 aggregate rows and 512 KiB, 1..30 UTC-day retention. Nonblocking file lock; contention, full capacity, corrupt/unwritable storage drop collection and never fail front requests. Counts saturate. Reads hide expired rows and attempt pruning; writes prune, including when collection is disabled. Explicit CLI prune supports scheduled cleanup on idle sites, so hosts must schedule it to guarantee physical deletion without requests. No per-request DNS or external verification. Source authentication is not implemented and is not implied by these counts.

No database migration; rollback to 1.2.88 ignores optional keys and bounded log file. Do not delete historical schemas or authorizations. The prune CLI only operates on this dedicated bounded crawler file.

## Evidence and documentation

Test authentication/CSRF/settings capability; old submissions; template precedence/escaping; actual theme head output and plugin registry; paid/draft/noindex exclusion; root/subdirectory text endpoints; global robots override/private exclusions; spoofed UA classification; retention/caps/locks/concurrency/failure; upgrade/rollback with retained data. Frozen published migrations remain byte-identical. No real provider calls, payment, signing or production changes are implied.

References reviewed: [OpenAI crawlers](https://developers.openai.com/api/docs/bots), [Anthropic crawlers](https://support.anthropic.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler), [llms.txt proposal](https://llmstxt.org/). Changes to public SDK or scopes beyond this contract require separate approval.
