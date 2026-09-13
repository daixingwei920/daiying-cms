<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Queue\QueueJob;
use Cms\Core\Queue\QueueService;
use Cms\Core\Security\SecretRedactor;

final class AiGateway
{
    public function __construct(
        private readonly AiService $service,
        private readonly ?AiUsageLedger $usage = null,
        private readonly ?AiQuotaService $quota = null,
        private readonly ?AuditLogger $audit = null,
        private readonly ?AiAuditLogger $aiAudit = null,
        private readonly ?QueueService $queue = null,
        private readonly ?AiJobService $jobs = null,
    ) {
    }

    public function request(AiRequest $request): AiResponse
    {
        $request = new AiRequest(
            $this->service->messagesForGateway($request->messages),
            $request->capabilities,
            $request->operation,
            $request->pluginId,
            $request->provider,
            $request->model,
            $request->options,
            $request->requestId !== '' ? $request->requestId : AiRequest::newRequestId(),
        );
        $config = $this->service->runtimeConfigForGateway($request->options);
        if (empty($config['enabled'])) {
            throw new AiException('Global AI is disabled.', 'disabled');
        }
        $providerId = AiProviderPresets::normalize($request->provider ?? (string) ($config['provider'] ?? 'openai_compatible'));
        $model = $request->model ?? (string) ($config['model'] ?? '');
        $config['provider'] = $providerId;
        if ($model !== '') {
            $config['model'] = $model;
        }
        try {
            return $this->executeWithConfig($request, $config, $providerId);
        } catch (AiException $exception) {
            $fallbackProvider = AiProviderPresets::normalize((string) ($config['fallback_provider'] ?? ''));
            if (empty($config['allow_cloud_fallback']) || $fallbackProvider === $providerId || !AiProviderPresets::isCloudProvider($fallbackProvider)) {
                throw $exception;
            }
            $fallbackConfig = AiProviderPresets::applyDefaults([
                'enabled' => true,
                'provider' => $fallbackProvider,
                'api_key' => (string) ($config['api_key'] ?? ''),
                'max_tokens' => $config['max_tokens'] ?? 1024,
                'temperature' => $config['temperature'] ?? 0.7,
                'timeout_seconds' => $config['timeout_seconds'] ?? 30,
            ]);

            return $this->executeWithConfig($request, $fallbackConfig, $fallbackProvider);
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    private function executeWithConfig(AiRequest $request, array $config, string $providerId): AiResponse
    {
        if (AiProviderPresets::requiresApiKey($providerId) && (string) ($config['api_key'] ?? '') === '') {
            throw new AiException('AI API Key is not configured.', 'api_key_missing');
        }
        $this->quota?->assertAllowed($request, $providerId, (string) $config['model']);
        $client = $this->service->providerClientForGateway($config);
        if ($client !== null) {
            try {
                $response = AiResponse::fromChatResult($client->chat($request->messages, $config), $request->requestId);
                $this->usage?->recordSuccess($request, $response);
                $this->aiAudit?->record([
                    'request_id' => $request->requestId,
                    'provider' => $response->provider,
                    'model' => $response->model,
                    'plugin_id' => $request->pluginId,
                    'action' => 'ai.request',
                    'result' => 'success',
                ]);
                return $response;
            } catch (AiException $exception) {
                $this->usage?->recordFailure($request, $providerId, (string) $config['model'], $exception->reason());
                $this->aiAudit?->record([
                    'request_id' => $request->requestId,
                    'provider' => $providerId,
                    'model' => (string) $config['model'],
                    'plugin_id' => $request->pluginId,
                    'action' => 'ai.request',
                    'result' => 'failed',
                ]);
                throw $exception;
            } catch (\Throwable $exception) {
                $safe = (string) SecretRedactor::redact($exception->getMessage());
                $this->usage?->recordFailure($request, $providerId, (string) $config['model'], 'provider_error');
                throw new AiException($safe !== '' ? 'AI provider failed safely: ' . $safe : 'AI provider failed safely.', 'provider_error');
            }
        }
        $provider = AiProviderRegistry::get($providerId);
        if ($provider === null) {
            throw new AiException('AI provider is not registered.', 'provider_not_found');
        }
        $this->assertModelCapabilities($provider, (string) $config['model'], $request->capabilities);

        try {
            $response = $provider->execute($request, $config);
            $this->usage?->recordSuccess($request, $response);
            $this->audit?->record('system', null, 'ai.request.after', [
                'request_id' => $request->requestId,
                'provider' => $response->provider,
                'model' => $response->model,
                'plugin_id' => $request->pluginId,
                'operation' => $request->operation,
                'status' => 'success',
            ]);
            $this->aiAudit?->record([
                'request_id' => $request->requestId,
                'provider' => $response->provider,
                'model' => $response->model,
                'plugin_id' => $request->pluginId,
                'action' => 'ai.request',
                'result' => 'success',
            ]);

            return $response;
        } catch (AiException $exception) {
            $this->usage?->recordFailure($request, $providerId, (string) $config['model'], $exception->reason());
            $this->audit?->record('system', null, 'ai.request.failed', [
                'request_id' => $request->requestId,
                'provider' => $providerId,
                'model' => (string) $config['model'],
                'plugin_id' => $request->pluginId,
                'operation' => $request->operation,
                'reason' => $exception->reason(),
            ]);
            $this->aiAudit?->record([
                'request_id' => $request->requestId,
                'provider' => $providerId,
                'model' => (string) $config['model'],
                'plugin_id' => $request->pluginId,
                'action' => 'ai.request',
                'result' => 'failed',
            ]);
            throw $exception;
        } catch (\Throwable $exception) {
            $safe = (string) SecretRedactor::redact($exception->getMessage());
            $this->usage?->recordFailure($request, $providerId, (string) $config['model'], 'provider_error');
            throw new AiException($safe !== '' ? 'AI provider failed safely: ' . $safe : 'AI provider failed safely.', 'provider_error');
        }
    }

    /** @param list<array{role:string,content:string}> $messages @param array<string,mixed> $options */
    public function chat(array $messages, array $options = []): array
    {
        return $this->request(AiRequest::chat($messages, $options))->toChatResult();
    }

    /** @return array{provider:string,model:string,status:string,message:string} */
    public function testConnection(): array
    {
        $config = $this->service->runtimeConfigForGateway(['max_tokens' => 16, 'temperature' => 0.0]);
        if (empty($config['enabled'])) {
            throw new AiException('Global AI is disabled.', 'disabled');
        }
        $providerId = AiProviderPresets::normalize((string) ($config['provider'] ?? 'openai_compatible'));
        if (AiProviderPresets::requiresApiKey($providerId) && (string) ($config['api_key'] ?? '') === '') {
            throw new AiException('AI API Key is not configured.', 'api_key_missing');
        }
        $client = $this->service->providerClientForGateway($config);
        if ($client !== null) {
            $response = AiResponse::fromChatResult($client->chat([['role' => 'user', 'content' => 'Reply with OK only.']], $config), AiRequest::newRequestId());

            return [
                'provider' => $response->provider,
                'model' => $response->model,
                'status' => 'success',
                'message' => '连接成功',
            ];
        }
        $provider = AiProviderRegistry::get($providerId);
        if ($provider === null) {
            throw new AiException('AI provider is not registered.', 'provider_not_found');
        }

        return $provider->testConnection($config);
    }

    /** @return list<array{id:string,label:string,capabilities:list<string>}> */
    public function detectModels(): array
    {
        $config = $this->service->runtimeConfigForGateway([]);
        if (empty($config['enabled'])) {
            throw new AiException('Global AI is disabled.', 'disabled');
        }
        $providerId = AiProviderPresets::normalize((string) ($config['provider'] ?? 'openai_compatible'));
        if (AiProviderPresets::requiresApiKey($providerId) && (string) ($config['api_key'] ?? '') === '') {
            throw new AiException('AI API Key is not configured.', 'api_key_missing');
        }
        $provider = AiProviderRegistry::get($providerId);
        if (!$provider instanceof AiModelDiscoveryInterface) {
            throw new AiException('AI provider does not support model discovery.', 'model_discovery_unsupported');
        }

        return $provider->detectModels($config);
    }

    /** @return array<string,mixed> */
    public function capabilities(): array
    {
        return $this->service->capabilities();
    }

    /** @param array<string,mixed> $options */
    public function queueChat(array $messages, array $options = []): int
    {
        if ($this->queue === null || $this->jobs === null) {
            throw new AiException('AI job queue is not available.', 'queue_unavailable');
        }
        $request = AiRequest::chat($messages, $options);
        $queueId = $this->queue->enqueue(new QueueJob('ai.request', [
            'request_id' => $request->requestId,
            'messages' => $request->messages,
            'options' => $request->options,
            'operation' => $request->operation,
            'plugin_id' => $request->pluginId,
        ], $request->pluginId));

        return $this->jobs->create($request, $queueId);
    }

    /** @param list<string> $required */
    private function assertModelCapabilities(AiProviderInterface $provider, string $modelId, array $required): void
    {
        foreach ($provider->getModels() as $model) {
            if ($model->id === $modelId || $modelId === 'custom-model') {
                if (!$model->supports($required)) {
                    throw new AiException('AI model does not support the requested capabilities.', 'capability_unsupported');
                }

                return;
            }
        }
    }
}
