# CMS Core Payment Foundation Report

Date: 2026-08-20

## Scope Decision

This workstream stops payment-as-plugin development. Payment is now treated as a CMS Core foundation capability.

Existing payment-plugin ideas were used only as implementation reference. New code was placed under `system/core` and `system/migrations`; no plugin directory was edited for this batch.

## Implemented

- Core namespace: `Cms\Core\Payment`.
- Provider contract: `PaymentProviderInterface`.
- Provider registry: `PaymentProviderRegistry`, with non-canonical registration ids rejected before they can become trusted Provider keys.
- Provider result DTO: `PaymentResult`.
- Core manual payment Provider:
  - built-in Provider id `core.manual-payment`;
  - registered by Core in every runtime, including production;
  - creates pending, operator-confirmed payment records for offline/bank/manual settlement without plugin runtime;
  - administrator capture marks trusted paid state through the normal Core ledger and audit path;
  - status sync cannot promote pending manual payments without an administrator capture action;
  - administrator cancellation and refunds are recorded through the normal Core lifecycle path;
  - public paid content/download checkout renders a Core `202 Accepted` pending-confirmation receipt with `Cache-Control: private, no-store` when no external Provider checkout URL exists;
  - command amounts and currencies must already be canonical before Core creates manual payment references;
  - status sync and lifecycle commands reject non-canonical current statuses and remote references instead of trimming, lowercasing or falling back to `pending`;
  - pending manual checkout receipts show only already-canonical Core payment references, operator instructions and non-token canonical Core completion/content links without issuing content/download bearer tokens;
  - manual payment instructions must already be Core display-safe and non-whitespace-padded before checkout creates payment rows, and restored non-canonical pending instruction metadata is not trimmed into visitor-facing receipts;
  - paid content/download checkout runtime treats malformed or non-string restored payment metadata as unavailable metadata before reusing stored Provider redirect URLs or manual pending instructions, so corrupted rows cannot be cast into checkout evidence;
  - manual completion links fail before administrator capture and mint tokenized access only after the payment becomes trusted `paid`;
  - manual completion links evaluate the same currency-scoped trusted net paid state as existing authorization checks, so partial refunds do not block completion while the subject remains trusted paid;
  - Core admin payment detail highlights canonical non-token-like manual payment references, operator instructions and confirmation guidance without exposing bearer tokens, token-like legacy metadata values including URL-decoded token-like manual references, whitespace-normalized metadata references or trimmed non-canonical manual instructions;
  - it does not make the CMS a fund custodian and does not depend on any plugin code.
- Core hosted redirect payment Provider:
  - built-in Provider id `core.hosted-redirect`;
  - registered by Core in every runtime, including production;
  - creates pending hosted checkout records and redirects visitors to an operator-configured third-party HTTPS checkout URL;
  - appends Core payment reference, amount, currency, subject, return URL and optional HMAC checkout signature to the external checkout URL;
  - rejects non-canonical command amounts, currencies and checkout signing secrets instead of casting, uppercasing or trimming them during external checkout creation;
  - requires public URL configuration and return path metadata to be strings before external checkout creation;
  - requires a query-free HTTPS `return_url_base` when Core's signed local completion or cancellation URLs are relative, so external PSP pages only receive predictable absolute CMS Core return URLs;
  - keeps checkout signing secrets in Core encrypted Provider secrets, not public JSON;
  - fails closed before creating Core payment rows when hosted checkout or required/configured return URL base is missing, non-HTTPS or carries query parameters;
  - status sync rejects non-canonical current Core status values instead of trimming or lowercasing them before returning pending hosted state;
  - does not trust the visitor return URL as payment proof; local authorization is issued only after the Core ledger is updated to trusted `paid`, normally by the signed Core webhook endpoint;
  - stores checkout URLs with sensitive query values redacted and does not issue bearer tokens before webhook-confirmed trusted payment state.
- Core fixture provider for deterministic tests: `FixturePaymentProvider`, with non-canonical scenarios, amounts, currencies and unsafe command reference text rejected before deterministic payment references are created.
  - The fixture Provider is Core code for deterministic tests, not plugin runtime.
  - Runtime registration is gated by `payment.fixture_provider_enabled`; the default is `false`, so ordinary and production runtime register only `core.manual-payment` and `core.hosted-redirect` unless tests explicitly opt in.
  - The installer writes Core payment defaults into new `config/app.php` files, including paid download token TTL/use limits and paid content token TTL, and always resets `fixture_provider_enabled=false` so fresh installs cannot inherit a test Provider from a seeded or legacy pre-install config.
  - `official.payment-fixture` is no longer listed as a trusted bundled official plugin in the Core official-plugin registry; the `official.` id remains reserved, but payment fixture behavior lives in Core test infrastructure instead of plugin runtime.
  - `scripts/build_payment_fixture_release.php` is retained only as a retired entrypoint that exits non-zero and explains that payment is now a CMS Core foundation; it no longer produces plugin ZIP, sidecar or manifest artifacts.
  - CMS release package construction and production-readiness package checks exclude the retired `content/plugins/official.payment-fixture` artifact so payment is delivered through Core, not as a bundled Provider plugin.
- Built-in Core Providers reject non-string, control-character, whitespace-padded, oversized, token-like or URL-decoded token-like command subjects, idempotency keys, remote references and status hints before deterministic/manual/hosted Provider references or status results are generated; Core service-supplied positive integer `payment_id` values remain accepted for refund-reference seeding.
- Core provider registry: registration and lookup both require already-canonical Provider ids, so whitespace-padded or case-normalized ids cannot resolve to trusted Core Providers.
- Core provider settings repository: `PaymentProviderSettingsRepository`.
- Core repository/service:
  - trusted payment creation;
  - provider capture, cancel and status sync;
  - provider status sync current ledger state and expected-state hints must already be canonical Core states before any Provider status call;
  - provider/webhook status updates cannot downgrade already-paid trusted payment records;
  - idempotency key protection, with create/refund retries resolved under Core transaction locks before Providers are called again;
  - create/refund/capture/cancel service inputs reject control-character subjects, token-like idempotency keys, URL-decoded token-like idempotency keys, Provider scenario hints and unsafe or whitespace-padded non-canonical refund reasons before any Provider call or ledger write;
  - Provider result envelope fields are validated before Core writes payment/refund rows or audit context: result codes must be lowercase canonical identifiers, messages must be bounded display-safe text, request ids must be empty or canonical safe references, Provider result `status`, remote payment reference and remote refund reference fields must be strings, and checkout/payment/redirect URL fields must be string safe URLs before Core accepts them into create, capture, cancel, status-sync or refund ledger paths;
  - capture, cancel, refund and status-sync lifecycle actions revalidate existing Core payment ledger Provider ids, subject references, remote references, statuses, amounts and currencies before building Provider commands or writing lifecycle audit context, so restored or manually corrupted values fail closed instead of being cast, trimmed or uppercased into external side effects;
  - duplicate provider remote-reference protection;
  - duplicate provider remote refund-reference protection;
  - Provider-returned and webhook-supplied remote payment/refund references reject control characters, whitespace-padded non-canonical values, token-like values and URL-decoded token-like values before entering Core ledger rows;
  - payment and refund ledger inserts require string identity/state/currency/hash/reason fields, then validate canonical non-token-like subject ids, canonical Provider ids, states, bounded canonical positive minor-unit amounts with no leading-zero or oversized numeric strings, uppercase ISO-style currencies, idempotency keys, request hashes and display-safe remote references before writing rows; new payment rows cannot be created directly as `partially_refunded` or `refunded`;
  - payment, refund, authorization, entitlement and webhook receipt statuses must already be canonical Core state values at runtime; repository and service paths reject whitespace-padded or case-normalized statuses instead of silently correcting them;
  - Core payment request hashes, refund request hashes, webhook payload hashes and authorization token hashes must be canonical lowercase 64-character SHA-256 hex values before entering Core ledger rows;
  - payment, refund, authorization, entitlement, authorization-event and webhook receipt metadata keys must be bounded Core-safe identifiers, and metadata is redacted at the Core service/repository boundaries so direct Core callers cannot persist raw bearer tokens, token-like values including URL-decoded token-like values, URL-decoded whitespace/control metadata values, secrets, signatures, key-like credentials such as `auth`, `api_key` or `access_key`, private contact fields, sensitive URL claims, array-shaped URL query values, token-like URL path/query values or fragment-carried bearer data, including `auth`, `key`, `password` and `private` URL query values;
  - payment repository reads return associative columns only across payment, refund, authorization, entitlement, search/export/summary and webhook receipt paths, so PDO numeric duplicate fields cannot re-expose metadata or hash-bearing columns to admin/runtime/export callers;
  - payment repository read paths validate payment ids, refund ids, authorization ids, entitlement ids, webhook receipt ids, idempotency keys, remote references, subject/principal ids, Provider ids, string payment search/export/summary filters, blank/non-canonical webhook Provider filters, canonical uppercase currencies and list limits before querying trusted payment, refund, status, authorization, entitlement or webhook receipt state, so future Core callers cannot treat malformed lookup inputs as ambiguous empty trusted-state responses or broad receipt listings;
  - payment repository update paths validate payment statuses, enforce the Core payment state machine, require `partially_refunded`/`refunded` states to match completed refund totals, revoke active payment-backed authorizations and entitlements when a payment becomes fully refunded, and validate webhook receipt statuses and webhook-to-payment bindings before writing rows, including requiring receipt Provider IDs to match bound payment Provider IDs, so future Core callers cannot bypass service-layer checks and persist impossible lifecycle state;
  - authorization and entitlement inserts require string subject/principal/status/hash/expiry fields before canonical validation, bounded authorization usage counters before integer conversion, and mutation paths reject invalid ids, restored non-canonical authorization payment ids and invalid or over-1000 expiry batch limits before changing access rows or writing authorization events, so scheduled maintenance or future Core callers cannot accidentally process one row from a zero/negative limit, silently clamp an oversized batch or revoke a corrupted authorization by integer-casting it back onto a trusted payment;
  - refund ledger inserts also require a refundable source payment with matching Provider and currency, reject corrupted source payment amounts and completed refund totals above the source payment amount, and reject control-character or whitespace-padded non-canonical refund reasons before storage;
  - webhook-supplied refund amounts must be bounded canonical positive integer minor-unit values and are not accepted through PHP's loose numeric casting, integer overflow or whitespace trimming;
  - admin refund requests also require canonical positive integer minor-unit amounts and already-canonical refund reasons before Core invokes Provider refund logic or writes refund audit records;
  - trusted payment status by `subject_type` and `subject_id`;
  - trusted payment status, export and summary reads validate restored payment ids plus ledger amount/currency rows before aggregating net paid state, so corrupted restored values cannot be trusted through SQL numeric coercion or PHP loose casts;
  - optional currency-scoped trusted payment status for mixed-currency subjects;
  - provider refunds;
  - parent payment status updates for partial/full refunds;
  - full refunds from either Provider lifecycle calls or signed webhook refunds automatically revoke active payment-backed authorization tokens and entitlements for the refunded payment;
  - stable Core-synthesized refund references when a Provider omits its remote refund reference;
  - signed provider webhook intake with string canonical timestamp, event id and lowercase HMAC signature headers;
  - webhook receipt deduplication, with public webhook success/failure/method responses marked `Cache-Control: private, no-store`;
  - PaymentService rejects whitespace-padded non-canonical webhook event ids, token-like event ids and URL-decoded token-like event ids before storing receipts, matching the Core webhook controller and repository boundary;
  - webhook receipt repository writes validate canonical Provider ids, canonical and non-token-like event ids, received-only creation status, payload hashes, bounded payload-size trace metadata and optional payment ids before storing rows; endpoint webhook headers and payload event ids must already be strings before signature verification or receipt storage; processed, ignored and failed receipt states must be reached through Core webhook application/status/failure paths;
  - webhook receipts bind to the target Core payment once a signed payload resolves its remote payment reference, and webhook application requires the raw payload to match the receipt's recorded payload hash before touching payment state;
  - webhook receipt status marking as processed, ignored or failed with safe diagnostic metadata; service-level failed receipt diagnostics preserve safe summaries but replace token-like, secret-like, signature-like, URL-decoded token-like or unsafe errors with a fixed safe summary, and repository failed-state writes reject non-canonical raw/JSON-like/token-like failure diagnostics and non-canonical failure timestamps, with direct failed writes rejected outside the diagnostic failure path;
  - repository webhook receipt status and failed-diagnostic writes reject malformed restored receipt metadata JSON instead of replacing it with an empty trusted object, leaving the corrupted row unchanged for recovery/import diagnostics;
  - provider-neutral webhook payment status application, with remote payment references and status values required to be strings before receipt binding or trusted payment state changes;
  - provider-neutral webhook refund recording with idempotent receipt handling, with remote payment/refund references and refund statuses required to be strings before refund rows are written or receipts bind to trusted payments;
  - webhook event type values must already be canonical lowercase event identifiers before Core uses them to classify payment or refund updates;
  - signed Provider webhook payload status, payment reference and refund reference fields must be strings before Core applies trusted payment or refund state, so JSON booleans or numbers cannot be string-cast into ledger evidence;
  - webhook endpoint and service-level webhook application reject malformed JSON payloads through controlled Core parsing before storing public endpoint receipts or applying trusted payment/refund state;
  - webhook payment and refund status values must already be canonical before Core applies them to trusted payment or refund state;
  - refund webhook events must carry an explicit canonical refund status; Core does not infer completed refunds from event names alone;
  - webhook application of receipt binding, refund effects, payment status changes, processed marking and audit logging is transactionally atomic, so failed application cannot leave a failed receipt bound to a trusted payment target;
  - refund webhook events keep Core's refund-derived `partially_refunded`/`refunded` state when Providers also send stale broad payment states such as `paid`;
  - signed webhook events that contain no Core payment status or refund update are terminally marked `ignored` with minimized audit context instead of lingering as unprocessed receipts, and the ignored status plus audit record are committed atomically;
  - failed webhook receipts can recover on a later identical signed delivery when the target Core payment becomes available, and stale failure diagnostics are cleared once the receipt is processed;
  - duplicate processed/ignored webhook deliveries are treated as idempotent terminal receipts and are not re-applied, and terminal receipt payment bindings must still be canonical restored payment ids before Core returns a payment context;
  - administrative webhook receipt status changes must submit canonical `processed` or `ignored` states exactly and can act only on current `received` or `failed` receipts; Core rejects whitespace-padded, case-normalized or corrupted-current-state variants before changing receipt rows or writing audit events, and status changes are committed atomically with their audit records;
  - Core rejects attempts to rewrite terminal processed webhook receipts into another terminal state through forged administrative status actions;
  - tokenless payment entitlements for member/user-style principals;
  - entitlement service grant and lookup paths reject control-character principal or subject ids before querying or writing tokenless access rows, and reject restored or manually corrupted source authorization payment ids before binding member access through a source authorization;
  - admin entitlement revoke revalidates restored entitlement source payment ids before revoking tokenless access or writing audit rows, commits entitlement state and audit atomically, and prevents corrupted source ids from being cast back onto the current payment context;
  - entitlement ledger writes reject restored or manually corrupted source authorization payment ids before creating tokenless access grants, so Core callers cannot bind member access through integer-cast authorization ownership;
  - expired active entitlements can be marked `expired` by Core so tokenless access state and the administrative ledger stay aligned;
  - CLI entitlement expiry rejects non-positive, out-of-bounds, leading-zero or whitespace-padded non-canonical limits with machine-readable failure JSON before loading site configuration, opening a database connection, marking grants or writing audit records, and sanitizes token-like, secret-like, signature-like, URL-decoded token-like or unsafe audit failure diagnostics before returning JSON;
  - payment metadata redaction;
  - Core payment ledger restore rejects valid-but-formatted non-canonical JSON column strings, so restored payment metadata and Provider public config must already match the compact JSON shape written by Core;
  - payment create, capture, cancel and refund reject unsafe, token-like, URL-decoded token-like or whitespace-padded non-canonical idempotency keys before Provider calls, ledger writes or admin audit records;
  - CMS audit events for Provider create, capture, cancel, refund, status sync and webhook application, with minimized/redacted context;
  - Provider capture, cancel, refund and status-sync ledger mutations are committed atomically with their Core audit records, so audit persistence failures cannot leave half-applied trusted payment state.
- Core provider settings:
  - `cms_payment_provider_settings` stores provider public config and encrypted secret config;
  - Provider setting statuses must already be canonical `enabled` or `disabled` values before persistence or admin save branching;
  - Provider display names are bounded Core display text and reject whitespace-padded non-canonical values, control characters, token-like values and URL-decoded token-like values before settings persistence;
  - public config rejects secret-like fields, including `api_key`, `access_key` and `auth`-named fields, while still allowing explicitly public fields such as `publishable_key`; it also rejects unsafe public config keys, token-like public values including URL-decoded token-like values, unsafe or whitespace-padded non-canonical string values, malformed/non-absolute public URL values, sensitive public URL query parameters, token-like decoded public URL path/query values and non-scalar values before persistence;
  - secret config is encrypted with the CMS encryption key and rendered only as non-revealing configured placeholders, without exposing raw suffixes;
  - Provider secret encryption requires the CMS encryption key itself to be present, whitespace-canonical, at least 16 bytes and free of control characters before Core stores or reads encrypted secrets;
  - secret config keys and values must be native strings and are validated by Core before encryption so empty keys, empty values, unsafe identifiers, invalid UTF-8 values, whitespace-padded non-canonical values or control-character values cannot be persisted through admin or lower-level settings writes;
  - Core signing secrets such as `webhook_secret` and `checkout_secret` must meet a minimum length before encryption so weak HMAC keys cannot become trusted Provider configuration;
  - decrypted Provider secret payloads are revalidated at runtime; malformed keys, non-string values, empty values, whitespace-padded non-canonical values or control-character values fail closed instead of being silently dropped or trimmed;
  - Provider settings writes and default-marker mutations encode public config with controlled JSON exceptions, so invalid UTF-8 public config values cannot be stored as empty/broken Core configuration;
  - restored or manually altered Provider public config is revalidated at runtime; non-canonical JSON strings, valid-but-formatted JSON variants, JSON decode/encode failures, secret-like public fields such as `api_key`, `access_key` and `auth` keys, token-like public values including URL-decoded token-like values, unsafe keys, non-scalar values, control-character strings, whitespace-padded non-canonical string values, malformed/non-absolute URL values, `return_url_base` values with query parameters, sensitive URL query parameters and token-like decoded URL path/query values fail closed before Provider command objects are created;
  - blank secret submissions preserve existing encrypted secrets only when the stored ciphertext can still be decrypted and revalidated by Core; corrupt restored secret ciphertext must be replaced explicitly instead of being carried forward by unrelated settings saves;
  - admin Provider settings saves commit public configuration, encrypted secrets, default Provider markers and administrator audit records atomically, so audit persistence failures roll back the Core Provider configuration change;
  - Core admin rejects enabling unregistered Providers;
  - administrators can designate one enabled Provider as the Core default checkout Provider;
  - default Provider changes require the target Provider's stored public config JSON to already match Core's compact canonical shape and pass public config validation, and rejected default changes do not rewrite non-canonical restored config rows;
  - disabling the current default Provider clears its default marker and checkout falls back to another enabled Provider;
  - Provider settings repository reads, writes, secret lookups and submitted default Provider ids reject non-canonical Provider ids instead of trimming or lowercasing them, while default mutation skips unrelated non-canonical imported Provider rows so legacy settings cannot block valid Core checkout administration;
  - settings are Core data and do not use plugin secret storage;
  - settings repository reads return associative columns only, so encrypted secret ciphertext and public config cannot be duplicated into numeric-indexed row fields for future Core callers;
  - PaymentService supplies Core-managed public config and decrypted secret config to Provider command objects, rejects query-bearing `return_url_base` values before Provider command construction, falls back from corrupted, token-like or URL-decoded token-like restored Provider display names to the registered Core Provider name before command construction, and rejects non-canonical, unsafe or token-like Provider result envelope fields before ledger or audit writes;
  - Provider command settings are usable by real PSP adapters without storing raw secrets in payment metadata;
  - Provider-created checkout redirect URLs must be HTTPS and cannot carry userinfo, fragments, secret-like query parameters or token-like decoded path/query values before Core writes payment rows, except for canonical 64-character lowercase Core-generated `cms_signature` parameters used by the hosted redirect baseline;
  - the just-created Provider checkout URL is returned as a transient in-memory Core value for the immediate public redirect after validation, while stored payment metadata still redacts the Core signature value so recovery packages and admin views do not persist or expose reusable checkout signatures;
  - PaymentService and public checkout only use registered Providers that are enabled in Core settings;
  - public checkout Provider selection ignores imported or manually altered settings rows whose Provider ids or public config JSON strings are not already canonical;
  - public checkout Provider selection rejects malformed Provider public config JSON through controlled Core parsing before listing defaults or accepting explicit Provider choices;
  - public checkout Provider labels do not trim non-canonical legacy display names or render token-like/URL-decoded token-like legacy display names into visitor-facing labels, and fall back to the Core Provider name instead;
  - Core paid content/download form renderers also reject non-canonical Provider ids and fall back from unsafe Provider labels without trimming them into visitor-facing checkout options;
  - public checkout uses the Core-managed default Provider when no Provider is explicitly submitted, rejects non-string Provider selections before payment creation, and ignores or rejects manually corrupted default markers that are not the exact Core boolean value, including explicit Provider checkout selection;
  - official Core payment ledger restoration rejects multiple enabled `default_provider=true` Provider settings before commit, so restored default checkout selection cannot depend on database row ordering;
  - runtime default Provider selection rejects multiple enabled checkout-capable `default_provider=true` settings before public checkout can create payment rows, so manually altered Core settings cannot make checkout depend on database row ordering;
  - public checkout forms render only Core-whitelisted Provider choices.
- Core paid download foundation:
  - attachment content blocks can declare CMS-owned paid downloads;
  - paid download subject ids are generated only from positive content and media ids, so invalid Core callers cannot mint ambiguous payment subjects;
  - media attachment metadata is encoded with controlled JSON exceptions before storage, so invalid UTF-8 media metadata cannot become empty or malformed attachment metadata for paid-download subjects;
  - paid download attachment block `media_id` values must already be canonical positive integers before Core exposes checkout configuration, so restored leading-zero or mixed-text ids are not integer-cast into paid subjects;
  - paid download attachment pricing must be a canonical positive integer minor-unit value with a canonical uppercase currency when enabled, and invalid, lowercase or whitespace-padded submissions are rejected before paid subjects can be created;
  - restored non-canonical or non-string paid download checkout labels are not trimmed or cast into visitor-facing payment labels and fall back to the Core default label;
  - restored or manually corrupted enabled paid download pricing, non-string currency values, non-canonical attachment `media_id` values or malformed content block JSON fail closed: Core keeps numerically matching paid media protected from direct file access, refuses checkout and does not silently coerce invalid prices, currencies, media ids or unreadable block payloads into low-value payment records;
  - paid download block rendering also requires already-canonical integer prices and uppercase currencies before showing a submit-capable checkout form, so malformed view-model values are not cast or uppercased into visitor-facing prices;
  - paid download block rendering requires canonical Core-local checkout and authorized download route shapes, including bounded positive ids, string query parameters and bounded token-shaped authorization links, before rendering submit forms or file links, so external, protocol-relative, tokenized action, array-shaped query, leading-zero, oversized or whitespace-padded view-model URLs stay locked;
  - public content rendering shows a Core checkout form for locked attachments;
  - public paid download checkout form includes Core CSRF protection;
  - public paid download checkout requires string POST-body Provider selection plus bounded canonical positive content/media ids before default Provider lookup and payment creation, so non-string Provider inputs, leading-zero path ids or oversized path ids cannot be coerced into real paid-download subjects or expose default Provider availability;
  - hosted Provider checkout sessions can redirect to a Provider checkout URL without issuing local download authorization early;
  - hosted Provider return can sync pending/authorized Provider status before completing local download authorization once in a transaction, only after the Core payment is trusted `paid` and the return claim is valid;
  - hosted Provider return validates bounded canonical positive path ids plus string canonical non-token-like query-string `payment_key` and lowercase signed claim shape before payment status sync or authorization creation, rejects token-like or URL-decoded token-like completion keys, rejects non-string completion parameters and ignores request-body completion parameters;
  - public checkout retries reject restored or manually altered stored Provider redirect URLs that are non-string, non-canonical, non-HTTPS, contain sensitive query parameters, malformed Core signature values or token-like decoded path/query values before redirecting visitors;
  - direct attachment URLs return `402 Payment Required` with `Cache-Control: private, no-store` until authorized;
  - successful Core checkout stores `cms_payment_authorizations` records and issues signed tokenized download URLs, while same-payment immediate checkout retries are locked and rejected before a second authorization can be minted, including after the original grant is exhausted;
  - download tokens are temporary, hash-backed in storage, and can enforce max-use limits;
  - paid download token TTL and max-use settings reject non-canonical or out-of-range values instead of silently casting or clamping access-control configuration;
  - malformed, oversized, non-string or signed malformed-JSON public download tokens are rejected before authorization consumption, so bad requests do not spend a valid download grant;
  - media download entrypoints require bounded canonical positive paid-download `media_id` path values and `content_id` query values before authorization checks, so whitespace-padded, leading-zero, mixed-text or oversized numeric ids cannot be coerced into consuming a valid grant through PHP integer conversion;
  - authorized paid attachment responses use `Cache-Control: private, no-store`, including HEAD responses, so limited-use or tokenized downloads are not intentionally cached as longer-lived local copies by the CMS response policy; HEAD, non-GET methods and invalid Range requests do not consume limited-use paid download grants, and Core consumes the grant only for a valid GET body response;
  - signed download token payloads are encoded and decoded with controlled JSON exceptions, must carry canonical JSON integer ids/timestamps, non-future issued-at values, requested-subject payloads, canonical UTC ledger-matching expiries and canonical token secrets before authorization lookup or consumption, and the authorization path continues using those typed ids instead of recasting payload strings;
  - authorization ledger inserts validate token hash, subject, active creation status, trusted paid/partially-refunded source payment, source subject match, positive future expiry, non-canonical expiry whitespace rejection and canonical no-leading-zero use counters before writing authorization rows or events; authorization consumption, revocation, expiry maintenance and active authorization lookup at the repository boundary also require unexpired active rows, canonical restored ids/token hashes and canonical non-negative usage counters before database comparison, mutation or reuse, with exhausted finite-use grants excluded from active lookup and consumption; pre-used, revoked or expired authorization state must be reached through Core mutation paths so event history cannot be bypassed, and official restore rejects expired authorizations that carry revoke timestamps;
  - authorization runtime checks reject restored or manually corrupted payment ids, expiry and use-counter rows that would need integer casting, whitespace trimming or numeric clamping before tokenized paid content/download access could be granted or consumed;
  - download tokens remain valid after partial refunds while the subject still has trusted net paid state, and fail after full refunds;
  - full refunds mark active paid download authorizations as revoked in the Core ledger rather than leaving only runtime checks to fail;
  - token signing requires a configured canonical string site security key and fails closed before payment creation when missing, non-string, whitespace-padded, too short or control-character-bearing;
  - authorization creation, consumption and revocation are recorded as Core authorization events;
  - active authorizations can be revoked by administrators and immediately invalidate their tokens;
  - expired active authorizations can be marked as `expired` by Core with event history, while restored rows with non-canonical expiry timestamps are skipped instead of being normalized into lifecycle events;
  - expired active authorizations can also be marked from CLI for scheduled maintenance, with minimized Core audit context;
  - CLI authorization expiry rejects non-positive, out-of-bounds, leading-zero or whitespace-padded non-canonical limits with machine-readable failure JSON before loading site configuration, opening a database connection, marking grants or writing audit records, and sanitizes token-like, secret-like, signature-like, URL-decoded token-like or unsafe audit failure diagnostics before returning JSON;
  - member-style entitlement checks can unlock paid downloads without exposing bearer tokens;
  - paid download entitlement checks fail closed for non-positive content or media ids before querying tokenless access;
  - authorization and entitlement ledger inserts require real calendar-valid canonical UTC future expiries before writing access rows, so impossible dates or natural-language dates cannot become Core payment truth;
  - entitlement ledger inserts validate canonical principal ids, canonical subject ids, active creation status, trusted paid/partially-refunded source payment, source subject match, optional active unexpired source authorization match and canonical UTC future expiry before writing tokenless access rows; active entitlement lookup, repository revocation and expiry maintenance require canonical restored source payment ids, active lookup revalidates optional source authorization ids/bindings and excludes expired, revoked-at or non-canonical restored rows before future Core callers can treat them as grant candidates; revoked or expired entitlement state must be reached through Core mutation paths, and official restore rejects expired entitlements that carry revoke timestamps;
  - entitlement service rejects unsafe principal and subject ids, negative source authorization ids and non-canonical UTC optional expiries before lookup or grant paths can touch tokenless access rows, rejects duplicate same-payment principal/subject grants even after a prior same-payment grant is revoked, and filters unsafe metadata keys while redacting private fields, token-like values including URL-decoded token-like values, URL-decoded whitespace/control metadata values, array-shaped URL query values, sensitive URL query keys/values, token-like URL paths and URL fragments before tokenless entitlement metadata reaches storage;
  - entitlement runtime checks require source payment ids, optional source authorization ids and source authorization payment ids to remain canonical positive integers, require optional source authorizations to still belong to the same trusted source payment, and fail closed for restored or manually corrupted active entitlement/source-authorization expiries that are not canonical UTC timestamps before tokenless access could be granted or lifecycle state could be normalized;
  - free attachment downloads keep the existing public behavior and cache policy.
- Core paid content foundation:
  - Article/Page metadata can declare CMS-owned paid content;
  - paid content subject ids are generated only from already-canonical positive content ids, so invalid or leading-zero restored Core callers cannot mint ambiguous payment subjects through integer casts;
  - administrators can set price, currency, checkout label and preview block count in the Core content editor;
  - enabled paid content requires a canonical positive integer minor-unit price, canonical uppercase currency and an integer preview block count between 0 and 100 before Core stores the paid subject;
  - Core content saves encode paid content metadata with controlled JSON exceptions, so invalid UTF-8 checkout labels cannot turn paid metadata into empty or malformed stored JSON;
  - restored non-canonical or non-string paid content checkout labels are not trimmed or cast into visitor-facing payment labels and fall back to the Core default label;
  - restored or manually corrupted enabled paid content pricing/currency/preview limits or malformed paid-content meta JSON, including whitespace-padded numeric strings, non-string currency values or out-of-range preview counts, fails closed with Core invalid-reason metadata: Core keeps locked blocks hidden, refuses checkout, renders a locked unavailable notice instead of a submit-capable payment form, and does not silently coerce invalid prices, currencies, preview counts or unreadable meta payloads into low-value payment records or excess previews;
  - paid content checkout wall rendering also requires already-canonical integer prices and uppercase currencies before showing a submit-capable checkout form, so malformed view-model values are not cast or uppercased into visitor-facing prices;
  - paid content checkout wall rendering requires a canonical Core-local checkout route shape with a bounded positive content id before showing a submit-capable checkout form, so external, tokenized, leading-zero, oversized or whitespace-padded view-model actions stay locked;
  - front rendering trims locked blocks before theme rendering, ignores non-string public `payment_token` query parameters, marks token-bearing content responses `Cache-Control: private, no-store`, and appends a Core checkout wall;
  - public paid content checkout wall includes Core CSRF protection;
  - public paid content checkout requires string POST-body Provider selection plus bounded canonical positive content ids before default Provider lookup and payment creation, so non-string Provider inputs, leading-zero path ids or oversized path ids cannot be coerced into real paid-content subjects or expose default Provider availability;
  - hosted Provider checkout sessions can redirect to a Provider checkout URL without issuing local content authorization early;
  - hosted Provider return can sync pending/authorized Provider status before completing local content authorization once in a transaction, only after the Core payment is trusted `paid` and the return claim is valid;
  - hosted Provider return validates bounded canonical positive path ids plus string canonical non-token-like query-string `payment_key` and lowercase signed claim shape before payment status sync or authorization creation, rejects token-like or URL-decoded token-like completion keys, rejects non-string completion parameters and ignores request-body completion parameters;
  - public checkout retries reject restored or manually altered stored Provider redirect URLs that are non-string, non-canonical, non-HTTPS, contain sensitive query parameters, malformed Core signature values or token-like decoded path/query values before redirecting visitors;
  - successful Core checkout stores `paid_content` authorizations and redirects back with a tokenized content URL, while same-payment immediate checkout retries are locked and rejected before a second authorization can be minted, including after the original grant expires;
  - malformed, oversized or signed malformed-JSON public content tokens are rejected before unlock checks can expose paid blocks;
  - signed paid content token payloads are encoded and decoded with controlled JSON exceptions, must carry canonical JSON integer ids/timestamps, non-future issued-at values, requested-subject payloads, real calendar-valid canonical UTC ledger-matching expiries and canonical token secrets before authorization lookup, and the authorization path continues using those typed ids instead of recasting payload strings;
  - paid content authorization runtime checks reject restored or manually corrupted expiry rows and non-canonical use counters that would need whitespace trimming, calendar-date normalization, loose integer casting or clamping before tokenized content access could be granted;
  - paid content authorization creation is recorded as a Core authorization event;
  - paid content tokens remain valid after partial refunds while the subject still has trusted same-currency net paid state, and fail after full refunds;
  - full refunds mark active paid content authorizations and payment-backed entitlements as revoked in the Core ledger;
  - member-style entitlement checks can unlock paid content without handing tokens to the theme;
  - paid content entitlement checks fail closed for non-positive content ids before querying tokenless access;
  - tokenless entitlement access remains Core-owned at runtime and refuses non-canonical or impossible-calendar restored expiry timestamps instead of trimming or normalizing them into valid access windows;
  - themes render the Core-provided output and do not own payment truth.
- Core admin integration:
  - `/admin/payments` payment list with filters;
  - payment filters include currency and creation date windows so list, summaries and CSV exports can be reconciled without mixing currencies or reporting periods;
  - payment search, summary and CSV export filters validate string query parameters, string statuses, canonical Provider ids, canonical subject types, uppercase currencies, real calendar-valid UTC date windows, pagination, export limits and bounded display-safe canonical search text before querying, rejecting token-like and URL-decoded token-like search text so non-string, invalid, impossible-calendar, lowercase, sensitive-looking or whitespace-padded reconciliation filters are not silently normalized;
  - creation-window filters accept only exact `YYYY-MM-DD` day values or canonical UTC `YYYY-MM-DDTHH:MM:SS+00:00` timestamps; whitespace-padded or natural-language dates are rejected instead of being trimmed or interpreted;
  - payment list and CSV export requests with invalid reconciliation filters return explicit bad-request responses instead of service-failure responses;
  - payment migration indexes creation windows and currency-scoped creation windows for reconciliation queries;
  - payment list renders filtered per-currency reconciliation summaries with record count, amount, completed refunds and net amount;
  - payment list amount, status and timestamp labels require already-canonical non-negative minor-unit amounts, uppercase currencies, known Core payment statuses and canonical UTC creation timestamps, and summary totals are built from row-level validated ledger values instead of database `SUM()` coercion, so corrupted ledger rows are marked invalid on the admin page instead of being cast, uppercased, status-normalized or natural-language parsed into normal-looking reconciliation evidence;
  - `/admin/payments/export.csv` filtered payment CSV export for reconciliation with completed-refund totals and net paid amount, without raw metadata, secrets, webhook payloads or bearer tokens;
  - payment CSV export computes completed refunds and net paid amounts from row-level validated ledger values instead of database `SUM()` coercion, then validates exported Core ledger rows before writing CSV bytes or audit records, so corrupted non-canonical amounts, currencies, statuses, timestamps, token-like text fields or refund/net totals fail closed instead of being clamped, uppercased or normalized into reconciliation evidence;
  - payment CSV exports write minimized CMS audit events with filters, row count and format only, and invalid export filters are rejected before any export audit record is written;
  - `/admin/payments/providers` Provider settings page;
  - `/admin/payments/providers/save` CSRF-protected Provider setting save action;
  - Core payment admin write actions verify CSRF only from POST body fields and reject non-string payment form fields before changing Provider settings, payment lifecycle state, refunds, webhook receipt status, authorizations, entitlements or expiry-maintenance state; Provider settings, payment lifecycle, refund, webhook receipt status, authorization revoke, entitlement revoke and expiry-maintenance success/failure responses are marked `Cache-Control: private, no-store`;
  - Core payment admin path ids must be bounded canonical positive integers in the expected exact `/admin/payments/{id}/...` action route shapes before payment detail, lifecycle, refund, webhook receipt, authorization or entitlement routes can touch Core payment state, so leading-zero, oversized, misplaced or trailing-segment paths are rejected instead of being cast into valid records;
  - Provider settings save rejects non-string submitted Provider ids, statuses, public config JSON, display names, default markers and secret text, plus whitespace-padded or valid-but-formatted non-canonical public config JSON, token-like/URL-decoded token-like display names and non-scalar public config values before persisting settings or writing audit events;
  - Provider settings save rejects non-canonical secret text key/value lines before persisting settings or writing audit events instead of trimming them into accepted secrets;
  - Provider settings page shows which enabled Provider is the Core default and exposes a CSRF-protected default checkbox;
  - `/admin/payments/providers` and Provider settings save success/failure responses are marked `Cache-Control: private, no-store` because they expose Core payment configuration diagnostics and secret masks;
  - Provider settings page renders Core diagnostics for registration, enablement, checkout capability, status/refund support and webhook secret readiness;
  - Provider settings page renders Core diagnostics for hosted redirect checkout configuration, including missing, non-string, non-HTTPS, non-canonical, userinfo-bearing, fragment-bearing, sensitive-query or token-like decoded checkout/return URLs;
  - Provider settings save rejects non-canonical submitted Provider ids before persisting settings or writing audit;
  - Provider settings save rejects non-canonical submitted statuses before persisting settings or writing audit;
  - Provider settings save rejects enabled hosted redirect configurations with missing, non-string, non-HTTPS, non-canonical, userinfo-bearing, fragment-bearing, sensitive-query or token-like decoded checkout/return URLs before persisting them;
  - Provider settings persistence and official ledger import reject non-boolean default markers before writing Core Provider settings, while the Provider settings page displays manually corrupted legacy default markers as invalid instead of showing them as active defaults;
  - Provider settings page also displays imported hosted redirect default markers with unusable checkout configuration as invalid, matching public checkout fallback behavior;
  - Provider settings page survives imported or legacy Provider display names, public JSON, unsafe public config and secret ciphertext corruption by showing unavailable placeholders or registered Core Provider-name fallbacks and keeping diagnostics visible without rendering raw secret-like public fields, token-like display names or token-like public values, including URL-decoded token-like values;
  - Provider settings page rejects imported public config JSON, valid-but-formatted public config JSON variants, scalar values or malformed/non-absolute public URL values that would need repair before rendering them as usable Core configuration;
  - Payment runtime fails closed before calling Providers for payment creation or lifecycle actions when Core Provider public configuration JSON is unreadable, unsafe or encrypted secret configuration cannot be decrypted or revalidated, and it does not pass corrupted, token-like or URL-decoded token-like restored Provider display names into Provider commands;
  - Public checkout Provider selection now only exposes enabled Core Providers that support `payment.create`, and ignores any enabled/default Provider that cannot create payments;
  - Public checkout Provider selection and service-layer Provider calls also filter Core Providers whose stored public checkout configuration is unreadable, non-canonical, unsafe or unusable, including secret-like public fields, hosted redirect rows without already-canonical safe HTTPS checkout/return URLs or hosted redirect rows with userinfo, fragments, sensitive public URL query parameters or token-like decoded public URL path/query values;
  - Core Provider settings repository and admin Provider settings reject attempts to designate status/refund-only Providers as the default checkout Provider;
  - Core Provider settings repository rejects attempts to designate `core.hosted-redirect` as the default checkout Provider until its checkout URL and optional return URL base are already-canonical safe HTTPS URLs without sensitive public query parameters;
  - `/admin/payments/{id}` payment detail responses are marked `Cache-Control: private, no-store`;
  - payment detail renders the subject-level trusted paid/refunded/net state scoped to the payment currency;
  - payment detail redacts token-like or URL-decoded token-like restored top-level ledger text such as remote references and idempotency keys, plus scalar, array-shaped and nested URL query secrets, before rendering, so manually corrupted rows cannot leak through the main detail table;
  - payment detail renders a dedicated manual confirmation summary for manual/offline payment records;
  - payment detail refund, webhook receipt, authorization and entitlement row ids are rendered through the same canonical positive-integer display boundary, and webhook status or access revoke action URLs are emitted only for canonical active/actionable row ids;
  - payment detail authorization records without exposing bearer tokens or integer-casting non-canonical restored usage counters into normal-looking grant evidence;
  - payment detail authorization event history without exposing token hashes or integer-casting non-canonical restored event/authorization ids into normal-looking audit relationships;
  - payment detail entitlement records without exposing bearer tokens;
  - CSRF-protected authorization revoke action that revalidates restored authorization payment ids before revoking access or writing audit/event rows, with authorization state, authorization events and audit committed atomically;
  - CSRF-protected entitlement revoke action that invalidates tokenless access and commits entitlement state with its audit record atomically;
  - CSRF-protected expired authorization marking action with audit logging committed atomically with authorization state and authorization-event changes;
  - CSRF-protected expired entitlement marking action with audit logging committed atomically with entitlement state changes;
  - CSRF-protected capture, cancel and Provider status sync actions with Provider ledger state, service audit and admin audit committed atomically;
  - CSRF-protected webhook receipt processed/ignored action with audit logging committed atomically with the receipt state change;
  - webhook receipt status action rejects receipts from another Provider or bound payment context, and revalidates restored receipt payment ids before changing receipt state or writing audit rows;
  - payment detail shows failed webhook diagnostics only when they are bounded display-safe summaries, without exposing raw payloads, bearer tokens, URL-decoded token-like values, secrets or signatures;
  - payment detail shows canonical Webhook receipt trace summaries without exposing raw payloads, raw source IPs, raw JSON diagnostics, whitespace-normalized trace metadata, leading-zero timestamp metadata or integer-cast payload-size metadata;
  - `/admin/payments/{id}/refund` CSRF-protected refund action with refund ledger, parent payment state, Provider refund audit and admin audit committed atomically;
  - admin refund action rejects non-string, non-integer or non-canonical amount submissions before writing refunds or audit records;
  - admin capture/cancel/refund actions reject non-string or non-canonical idempotency keys before Provider lifecycle changes or audit records;
  - payment entry in the CMS admin sidebar and dashboard.
- Core official export integration:
  - `payments/payment-ledger.json` is included in official CMS export packages;
  - export package checksums include the Core payment ledger payload;
  - export package reader can load JSON payloads only after validating their manifest checksums, including the Core payment ledger;
  - export package reader rejects malformed manifest JSON before trusting package metadata, and rejects malformed Core payment ledger payload JSON even when its checksum matches the manifest;
  - export package reader exposes a dedicated Core payment ledger reader that validates required ledger sections before future migration/import consumers use the data;
  - Core payment ledger payloads carry native-string `schema_version` and canonical UTC `exported_at`, and unsupported, non-string or array-shaped ledger schema versions plus non-canonical export timestamps are rejected by the reader before preflight/restore consumers use the payload;
  - Core payment ledger payloads carry per-section canonical `counts`, and the reader rejects non-canonical count values or mismatches between the counts summary and ledger rows before integer coercion;
  - official export manifests expose a Core payment ledger payload summary with canonical string type, schema version, export time and counts for preflight checks, and array-shaped summary type values are rejected before string coercion;
  - the Core payment ledger reader rejects mismatches between the manifest payload summary and the ledger body;
  - Core payment ledger importer restores checksum-verified official export packages into Core payment tables in dependency-safe order and skips already-present rows on repeated imports;
  - Core payment ledger importer rejects missing/non-array required sections, rows missing required Core columns and rows carrying unexpected columns before writing restore rows;
  - Core payment ledger importer rejects unknown Core payment/refund/webhook/authorization/entitlement statuses and invalid, scalar, whitespace-padded or otherwise non-canonical JSON columns through controlled JSON exceptions before restoring rows;
  - Core payment ledger importer rejects restored payment metadata that is not already redacted, contains nested values, carries token-like values including URL-decoded token-like values under ordinary keys, carries sensitive, token-like or non-canonical URL values, contains non-canonical manual payment references/instructions or contains non-canonical/token-like webhook failure diagnostics before restoring rows;
  - Core payment ledger importer requires non-integer ledger identity, state, reference, hash, timestamp and JSON columns to be native strings before canonical validation, so numeric or boolean restored values cannot be string-cast into trusted subject ids, Provider ids, remote references, statuses, hashes or metadata;
  - Core payment ledger importer requires restored status values to already be exact lowercase Core state-machine values during restore and rejects case-normalized or whitespace-padded status values;
  - Core payment ledger importer validates integer ledger columns as already-canonical PHP integers or bounded canonical decimal strings, rejecting whitespace-padded, signed, leading-zero, oversized, non-positive amount/id values or negative authorization usage counts before restoring rows and before PHP integer coercion;
  - Core payment ledger importer validates authorization usage counters against runtime semantics before restore, rejecting used counts above finite max uses, used authorizations without `last_used_at` and unused authorizations that carry `last_used_at`;
  - Core payment ledger importer validates authorization event history against restored authorization state before commit, requiring exactly one `created` event, matching `consumed` event counts to `used_count`, and rejecting active/revoked/expired authorizations whose terminal events contradict their status;
  - Core payment ledger importer rejects restored payment/refund currencies that are not already canonical uppercase three-letter values before restoring rows;
  - Core payment ledger importer rejects ambiguous restored Provider defaults before commit and rolls back Provider setting rows rather than importing multiple enabled defaults; Provider default marker validation also fails closed on malformed restored public config JSON instead of skipping the row during default counting;
  - Core runtime and ledger importer validate restored Provider IDs, subject/principal type fields, subject/principal IDs, remote references, idempotency keys, token hashes and webhook event IDs with Core display-safety, non-canonical whitespace rejection and token-like/URL-decoded token-like rejection before trusting or restoring rows; restored Provider IDs and type fields must already be lowercase canonical values and are not accepted by trimming or lowercasing during restore;
  - Core payment ledger importer validates restored SHA-256 hash columns and rejects uppercase or whitespace-padded non-canonical hashes before restoring rows;
  - Core payment ledger importer validates restored Provider display names as bounded, non-whitespace-padded, non-token-like display-safe text before restoring rows;
  - Core payment ledger importer validates restored Provider public config with the same Core rules used by Provider settings persistence/runtime, rejecting secret-like keys, token-like public values including URL-decoded token-like values, unsafe keys, unsafe or whitespace-padded non-canonical string values, malformed/non-absolute public URL values, public URL values with sensitive query parameters, token-like decoded public URL path/query values and non-boolean default markers before restoring rows;
  - Core payment ledger importer validates restored Provider secret ciphertext is a plausible, already canonical Core encrypted payload before restoring rows without decrypting, trimming or exposing secret values;
  - Core payment ledger importer validates restored timestamp columns before restoring rows so broken, whitespace-padded, local-format or natural-language dates cannot poison reconciliation windows, receipt history or expiry maintenance; restored timestamps must already be canonical UTC `YYYY-MM-DDTHH:MM:SS+00:00` values rather than merely `strtotime()`-compatible text;
  - Core payment ledger importer rejects restored payment rows whose lifecycle timestamp columns do not match the trusted state, such as paid-like payments without `paid_at`, pending payments with lifecycle timestamps, authorized payments without `authorized_at`, or failed/cancelled payments carrying contradictory paid/terminal timestamps;
  - Core payment ledger importer rejects refund and webhook receipt rows whose status-specific timestamp columns do not match the restored state, such as completed refunds without `completed_at`, pending refunds with terminal timestamps, terminal webhook receipts without `processed_at` or non-terminal receipts with `processed_at`;
  - Core payment ledger importer rejects restored timestamp inversions before commit, including lifecycle timestamps before row creation, `updated_at` before `created_at`, webhook `processed_at` before `received_at`, and authorization event timestamps outside their source authorization window;
  - Core payment ledger importer rejects restored webhook trace metadata whose `payload_size`, `content_type`, `webhook_timestamp` or `source_ip_hash` is not already canonical, or whose `content_type` carries token-like/URL-decoded token-like text, before restore commit;
  - Core payment ledger importer validates completed refund totals against each source payment from row-level canonical integer values before commit, rejecting completed refunds that exceed the captured amount or parent payment statuses that do not match the completed-refund total without relying on database `SUM()` coercion;
  - Core payment ledger importer validates cross-table restore relationships before commit, rejecting refunds, webhook receipts, authorizations, authorization events or entitlements that point at missing payments/authorizations, whose Provider/currency binding no longer matches the source payment, whose paid subject no longer matches the source payment, whose authorization payment ids are restored as non-canonical values that would require integer casting before event or entitlement relationship checks, whose active access state references any source payment other than `paid` or `partially_refunded`, whose active authorization or entitlement expiry is already stale, whose active entitlement references an inactive or expired source authorization, whose active/revoked authorization or entitlement status does not match its `revoked_at` state, whose restored authorizations duplicate one payment subject, or whose restored entitlements duplicate one source payment/principal/subject grant;
  - Core payment ledger importer accepts only Core authorization event types `created`, `consumed`, `revoked` and `expired` during restore;
  - Core payment ledger importer rejects restored refund reasons that would need whitespace trimming before restore, matching the runtime webhook refund boundary;
  - official export package generation fails closed when Core payment ledger tables cannot be read, so a backup or migration package cannot silently replace missing/corrupted payment storage with an empty trusted ledger;
  - official export package generation fails closed with Core export errors when payment metadata or Provider public configuration columns are not JSON objects, so numeric, non-empty list-shaped or malformed JSON cannot be sanitized into empty trusted restore objects or leak through PHP JSON encoding failures;
  - official export package generation fetches ledger rows as associative columns only and re-redacts payment, refund, authorization, authorization-event and entitlement metadata before writing the zip payload, so legacy or manually corrupted rows cannot leak duplicate numeric-column metadata, unsafe metadata keys, control-character metadata values, private contact fields, bearer tokens, URL fragments, token-like values including URL-decoded token-like values, key-like metadata such as `auth`, `api_key` or `access_key`, array-shaped URL query values, token-like URL path/query values, malformed URL metadata or sensitive URL claims, and the exported ledger remains acceptable to the Core restore importer;
  - official export package generation also sanitizes restored or manually corrupted Provider public configuration before writing `payments/payment-ledger.json`, preserving safe scalar fields and boolean `default_provider` markers while dropping secret-like keys, non-scalar values, non-string URL fields, token-like values, unsafe URLs, sensitive URL query/path values, userinfo-bearing URLs and fragments so recovery packages do not spread legacy Provider credentials;
  - `php cli.php preflight-payment-ledger <official-export-zip>` verifies the ledger and returns package hash, manifest summary and counts without opening a database connection or writing data;
  - CLI payment ledger preflight and restore sanitize unsafe package names before returning JSON or writing audit context, so control-character filenames, bearer-token-like filenames or URL-encoded token-like filenames are not reflected back to operators;
  - failed CLI payment ledger preflight returns machine-readable JSON with sanitized package name, package hash and safe error text instead of emitting PHP fatal diagnostics, including missing package arguments or unsafe-named package paths;
  - `php cli.php import-payment-ledger <official-export-zip>` exposes the same verified, idempotent Core payment ledger restoration path for recovery operations without loading plugin runtime, and returns the sanitized package name plus package SHA-256 in machine-readable success/failure JSON, including missing package arguments or unsafe-named package paths before configuration loading or database access;
  - CLI ledger restoration commits imported rows and the minimized success audit event atomically, rolling back restored ledger rows when success audit persistence fails;
  - CLI ledger restoration writes a minimized Core audit event with package name, package hash and section import/skip counts;
  - failed CLI ledger restoration returns machine-readable JSON and writes a minimized failure audit event with package name, package hash and safe error context; if the failure audit itself cannot be persisted, the CLI still returns sanitized machine-readable JSON with a safe audit failure summary instead of emitting raw PHP diagnostics;
  - Core payment ledger importer sanitizes restore failure diagnostics, including duplicate-conflict and malformed-row errors, so control characters, token-like credential text or database driver messages from a bad package are not reflected into CLI output or failure audit context;
  - repeated ledger restoration skips only rows that already exist with identical Core column values; duplicate keys with changed ledger data are rejected and rolled back;
  - the ledger restoration path is covered on SQLite-compatible test databases and real MySQL/MariaDB acceptance, including repeated-import skip behavior and conflicting duplicate rejection;
  - the ledger payload exports Core-owned payment, refund, webhook receipt, authorization, authorization event, Provider setting and entitlement rows without raw bearer tokens or raw webhook payloads.
- Core public checkout integration:
  - `/paid-content/{content_id}/checkout` requires POST + CSRF before Provider selection or payment creation;
  - `/paid-download/{content_id}/{media_id}/checkout` requires POST + CSRF before Provider selection or payment creation;
  - public checkout CSRF and submitted Provider selection are accepted only from POST body fields, not from URL query parameters;
  - public checkout submitted Provider ids must already be canonical, so whitespace-padded Provider selections are rejected before payment creation;
  - submitted `provider_id` values must be registered and enabled in Core before checkout can create a payment;
  - paid content/download token TTL and paid download max-use configuration must be canonical integer values inside Core bounds, and invalid or whitespace-padded settings fail before payment creation;
  - Provider-returned checkout URLs that are non-HTTPS, whitespace-padded non-canonical or carry secret-like query parameters, malformed Core signature values or token-like decoded path/query values are rejected before payment rows or local authorization tokens are created;
  - stored Provider checkout URLs are rechecked for HTTPS, secret-like query parameters, malformed Core signature values and token-like decoded path/query values before public paid content/download redirects visitors, so corrupted or restored metadata cannot bypass the create-time guard;
  - pending/authorized hosted Provider checkout responses redirect to the Provider checkout URL without minting Core bearer tokens;
  - Core-signed hosted Provider return URLs are supplied to Provider command metadata and redacted before being stored in payment metadata;
  - hosted Provider return/cancel URL values must already be canonical Core-local completion or content route shapes before Core sends them to external checkout pages;
  - hosted Provider command subjects, idempotency keys, remote references, amounts, currencies and checkout signing secrets must already be canonical, display-safe and free of token-like URL-decoded text before Core builds the external checkout URL or status result;
  - hosted Provider return URLs can sync Provider status and mint Core content/download tokens only once and only after the payment is trusted `paid`, using transaction-scoped payment locking where supported;
  - paid content/download completion endpoints accept only GET returns and read string completion credentials only from the query string; non-string, non-GET, body-only or body-overridden parameters fail before status sync, authorization creation or token minting;
  - malformed, non-canonical, token-like or URL-decoded token-like hosted return `payment_key` values and malformed claim parameters, including whitespace-padded keys or uppercase claims, fail before status sync, authorization creation or token minting;
  - paid Provider checkout responses redirect to Core tokenized access URLs with `Cache-Control: private, no-store`;
  - pending manual/offline Provider checkout responses render `Cache-Control: private, no-store` Core pending-confirmation pages and do not mint Core bearer tokens before administrator confirmation;
  - pending confirmation pages do not trim unsafe references or instructions into displayable text, and they refuse tokenized, external, protocol-relative, whitespace-padded, userinfo-bearing, fragment-bearing or non-canonical pending action/content links before rendering;
  - manual/offline completion links do not unlock access before administrator capture, then redirect to Core tokenized access URLs with `Cache-Control: private, no-store` after trusted paid state;
  - paid content/download completion uses the Core trusted net paid calculation, not a brittle exact payment status string, before minting authorization tokens.
- Core provider webhook endpoint:
  - `/payment/webhooks/{provider_id}` is owned by CMS Core;
  - Provider must be registered in Core and enabled in Core Provider settings before a webhook receipt can be stored;
  - requests must include string canonical timestamp, signature and event id headers;
  - signature timestamps and webhook receipt trace timestamps must be canonical Unix-second values without leading zeroes before signature comparison or receipt storage;
  - signature headers must be 64-character HMAC-SHA256 hex values before signature comparison or receipt storage;
  - missing, oversized, control-character or non-canonical whitespace-padded event ids are rejected before a webhook receipt is stored;
  - if a payload declares an event id, it must be string, canonical and match the event id header before a webhook receipt is stored;
  - non-JSON or whitespace-padded non-canonical content types are rejected before a webhook receipt is stored when a content type is supplied;
  - raw webhook payloads must be strings, non-canonical `CONTENT_LENGTH` values, declared/body length mismatches and oversized declared/body webhook payloads are rejected before a webhook receipt is stored, and webhook payload-limit configuration must be a canonical integer within Core bounds before it can widen the Core default limit; malformed, whitespace-padded or out-of-range configured limits fail closed instead of defaulting or being clamped;
  - empty or malformed JSON payloads are rejected before a webhook receipt is stored;
  - signatures use HMAC-SHA256 over the raw payload with the Core-managed Provider `webhook_secret`;
  - unreadable Provider webhook secret ciphertext is rejected before a webhook receipt is stored;
  - service-level webhook receipt/application calls and raw webhook endpoint paths reject non-canonical or URL-encoded Provider ids before storing receipts or applying Provider events;
  - stale requests and invalid signatures fail closed;
  - duplicate provider events return the original receipt instead of creating new trust records;
  - signed provider-neutral payloads with string `provider_payment_id` and `status` can update trusted Core payment state and mark the receipt processed, while non-string status or reference fields fail the receipt without updating trusted payment state; public webhook responses stay `Cache-Control: private, no-store` across success, duplicate, validation failure and method rejection paths;
  - signed provider-neutral refund payloads can create Core refund records, update parent payment refund state and remain idempotent on duplicate events;
  - signed Provider payloads with control characters in payment or refund references fail closed before binding payment/refund ledger rows;
  - signed Provider payloads with whitespace-padded or case-normalized event types fail closed before Core writes refund rows or changes parent payment state;
  - signed refund payloads with non-integer or non-canonical amount values fail closed before Core writes refund rows or changes parent payment state;
  - signed refund payload reasons supplied by Providers must already be canonical display-safe text and are not accepted by trimming whitespace before Core writes refund rows;
  - signed refund payloads also revalidate the source payment ledger amount and currency before writing refund rows or changing parent payment state, so corrupted source ledger values cannot be loosely cast or uppercased during webhook application;
  - webhook refund/status side effects are applied atomically, so a later failed transition cannot leave a refund row or application audit behind;
  - webhook refund events do not let a stale Provider `payment_status=paid` overwrite Core's refund-derived parent payment state;
  - signed non-payment webhook events are marked `ignored` and audited atomically without requiring plugin code or raw payload storage;
  - failed webhook receipts can be replayed by the Provider with the same event payload and become processed without retaining stale failure diagnostics;
  - already processed or ignored webhook receipts are terminal and duplicate deliveries do not re-apply payment/refund side effects;
  - administrative webhook receipt status changes cannot rewrite terminal processed/ignored receipts into a different terminal state, and the repository failure path cannot overwrite ignored receipts with failed state;
  - signed provider-neutral status and refund payloads bind their receipts to the resolved Core payment so payment detail pages stay scoped;
  - signed events that pass intake but fail Core application are retained as failed receipts with a safe error summary;
  - receipt metadata stores Core-computed payload size, canonical non-token-like content type, webhook timestamp and hashed source IP for traceability without storing raw source IPs or raw payloads, and non-IP or non-canonical source addresses, caller-supplied payload sizes, token-like content types or malformed trace fields are omitted or rejected instead of being trimmed, hashed, redacted into structured trace fields or cast into trace metadata.
- Core admin payment detail re-redacts stored top-level ledger text, payment metadata, authorization-event metadata, legacy webhook trace metadata and legacy webhook failure diagnostics immediately before rendering, labels corrupted authorization, entitlement, webhook or authorization-event statuses plus corrupted refund, authorization, entitlement, authorization-event or webhook row timestamps as invalid instead of rendering them as payment evidence, and hides webhook status actions for corrupted current states, so older or manually altered Core rows cannot expose raw private contact fields, bearer tokens, token-like values including URL-decoded token-like values, key-like credentials, signatures, leading-zero timestamp traces, URL fragments, token-like URL path/query values, sensitive URL claims, natural-language timestamp claims or non-Core access/webhook lifecycle states in the dashboard.
- Core non-production fixture provider registration for deterministic development and acceptance, while still rejecting non-canonical command scenarios, amounts, currencies, unsafe references and token-like URL-decoded command strings before reference generation.
- Core production-safe manual Provider registration for operator-confirmed/offline settlement.
- Core production-safe hosted redirect Provider registration for third-party checkout redirects that settle through Core webhooks.
- Core paid checkout and authorization checks reject missing, non-string or non-canonical payment token signing keys instead of casting them or using a fixed fallback.
- Core authorization creation commits the authorization row and its `created` event atomically when no outer transaction exists, and immediate paid content/download checkout locks the source payment before checking for existing same-payment authorizations, so public paid checkout cannot return bearer-token access backed by orphaned, partially recorded or duplicate same-payment authorization state, even after the original grant expires or is exhausted.
- Core authorization consumption, single revoke, payment-wide revoke and expiry maintenance also commit their state mutation together with the corresponding authorization event when no outer transaction exists, so direct media access, refund revocation and maintenance paths cannot leave eventless lifecycle state behind.
- Core payment status updates commit the payment row and full-refund access revocation together when no outer transaction exists, so repository-level refunded transitions cannot leave a fully refunded payment with still-active authorization or entitlement access after a later revoke failure.
- Core CLI maintenance command: `php cli.php expire-payment-authorizations [limit]`.
- Core CLI maintenance command: `php cli.php expire-payment-entitlements [limit]`.
- Core CLI payment authorization and entitlement expiry commands commit expiry state, authorization event history and minimized `cli` audit events atomically, return the applied limit alongside the expired-row count in success JSON, and reject non-positive, out-of-bounds or non-canonical limit arguments before site configuration/database loading instead of trimming or clamping them.
- Core full-refund access revocation records authorization revoke events and a minimized `payment.access.revoked_after_refund` audit event.
- Core migration: `2026_08_20_000001_core_payment_schema.php`.
- Core tables:
  - `cms_payments`;
  - `cms_payment_refunds`;
  - `cms_payment_webhook_receipts`.
  - `cms_payment_authorizations`.
  - `cms_payment_authorization_events`.
  - `cms_payment_provider_settings`.
  - `cms_payment_entitlements`.
- ADR: `docs/adr/0202-core-payment-foundation.md`.
- Regression test: `tests/core_payment_foundation.php`.
- Real MySQL/MariaDB acceptance test: `tests/core_payment_mysql_acceptance.php`.

## Verification

Commands run with explicit timeout where appropriate:

```sh
php -l system/core/Payment/PaymentException.php
php -l system/core/Payment/PaymentResult.php
php -l system/core/Payment/PaymentProviderInterface.php
php -l system/core/Payment/PaymentProviderRegistry.php
php -l system/core/Payment/PaymentProviderSettingsRepository.php
php -l system/core/Payment/PaymentProviderSelector.php
php -l system/core/Payment/ManualPaymentProvider.php
php -l system/core/Payment/FixturePaymentProvider.php
php -l system/core/Payment/PaymentRepository.php
php -l system/core/Payment/PaymentService.php
php -l system/core/Payment/PaymentEntitlementService.php
php -l system/core/Payment/PaidContentService.php
php -l system/core/Payment/PaidContentController.php
php -l system/core/Payment/PaidDownloadService.php
php -l system/core/Payment/PaidDownloadController.php
php -l system/core/Payment/PaymentWebhookController.php
php -l system/core/Bootstrap/Application.php
php -l system/core/Content/ContentFrontController.php
php -l system/core/Content/ContentRepository.php
php -l system/core/Admin/AdminController.php
php -l system/core/Media/MediaController.php
php -l system/migrations/2026_08_20_000001_core_payment_schema.php
php -l tests/core_payment_foundation.php
php -l tests/core_payment_mysql_acceptance.php
perl -e 'alarm shift; exec @ARGV' 120 php tests/core_payment_foundation.php
perl -e 'alarm shift; exec @ARGV' 180 php tests/core_payment_mysql_acceptance.php
perl -e 'alarm shift; exec @ARGV' 240 php tests/run.php
```

Results:

- `tests/core_payment_foundation.php`: passed after the hosted redirect sensitive-query hardening, Provider-returned checkout URL validation, atomic webhook application hardening, entitlement expiry maintenance, public paid-token shape hardening, signed malformed-JSON paid-token rejection, paid content metadata JSON encode failure hardening, paid attachment metadata JSON encode failure hardening, malformed paid-content meta JSON fail-closed protection, malformed paid-download block JSON fail-closed protection, public checkout path-id preflight before default Provider lookup, hosted return parameter canonicalization and query-only completion parameter handling, Provider remote reference canonicalization, official export legacy-metadata redaction, key-like metadata/value redaction, restored token-like metadata value rejection, admin payment detail display redaction, admin webhook trace timestamp display hardening, unsafe legacy Provider public-config runtime/display rejection, token-like Provider public-config value rejection, malformed Provider public-config restore rejection, malformed Provider default-marker JSON rejection, Provider settings JSON encode/decode failure hardening, admin Provider public-config formatted JSON rejection, ambiguous restored Provider default rejection, ambiguous runtime default Provider rejection, authorization-created event atomic rollback, authorization lifecycle event atomic rollback, full-refund status/access rollback, token-like restore failure diagnostic sanitization, CLI payment-ledger package-name sanitization, CLI missing-package JSON hardening, CLI omitted-package-argument hardening, CLI missing-package pre-config hardening, CLI restore success/failure package context output, CLI restore failure-audit fallback JSON hardening, CLI maintenance success limit output, CLI maintenance invalid/out-of-bounds/leading-zero-limit pre-config hardening, unsafe successful restore audit package-name sanitization, preflight failure JSON hardening, CLI maintenance failure diagnostic sanitization and webhook failure diagnostic token/secret-like sanitization.
- `tests/run.php`: passed after Core manifest regeneration for the latest Core payment hardening.
- `git status --short -- content/plugins`: empty; this workstream did not modify plugin files.
- `tests/core_payment_mysql_acceptance.php`: passed in the earlier Core payment batch against real local MySQL/MariaDB. The latest rerun attempts after the hosted redirect sensitive-query, checkout URL, webhook event-id canonicalization, public paid-token shape hardening, signed malformed-JSON paid-token rejection, paid content metadata JSON encode failure hardening, paid attachment metadata JSON encode failure hardening, malformed paid-content meta JSON fail-closed protection, malformed paid-download block JSON fail-closed protection, public checkout path-id preflight before default Provider lookup, hosted return parameter canonicalization, Provider remote reference canonicalization, official export legacy-metadata redaction, admin payment detail display redaction, admin webhook trace timestamp display hardening, unsafe legacy Provider public-config runtime/display rejection, malformed Provider public-config/default-marker JSON rejection, Provider settings JSON encode/decode failure hardening, admin Provider public-config formatted JSON rejection, ambiguous restored Provider default rejection, ambiguous runtime default Provider rejection, CLI missing-package JSON hardening, CLI omitted-package-argument hardening, CLI missing-package pre-config hardening, CLI restore success/failure package context output, CLI restore failure-audit fallback JSON hardening, CLI maintenance success limit output, CLI maintenance invalid/out-of-bounds/leading-zero-limit pre-config hardening and unsafe successful restore audit package-name sanitization were blocked by local sandbox/database permissions (`Operation not permitted`), so it was not rerun to completion for these final deltas.

## Next Core Work

- Add concrete online PSP settlement adapters once specific payment platforms are selected; the production-safe `core.manual-payment` and generic `core.hosted-redirect` baselines are already in Core.
