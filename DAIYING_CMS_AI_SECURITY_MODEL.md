# Daiying CMS AI Security Model

Status: Foundation V1

## Credential Boundary

AI API keys remain encrypted in `cms_core_ai_settings.api_key_ciphertext`. Admin pages display only masked status. Plugins call the AI service and do not receive decrypted API keys through public APIs.

## Runtime Isolation

AI is optional. These states must not break normal CMS pages:

- Global AI disabled
- Missing API key
- Invalid API key
- Provider network failure
- Model not found
- Timeout
- Quota or balance errors

Failures return `AiException` with safe reason codes.

## Logging and Redaction

AI usage and audit records store provider, model, operation, plugin scope, status, and token counts. They do not store prompts by default and do not store secrets.

Provider runtime exceptions are normalized through `AiGateway` and redacted with the Core secret redactor.

## Tool Safety

AI Tool execution is guarded by:

- declared tool risk level
- required capabilities
- explicit confirmation for write/destructive/sensitive actions
- audit records for request and tool outcomes

Sensitive actions include payment settings, credentials, license issuance, user security, and destructive content operations.

## Official Update Server Isolation

`updates.daiyingcms.com` and Marketplace AI Review remain independent. They must not read site AI settings, share site API keys, or call the site AI service.

This separation prevents a customer site AI key from being used for official review, and prevents official review infrastructure from becoming a runtime dependency of installed CMS sites.
