# Update System

Daiying CMS includes a signed Core update flow for installed sites.

## Official Update Server

Installed sites are configured to use:

```text
https://updates.daiyingcms.com
```

The update server is private infrastructure. Its source code, credentials, private signing keys, package storage internals, and logs must not be committed to the public CMS repository.

## Core Update Flow

Current Core update infrastructure includes:

- Update server client.
- Package manifest reader.
- Signature verifier.
- Update planner.
- Restore points.
- Atomic update state.
- Health checks.
- Rollback support.
- Cross-version update history.

## Production Notes

- Use the official public key configured in `config/app.example.php`.
- Verify package SHA-256 and signature before applying updates.
- Keep update packages out of public writable paths.
- Run readiness checks before and after major production updates.

## Release Package

The GitHub full installation package is separate from the online Core update package. GitHub Releases are for new installations and public downloads; installed sites use the official update server.
