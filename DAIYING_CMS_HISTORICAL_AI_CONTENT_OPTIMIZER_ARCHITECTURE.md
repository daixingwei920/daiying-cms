# Daiying CMS Historical AI Content Optimizer Architecture

## Status

Architecture implemented locally as part of the historical article SEO/content workflow.

Production release: not started.

Production article backfill: not started.

## Existing AI Provider Audit

Daiying CMS Core already has a unified AI architecture:

- `Cms\Core\Ai\AiService`
- `Cms\Core\Ai\AiGateway`
- `Cms\Core\Ai\AiRequest`
- `Cms\Core\Ai\AiResponse`
- `Cms\Core\Ai\AiProviderRegistry`
- `Cms\Core\Ai\SiteAiSettingsRepository`

Supported adapters/providers include:

- OpenAI-compatible providers
- Gemini
- OpenClaw
- local OpenAI-compatible models
- fallback-capable provider presets

Therefore historical content optimization must use this existing AI stack and must not create a second model/API configuration.

## Provider Ownership

Historical Content Optimizer calls:

`HistoricalArticleSeoBackfillService -> AiService -> AiGateway -> configured site AI Provider`

No provider is hardcoded.

The model is administrator-configured through existing AI settings.

## Workflow

### A Grade

Do not rewrite body by default.

Only generate/fill missing SEO fields:

- SEO Description
- Target Keywords
- Auxiliary Keywords / `seo_keywords`
- Canonical
- Keyword Center binding
- SEO Lifecycle readiness

### B Grade

AI generates an expansion draft:

1. Reads original article.
2. Determines original topic.
3. Determines search intent.
4. Determines primary keyword.
5. Determines auxiliary long-tail keywords.
6. Adds useful content without changing topic.
7. Improves heading structure.
8. Adds steps, examples, notes, FAQ-style content when useful.
9. Generates SEO after the optimized draft.

### C Grade

AI generates a focused rewrite/expansion draft:

1. Reads original article.
2. Identifies topic.
3. Analyzes search intent.
4. Decides whether the page has independent search value.
5. Determines primary keyword.
6. Redesigns structure.
7. Rewrites/expands without inventing unsupported product facts.
8. Generates SEO after the optimized draft.

### D Grade

Manual review only.

## Safety Model

AI output is always Draft / Pending Review.

AI never automatically:

- publishes body changes
- deletes content
- changes slug/URL
- overwrites existing manual SEO fields
- fabricates Baidu indexing/ranking/search metrics

Original body remains in `cms_contents.blocks_json` until an administrator explicitly approves future publication.

## Product Truth Context

The prompt includes trusted Daiying CMS context from Core/product capabilities:

- content management
- SEO keyword center/lifecycle/sitemap
- Google Search Console search metrics
- Baidu URL submission plugin bridge
- AI Provider architecture
- plugin/theme management
- Commerce/payment features
- signed update/recovery/health flow

It also includes snippets from existing high-quality site articles where available.

If context is insufficient, the AI is instructed to mark the claim for administrator verification instead of presenting it as fact.

## Output Contract

AI must return JSON:

- `search_intent`
- `primary_keyword`
- `auxiliary_keywords`
- `seo_title`
- `seo_description`
- `body_markdown`
- `change_summary`

The service validates required fields and rejects invalid JSON.

## Storage

Existing historical workflow table stores:

- proposal source: `rules` or `ai`
- search intent
- primary keyword
- auxiliary keywords
- AI provider
- AI model
- AI request id
- AI usage JSON
- change summary
- proposed SEO metadata
- proposed body draft blocks

Existing SEO data remains the source of truth until administrator action.

## Fallback

The deterministic generator remains available as:

- no-AI fallback
- basic SEO suggestion
- preview/safety baseline

The UI labels rule suggestions separately from AI content optimization.

## Production Plan

Do not bulk process 53 B/C articles.

After release approval:

1. Release patch.
2. Deploy production.
3. Verify `SEO -> 历史文章 SEO 补全`.
4. Verify `SEO -> 历史内容优化`.
5. Select one typical C-grade article.
6. Generate AI draft only.
7. Do not approve/publish.
8. Produce pilot report for human review.
