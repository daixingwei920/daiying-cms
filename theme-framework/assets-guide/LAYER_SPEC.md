# Layer Spec

Layer order:

1. background
2. environment
3. figures
4. foreground
5. props
6. effects
7. text/signage
8. interaction

Each layer should define:

- `id`
- `type`
- `asset`
- `z_index`
- desktop position
- mobile position
- optional animation

Theme-specific layer names are allowed in final themes, but reusable framework code should keep generic roles.
