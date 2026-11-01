# Plugin data scope/order index maintenance candidate

Core 1.2.92 prepares PR80's payment-save repair and the Owner-specified novel shelf indexing debt. Merge and exact-source isolated Gate preparation are authorized; publication, live schema/configuration/data changes, and novel plugin release are not.

## Scope and index contract

The debt comes from the novel operations handoff `TECH_DEBT_2026-11-01.md`: the shared `cms_plugin_data` table had no index matching `PluginDataStore::all()`'s `WHERE plugin_id=? AND data_type=? ORDER BY id DESC`. The novel shelf repeats this query for book, chapter, workflow and publication data. The operator already added `idx_plugin_data_scope_id(plugin_id,data_type,id DESC)` on that production site; this task never changes that site.

Add the new Core migration `2026_11_01_000001_plugin_data_scope_index`. Do not rewrite any published migration. A matching pre-existing index is adopted without DROP or rebuild. SQLite validates the table, three columns, direction, binary collations, non-unique and non-partial definition. MySQL validates full-column non-unique visible/unignored BTREE keys; MySQL5.7's stored ASC representation also supports reverse scanning with the equality prefix. A conflicting same-name index fails closed and remains untouched.

The migration has no destructive down operation. Upgrade rollback uses the native pre-upgrade database backup, preserving pre-existing indexes and all data. No plugin migration writes a Core table, and no runtime index creation or query/API change is introduced.

## Acceptance

`tests/plugin_data_scope_index.php` runs a synthetic 32-book/12,800-chapter, 38,434-row fixture. It verifies native migration history/idempotency, existing-index adoption, payload and newest-duplicate/order preservation, plugin scope, index query plan, wrong-definition rejection, and unique/partial rejection on SQLite. Timings are observations, not portable thresholds.

Server SQLite/PHP8.3/PHP8.5, private MySQL, unchanged novel shelf/detail/read/publication, native upgrade/rollback and exact merged Release Gate evidence are pending. A local migration test is not production or full-plugin acceptance. Further batching in the independent novel plugin is considered only if the indexed existing implementation still misses acceptance; no plugin source belongs in the Core package.
