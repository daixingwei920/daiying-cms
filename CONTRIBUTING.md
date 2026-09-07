# Contributing

Thank you for your interest in Daiying CMS.

## Current Contribution Scope

This repository is the public Daiying CMS Core, theme, and bundled extension repository. Contributions should stay within clearly scoped changes and should not include private infrastructure.

## Before Opening A Pull Request

1. Check the latest `main` branch.
2. Keep the change focused.
3. Do not commit secrets, databases, logs, sessions, OAuth credentials, payment keys, update signing private keys, or server credentials.
4. Add or update documentation when changing public behavior.
5. Run relevant PHP checks or tests for the changed area.
6. Include screenshots only when they show real UI from the current code.

## Areas That Need Care

- Core update logic must preserve signature, manifest, restore, health-check, and rollback safeguards.
- Plugin and theme changes must declare accurate manifests.
- Payment Provider changes must not expose Authorization headers, API keys, webhook secrets, or checkout tokens.
- Market and review changes must avoid official-only bypasses or hidden whitelists.
- Update Server source and private package infrastructure must not be included in this public repository.

## Pull Request Checklist

- [ ] The change is scoped and described clearly.
- [ ] No secrets or production data are included.
- [ ] Documentation is updated when needed.
- [ ] Relevant tests or manual checks are listed.
- [ ] Backward compatibility and migration impact are described.
