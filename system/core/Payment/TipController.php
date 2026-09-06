<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;
use Throwable;

final class TipController
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function checkout(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return Response::text('打赏请求必须通过 POST 提交。', 405)
                ->withHeaders(['Allow' => 'POST', 'Cache-Control' => 'private, no-store']);
        }
        if (!CsrfToken::verify($request->body['_csrf'] ?? null)) {
            return Response::text('无权执行此操作。', 403)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        try {
            $result = (new TipService(ConnectionFactory::make($this->settings), $this->settings))->checkout(
                $this->positiveInt($request->body['content_id'] ?? null),
                $this->nonNegativeInt($request->body['block_index'] ?? null),
                $this->tipAmount($request),
                $this->bodyString($request, 'provider_id'),
                'content-tip-' . bin2hex(random_bytes(16)),
            );

            if (($result['pending_confirmation'] ?? false) === true) {
                return $this->pendingResponse($result);
            }

            return Response::redirect((string) ($result['content_url'] ?? '/'))
                ->withHeaders(['Cache-Control' => 'private, no-store']);
        } catch (PaymentException $exception) {
            return $this->paymentErrorResponse($exception);
        } catch (Throwable) {
            return Response::text('打赏暂不可用，请稍后重试或联系网站管理员。', 500)
                ->withHeaders(['Cache-Control' => 'private, no-store']);
        }
    }

    private function positiveInt(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int) $value;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }

        throw new PaymentException('Tip content is invalid.');
    }

    private function nonNegativeInt(mixed $value): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1) {
            return (int) $value;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        throw new PaymentException('Tip block is invalid.');
    }

    private function bodyString(Request $request, string $key): string
    {
        if (!array_key_exists($key, $request->body)) {
            return '';
        }
        $value = $request->body[$key];
        if (!is_string($value)) {
            throw new PaymentException('Tip request is invalid.');
        }

        return trim($value);
    }

    private function tipAmount(Request $request): string
    {
        $custom = $this->bodyString($request, 'custom_amount');

        return $custom !== '' ? $custom : $this->bodyString($request, 'amount');
    }

    private function paymentErrorResponse(PaymentException $exception): Response
    {
        $message = $exception->getMessage();
        if ($this->providerErrorIsPublicSafe($message)) {
            return Response::html(View::page('打赏', '<h1>打赏</h1><p class="error">当前没有可用的支付方式，请联系网站管理员。</p>'), 400)
                ->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        return Response::html(View::page('打赏', '<h1>打赏</h1><p class="error">打赏暂不可用，请稍后重试或联系网站管理员。</p>'), 400)
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function providerErrorIsPublicSafe(string $message): bool
    {
        return str_contains($message, 'No enabled payment provider')
            || str_contains($message, 'Payment provider is not enabled')
            || str_contains($message, 'Payment provider checkout configuration is unavailable')
            || str_contains($message, 'Payment provider is not available');
    }

    /** @param array<string,mixed> $result */
    private function pendingResponse(array $result): Response
    {
        $payment = is_array($result['payment'] ?? null) ? $result['payment'] : [];
        $reference = $this->safeLine((string) ($payment['remote_id'] ?? ''));
        $instructions = $this->safeText((string) ($result['instructions'] ?? ''));
        $contentUrl = $this->safePath((string) ($result['content_url'] ?? '/'));
        $html = '<h1>等待打赏确认</h1><p>打赏支付记录已经创建，管理员确认到账后会在后台标记完成。</p>' .
            ($reference !== '' ? '<p><strong>支付参考号</strong><br>' . View::escape($reference) . '</p>' : '') .
            ($instructions !== '' ? '<p><strong>付款说明</strong><br>' . nl2br(View::escape($instructions), false) . '</p>' : '') .
            '<p><a class="button" href="' . View::escape($contentUrl) . '">返回内容</a></p>';

        return Response::html(View::page('等待打赏确认', $html), 202)
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function safeLine(string $value): string
    {
        if ($value === '' || $value !== trim($value) || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return '';
        }

        return $value;
    }

    private function safeText(string $value): string
    {
        if ($value === '' || $value !== trim($value) || strlen($value) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return '';
        }

        return $value;
    }

    private function safePath(string $path): string
    {
        if ($path === '' || $path !== trim($path) || strlen($path) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '/';
        }
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        return $path;
    }
}
