# Content Foundation

Daiying CMS Core provides content safety foundations that plugins can build on without altering Core tables directly.

## Revisions

`Cms\Core\Content\ContentRevisionRepository` stores snapshots before content updates and trash operations.

Revision records preserve:

- Content ID
- Content type
- Title
- Slug
- Status
- Blocks JSON
- Meta JSON
- Actor ID
- Reason
- Created time

## Autosave

`Cms\Core\Content\ContentAutosaveRepository` stores recoverable drafts per actor. Autosaves are separate from published content and do not create public revisions until explicitly saved.

## Trash

`ContentRepository::delete()` now sends content to `trash`.

Use `ContentRepository::hardDelete()` only for explicit permanent deletion.

Use `ContentRepository::restoreFromTrash()` to restore trashed content.

## Content Type Registry

`ContentTypeRegistry` supports metadata for:

- Capabilities
- Taxonomy support
- Searchable flag
- REST exposure flag
- Revision support
- Field schema

Plugins should register new content types through `PluginContext::registerContentType()` with the `content.type` capability.

## Custom Fields

`CustomFieldRegistry` and `CustomFieldDefinition` provide a typed field foundation.

Supported field types:

- `text`
- `textarea`
- `integer`
- `decimal`
- `boolean`
- `select`
- `date`
- `datetime`
- `url`
- `media`
- `relation`

Plugins should register custom fields through `PluginContext::registerCustomField()` with the `content.field` capability.

## Search Registry

`SearchResourceRegistry` lets plugins register searchable resources such as products, novels, videos, downloads, or other extension-owned data.

Plugins need `search.register`.

## SEO Extensions

`SeoExtensionRegistry` provides extension points for:

- JSON-LD / Schema.org data
- Open Graph or Twitter/X-style meta data

Plugins need `seo.extend`.

## Redirects

`RedirectManager` records redirects through `UrlMappingRepository`, supports hit counters when the schema is available, and blocks redirect loops.
