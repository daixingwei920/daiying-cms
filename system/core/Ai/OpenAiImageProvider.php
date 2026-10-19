<?php

declare(strict_types=1);
namespace Cms\Core\Ai;

final class OpenAiImageProvider implements AiImageProviderInterface
{
    public function __construct(private readonly ?\Closure $transport = null) {}

    public function models(): array
    {
        return [new AiModel('gpt-image-1.5', 'GPT Image 1.5', ['image.generate', 'image.edit'], metadata: [
            'provider' => 'openai', 'protocol_verified' => 'official_documentation',
            'account_verified' => false, 'output_formats' => ['png'], 'transparent' => true,
            'source' => 'https://developers.openai.com/api/reference/resources/images/methods/generate',
        ])];
    }

    public function assertSupported(array $config): void
    {
        if (($config['provider'] ?? '') !== 'openai' || rtrim((string) ($config['base_url'] ?? ''), '/') !== 'https://api.openai.com/v1'
            || ($config['image_model'] ?? '') !== 'gpt-image-1.5') {
            throw new AiException('当前服务商、端点或图像模型未经支持验证。请在 Core AI 设置中选择已声明的图像模型。', 'image_model_unsupported');
        }
    }

    public function execute(array $request, array $config, ?string $inputPng = null): array
    {
        $this->assertSupported($config);
        $edit = $request['operation'] === 'image.edit';
        if ($edit && $inputPng === null) throw new AiException('编辑需要原图。', 'image_input_missing');
        $payload = ['model' => $config['image_model'], 'prompt' => $request['prompt'], 'n' => 1,
            'size' => $request['size'], 'quality' => $request['quality'], 'background' => $request['background'], 'output_format' => 'png'];
        $headers = ['Authorization: Bearer ' . (string) $config['api_key']];
        $temp = null;
        try {
            if ($edit) {
                $temp = tempnam(sys_get_temp_dir(), 'cms-edit-');
                if ($temp === false || file_put_contents($temp, $inputPng) !== strlen($inputPng)) throw new AiException('无法暂存编辑原图。', 'image_storage_failed');
                chmod($temp, 0600);
                $payload['image'] = new \CURLFile($temp, 'image/png', 'input.png');
                $body = $payload;
            } else {
                $headers[] = 'Content-Type: application/json';
                $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
            $url = 'https://api.openai.com/v1/images/' . ($edit ? 'edits' : 'generations');
            // No retry: transport ambiguity must never silently duplicate a billable call.
            if ($this->transport !== null) {
                $response = ($this->transport)($url, $headers, $body, (int) $config['timeout_seconds']);
            } else {
                $response = AiImageHttpTransport::post($url, $headers, $body, (int) $config['timeout_seconds']);
            }
            if ($response['status'] !== 200) {
                // 5xx can follow successful upstream work; fail closed instead of charging twice.
                $reason = $response['status'] >= 500 ? 'image_result_unknown' : 'image_provider_rejected';
                throw new AiException('图像服务返回 HTTP ' . (int) $response['status'] . '。', $reason);
            }
            $json = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
            $png = base64_decode((string) ($json['data'][0]['b64_json'] ?? ''), true);
            $dimensions = is_string($png) ? @getimagesizefromstring($png) : false;
            if (!is_string($png) || strlen($png) > 28 * 1024 * 1024 || !str_starts_with($png, "\x89PNG\r\n\x1a\n")
                || $dimensions === false || $dimensions[0] * $dimensions[1] > 20000000) throw new AiException('服务商未返回有效 PNG。', 'image_result_unknown');
            return ['png' => $png, 'usage' => is_array($json['usage'] ?? null) ? $json['usage'] : []];
        } catch (AiException $e) { throw $e; }
        catch (\Throwable) { throw new AiException('图像请求未能确认结果。', 'image_result_unknown'); }
        finally { if (is_string($temp) && is_file($temp)) unlink($temp); }
    }
}
