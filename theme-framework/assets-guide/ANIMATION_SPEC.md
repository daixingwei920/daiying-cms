# Animation Spec

Animations must be optional and resilient.

## Transition Presets

- `scroll-open`: reveal content through scroll or button activation.
- `light-reveal`: fade and glow reveal.
- `split-open`: two-part opening effect.
- `none`: static hero.

Avoid hardcoding one theme's cultural object into the transition system.

## Accessibility

- Respect `prefers-reduced-motion`.
- Keep interactive controls keyboard reachable.
- Do not block article reading if JavaScript fails.
