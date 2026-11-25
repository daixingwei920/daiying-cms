# Core 1.2.104 preparation

Core exposes a single-task scheduler entry point. It reuses the existing enabled-state, due-time and lease checks. Existing calls that dispatch all eligible tasks behave the same. No schema migration is required.

The local novel fixture used separate writer and publication processes. The writer waited 82 seconds for a local model fixture. The two publication wakes occurred while writing was blocked, and each created exactly one receipt. Website deployment and scheduler cutover remain separate from this Core release.
