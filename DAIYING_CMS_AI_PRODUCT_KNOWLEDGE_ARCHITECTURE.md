# Daiying CMS AI Product Knowledge Architecture

Original design: 2026-10-06

Knowledge revision: 2026-10-14.1, pinned to official Core v1.2.83.

## Goal

Create one versioned product-fact layer for Daiying CMS AI consumers.

Consumers include:

- Historical Content Optimizer
- SEO AI
- Content generation AI
- Daiying CMS AI customer service
- Daiying AI Workbench agents
- GPT-4.1-mini
- local qwen2.5:7b
- future local models

This is not model training. The first phase is verified knowledge plus retrieval/context building.

## Architecture

```text
Daiying CMS Core / Docs / Verified Production Facts
-> AI Product Knowledge
-> Knowledge Retriever / Context Builder
-> GPT-4.1-mini / Local Qwen / Customer Service / SEO AI / Future Agents
```

## Fact Classes

### USER-FACING

Facts that administrators or users can operate directly.

Allowed evidence:

- public route
- admin route
- visible UI
- form field
- button
- documented CLI command
- official operation document

Model rule:

Only USER-FACING facts may be written as “进入后台 / 点击 / 选择 / 打开 / 执行 / 上传 / 创建 / 恢复”.

### INTERNAL

Facts that describe Core implementation.

Examples:

- PHP class
- service
- repository
- method
- internal state machine
- migration internals
- job internals

Model rule:

INTERNAL facts may support explicitly authorized internal engineering analysis. They must be excluded from ordinary customer-service retrieval and must never be converted into administrator steps. The USER-FACING export is the only customer-service import candidate.

### ADMIN / DEVELOPER

Advanced operations for site owners, deployers, and developers.

Examples:

- CLI command
- deployment checklist
- security hardening
- plugin development
- diagnostics
- recovery
- API usage

Model rule:

These facts require audience labeling. Do not present developer internals as beginner UI workflows.

## Knowledge Record Schema

Each record should include:

- `id`
- `topic`
- `capability`
- `description`
- `audience`
- `source`
- `source_type`
- `evidence`
- `user_facing`
- `admin_route`
- `public_route`
- `cli_command`
- `verified_version`
- `confidence`
- `last_verified_at`

## Retrieval Policy

Historical Optimizer flow:

```text
article title/body
-> topic detection
-> retrieve 3-8 relevant knowledge records
-> split USER-FACING / INTERNAL / ADMIN_DEVELOPER facts
-> build prompt context
-> model draft
-> store pending review
```

Rules:

- Do not inject the whole knowledge base.
- Prefer USER-FACING facts for tutorials.
- Do not pass INTERNAL facts to public customer service or ordinary user-facing article drafting. Internal engineering use requires an explicit authorized audience.
- Security and recovery articles remain human-gated.
- Submitted, indexed, and observed search data must remain separate concepts.

## Local Model Readiness

The knowledge layer is model-neutral:

- Markdown for humans and strong-model prompts.
- JSON for programmatic retrieval.
- Chunkable records for future bge-m3 embeddings and RAG.

No fine-tuning, LoRA, or vector database is required for V1.

## Prompt Guardrails

For user-facing articles:

- Do not output Core class names unless the article is explicitly for developers.
- Do not turn internal methods into click paths.
- Any phrase like “点击 / 进入后台 / 打开 / 执行 / 上传 / 创建 / 恢复” must be supported by UI, route, CLI, or official operation evidence.
- Unknown capabilities must be omitted or marked “需要管理员确认”.
- Do not invent plugin support, prices, customer cases, rankings, search metrics, or server-specific configuration.

## Versioning

Knowledge V1 starts with the domains needed for current historical content optimization:

1. Installation
2. Content Model
3. Migration
4. Update
5. Backup / Recovery
6. Security
7. SEO
8. Sitemap
9. Keyword Center
10. Search Metrics
11. Plugin / Theme
12. Commerce
13. AI Provider

Future batches should add or refine records before rewriting related articles.


## Current Implementation and Validation Status

The V1 fact package, Markdown companion and USER-FACING projection are versioned documentation. Every current record is SOURCE_REVIEWED against the pinned source commit and marked NOT_RUN_THIS_TASK for runtime tests. Source evidence includes relative paths, full-file SHA-256 and line anchors. Source review does not prove installation, device, provider, payment or production acceptance.

`scripts/product_knowledge.py` checks provenance and produces the two derived files. It is offline documentation tooling. It neither creates a new retrieval system nor enables automatic synchronization.

Historical plugin Site Knowledge import and keyword Retriever implementations exist outside this Core tree; historical AI-node indexing and Git incremental tools exist separately. Their existence does not prove that this revision was imported or that all policy checks are enforced.

The original Core historical optimizer still uses its private product context and has not been connected to this shared package. Do not alter Core or bypass private methods to claim integration. A missing generic extension requires a separate Owner decision.

## Required Retrieval Policy

P0 official knowledge precedes P1 reviewed published articles, then P2 ordinary published articles. Rank relevance within each tier. Draft, Pending, C/D, INTERNAL and records with unknown review/classification state are disabled by default. An article being optimized may be input without becoming reference knowledge. Exact FAQ shortcuts and legacy content chunks must follow the same policy.

Customer service uses the actual site/plugin-configured model through Core AiService/AiGateway. The historical gpt-4.1-mini preset is not a claim about the current production model. The package does not change a model or any site settings. Generic customer-service plugins must not inject Daiying product knowledge into unrelated sites.

## Synchronization Status and Future Work

The unified pipeline is IMPLEMENTED_ISOLATED as of 2026-10-25: release-event files, persistent inbox/queue, daily/weekly checks, bounded retries, transactional consumer receipts and native customer HTTP retrieval are tested in disposable environments. Reuse is pinned to PR41 tooling and the separately locked customer plugin candidate. Initial protocol-fixture acceptance and subsequent real configured-provider acceptance are separate evidence classes. Neither deploys a webhook, scheduler or production consumer. The Core optimizer integration remains NOT_IMPLEMENTED. See [initial acceptance](docs/knowledge-customer-loop-acceptance.md) and [real-provider supplement](docs/knowledge-customer-loop-live-acceptance.md).

Subsequent real-business article admission exposes a blocking relevance defect: generic P0 n-gram matches suppress all relevant P1 candidates in seven natural questions. Transactional admission/withdrawal passes while business retrieval fails. Consumer acknowledgements and the earlier synthetic/installation model tests do not establish business readiness. Keep the batch withdrawn and do not deploy until scoped relevance/authority handling is fixed and retested. See [failed business acceptance](docs/knowledge-article-admission-acceptance.md).

The Owner-authorized minimal repair in independent plugin1.1.1-dev.2 filters lexical relevance before authority ordering, preserving concise related P0 evidence. The same seven questions, conflict, unknown and withdrawal checks now pass in isolation; 9 focused tests, 21 consumer regressions and 41 live-provider HTTP checks passed. This supersedes the preceding blocker for the tested corpus, not the historical evidence, deployment gate, large-corpus limit or general semantic-quality limitations. See [repair acceptance](docs/knowledge-relevance-fix-acceptance.md).

The required mechanism is change-triggered synchronization, not a Codex call for every conversation. It must preserve logical source IDs, incrementally skip unchanged hashes, deduplicate, withdraw deleted or unapproved facts, mark component versions, retain provenance, and report failures without advancing successful consumer cursors. Failed batches must retain the previous valid snapshot. Official release, documentation, plugin/theme and schema changes all belong in the source manifest.

## Current Package Use

- Full package: `DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1.json`, including internal engineering facts.
- Customer-service candidate: `DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1.user-facing.json`, with only enabled USER-FACING records.
- Human companion: `DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1.md`.
- Refresh derived files: `python3 scripts/product_knowledge.py --write`.
- Check facts, source drift and derived consistency: `python3 scripts/product_knowledge.py`.

The legacy importer does not enforce all new metadata. Never import the full package there. The public projection reduces internal exposure but does not implement runtime tier ordering, revocation, deduplication or complete leakage defenses. Import and end-to-end acceptance require separate validation.

The original 332-record `daixiaoying-ai-customer-service-qa-v1.jsonl` has not been recovered and is not included. Other QA datasets are not substitutes. See `docs/knowledge/2026-10-14-sync-report.md` for this revision's verified scope and open issues.


## Issue #9 candidate knowledge boundary

The V1 package on this branch will pin the immutable Issue #9 implementation commit, with candidate_evidence.status=UNPUBLISHED and release_evidence retained as the historical 1.2.88 release. source_tag may hold that exact Git commit as an immutable ref; no release tag is created. Collaboration retirement and SEO/GEO are ADMIN_DEVELOPER facts, not production availability claims. Runtime evidence stays in the Issue #9 delivery report; source-review records retain NOT_RUN_THIS_TASK. No knowledge import, index rebuild, consumer deployment or automatic synchronization is implied.
