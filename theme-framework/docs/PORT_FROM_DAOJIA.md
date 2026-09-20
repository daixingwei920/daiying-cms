# Port Lessons From Daojia 1.7.11

Daojia 1.7.11 is the current frozen reference, not a base to copy.

Daojia 1.7.10 remains available only as a historical reference. Do not use 1.7.10 as a starting point because its content URL helper does not support Daiying CMS 1.2.65 list ViewModel items where the raw content record is nested under `$item['content']`.

Useful patterns to port:

- Use `TemplateContext`.
- Centralize content URL generation.
- Support both top-level `url/slug/content_type` and nested `content.url/content.slug/content.content_type`.
- Centralize item collection handling.
- Use `TemplateContext::pagination()` to normalize pager links.
- Use a logo resolver.
- Keep article detail previous/next navigation optional.
- Keep JavaScript progressive: content remains readable if scripts fail.

Do not port:

- Culture-specific symbols, names, copy, or imagery.
- Completed visual composition.
- Completed motion details.
- Theme-specific class names as framework API.

Recommended process:

1. Start from `starters/blank/`.
2. Choose a scene concept.
3. Prepare assets using `assets-guide/`.
4. Configure scene/layers/props.
5. Implement visual CSS.
6. Run release checklist.
