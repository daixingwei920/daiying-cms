# Daiying CMS Historical Content Quality Audit

Audit mode: production read-only.

## Summary

| Metric | Count |
| --- | ---: |
| Published articles | 61 |
| Short articles | 43 |
| Low quality articles | 37 |
| Possible duplicate articles | 0 |
| Only needs SEO / content complete | 8 |
| Suggested expansion | 16 |
| Suggested rewrite / focused expansion | 37 |
| Suggested merge / unpublish / manual review | 0 |

## Quality Distribution

| Grade | Count | Meaning |
| --- | ---: | --- |
| A | 8 | Content is complete enough; do not rewrite body by default, only fill missing SEO. |
| B | 16 | Content is usable but thin or missing helpful structure; generate an expansion draft for review. |
| C | 37 | Content is too short or information-light; generate a focused rewrite/expansion draft for review. |
| D | 0 | Duplicate or very low-value content; should enter manual merge, rewrite, unpublish, or delete review. |

## Criteria

The scan does not use fixed word count alone. It combines:

- body length
- paragraph count
- heading count
- image presence
- duplicate fingerprint
- early batch-generation signals
- SEO metadata presence
- whether the article appears to answer a real search intent

## Important Boundary

Short content is not automatically overwritten. For B/C articles, the new workflow stores an optimization draft in the historical SEO job table for administrator review.

Allowed:

- batch scan
- batch classification
- batch SEO suggestion generation
- batch draft generation

Not allowed by default:

- automatically replacing live article body
- deleting articles
- changing article URL/slug
- overwriting existing manual SEO fields

## Conclusion

The historical article set contains a real content-quality backlog. The immediate safe path is:

1. A articles: fill missing SEO only.
2. B articles: generate expansion draft, administrator reviews before publish.
3. C articles: generate focused rewrite/expansion draft, administrator reviews before publish.
4. D articles: manual decision only.
