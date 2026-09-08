# Daiying CMS AI Tool and Agent Spec

Status: Foundation V1

AI tools and agents are extension contracts. Core provides registries and guardrails, not business agents.

## Tool Registry

Register tools with:

- `AiToolDefinition`
- `AiToolRegistry::register()`
- `PluginContext::registerAiTool()`

Tool IDs should be stable namespaced IDs, for example:

- `content.search`
- `content.read`
- `media.read`
- `commerce.product.search`
- `commerce.order.read`

## Risk Levels

Risk levels are fixed in `AiToolRisk`:

- `READ`: read-only operations
- `WRITE`: creates or updates CMS state
- `DESTRUCTIVE`: deletes or irreversibly changes data
- `SENSITIVE`: touches credentials, payments, authorization, security, or private data

`WRITE`, `DESTRUCTIVE`, and `SENSITIVE` tools require explicit confirmation through `AiToolService`.

## Permissions

Tools declare required capabilities. The caller must provide granted capabilities when executing a tool. Missing capability returns `permission_denied`.

AI must not receive unlimited administrator authority. Agents call tools; tools enforce permission and confirmation.

## Agent Registry

Agents are described through `AiAgentDefinition` and registered through `AiAgentRegistry` or `PluginContext::registerAiAgent()`.

V1 stores:

- `id`
- `name`
- `description`
- `required_capabilities`
- `allowed_tools`
- `owner_plugin`

Actual agent workflows belong to plugins or feature modules.

## Prompt Registry

Prompts use `AiPromptTemplate`, `AiPromptRegistry`, and optional persistence through `AiPromptRepository`.

Prompt records include:

- `prompt_id`
- `version`
- `variables`
- `owner_plugin`
- `language`
- `template`

Prompt templates are versioned so future prompt upgrades do not silently change old plugin behavior.
