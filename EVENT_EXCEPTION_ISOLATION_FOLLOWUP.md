# Event Exception Isolation Follow-Up

Foundation V1 does not change `EventDispatcher` exception semantics.

Current behavior:

- Event dispatch is synchronous.
- Listener exceptions escape from `EventDispatcher::dispatch()`.
- Existing business flows may rely on this behavior to abort work.

Reason for deferral:

- Silently swallowing listener exceptions would be a behavior change.
- A safe implementation needs logging, per-listener attribution, and a policy for trusted Core listeners versus third-party plugin listeners.

Recommended follow-up:

1. Add listener owner metadata when plugins call `listen()`.
2. Add a plugin-listener isolation mode that catches, logs, and circuit-breaks plugin listener failures.
3. Keep Core/internal listeners fail-fast unless explicitly marked isolated.
4. Document the changed semantics in Event Registry V1.1.

