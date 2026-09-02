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

final class PaidContentController
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function checkout(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return Response::text('付费内容购买请求必须通过 POST 提交。', 405)
                ->withHeaders(['Allow' => 'POST', 'Cache-Control' => 'private, no-store']);
        }
        if (!CsrfToken::verify($request->body['_csrf'] ?? null)) {
            return Response::text('无权执行此操作。', 403)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        $contentId = $this->pathId($request->path);
        try {
            if ($contentId <= 0) {
                throw new PaymentException('Paid content is not available.');
            }
            $pdo = ConnectionFactory::make($this->settings);
            $result = (new PaidContentService($pdo, $this->settings))->checkout(
                $contentId,
                $this->bodyString($request, 'provider_id'),
                'paid-content-' . $contentId . '-' . bin2hex(random_bytes(8)),
            );

            if (($result['pending_confirmation'] ?? false) === true) {
                return $this->pendingResponse($result, '付费内容');
            }

            return $this->accessRedirect((string) $result['content_url']);
        } catch (PaymentException $exception) {
            return $this->paymentErrorResponse($exception, '付费内容');
        } catch (Throwable) {
            return $this->unavailableResponse();
        }
    }

    public function complete(Request $request): Response
    {
        if ($request->method !== 'GET') {
            return Response::text('付费内容完成页只能通过 GET 访问。', 405)
                ->withHeaders(['Allow' => 'GET', 'Cache-Control' => 'private, no-store']);
        }

        $contentId = $this->pathId($request->path, 'complete');
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $result = (new PaidContentService($pdo, $this->settings))->completeHostedCheckout(
                $contentId,
                $this->queryString($request, 'payment_key'),
                $this->queryString($request, 'claim'),
            );

            if (($result['pending_confirmation'] ?? false) === true) {
                return $this->pendingResponse($result, '付费内容');
            }

            return $this->accessRedirect((string) $result['content_url']);
        } catch (PaymentException $exception) {
            return $this->paymentErrorResponse($exception, '付费内容');
        } catch (Throwable) {
            return $this->unavailableResponse();
        }
    }

    private function pathId(string $path, string $action = 'checkout'): int
    {
        if (preg_match('#^/paid-content/([1-9][0-9]{0,17})/' . preg_quote($action, '#') . '$#', $path, $matches) !== 1) {
            return 0;
        }

        return $this->canonicalPathId((string) $matches[1]);
    }

    private function canonicalPathId(string $value): int
    {
        $id = (int) $value;

        return $id > 0 && (string) $id === $value ? $id : 0;
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query[$key] ?? '';
        if (!is_string($value)) {
            throw new PaymentException('Payment completion parameter is invalid.');
        }

        return $value;
    }

    private function bodyString(Request $request, string $key): string
    {
        if (!array_key_exists($key, $request->body)) {
            return '';
        }
        $value = $request->body[$key];
        if (!is_string($value)) {
            throw new PaymentException('Payment provider is invalid.');
        }

        return $value;
    }

    private function accessRedirect(string $location): Response
    {
        $response = Response::redirect($location);

        return preg_match('/(?:^|[?&])payment_token=/', $location) === 1
            ? $response->withHeaders(['Cache-Control' => 'private, no-store'])
            : $response;
    }

    private function paymentErrorResponse(PaymentException $exception, string $title): Response
    {
        $message = $exception->getMessage();
        if (!$this->providerErrorIsPublicSafe($message)) {
            return Response::text($message, 400)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        return Response::html(View::page($title, '<h1>' . View::escape($title) . '</h1><p class="error">当前没有可用的支付方式，请联系网站管理员。</p>'), 400)
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function providerErrorIsPublicSafe(string $message): bool
    {
        return str_contains($message, 'No enabled payment provider')
            || str_contains($message, 'Payment provider is not enabled')
            || str_contains($message, 'Payment provider checkout configuration is unavailable')
            || str_contains($message, 'Payment provider is not available');
    }

    private function unavailableResponse(): Response
    {
        return Response::text('付费内容暂不可用，请稍后重试或联系网站管理员。', 500)
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    /** @param array<string,mixed> $result */
    private function pendingResponse(array $result, string $title): Response
    {
        $payment = is_array($result['payment'] ?? null) ? $result['payment'] : [];
        $reference = $this->safePendingLine((string) ($payment['remote_id'] ?? ''), 191);
        $instructions = $this->safePendingText((string) ($result['instructions'] ?? ''), 4096);
        $contentUrl = $this->safePendingPath((string) ($result['content_url'] ?? ''));
        $completionUrl = $this->safePendingPath((string) ($result['completion_url'] ?? ''));
        $html = '<h1>等待支付确认</h1><p>' . View::escape($title) . '已经创建支付记录，管理员确认到账后会解锁访问。</p>' .
            ($reference !== '' ? '<p><strong>支付参考号</strong><br>' . View::escape($reference) . '</p>' : '') .
            ($instructions !== '' ? '<p><strong>付款说明</strong><br>' . nl2br(View::escape($instructions), false) . '</p>' : '') .
            '<p class="muted">此页面不会发放访问 Token；支付确认前内容仍由 CMS Core 锁定。</p>' .
            ($completionUrl !== '' ? '<p><a class="button" href="' . View::escape($completionUrl) . '">检查确认状态</a></p>' : '') .
            '<p><a class="button" href="' . View::escape($contentUrl !== '' ? $contentUrl : '/') . '">返回内容</a></p>';

        return Response::html(View::page('等待支付确认', $html), 202)
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function safePendingText(string $value, int $maxBytes): string
    {
        if ($value === '' || $value !== trim($value) || strlen($value) > $maxBytes || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return '';
        }

        return $value;
    }

    private function safePendingLine(string $value, int $maxBytes): string
    {
        if ($value === '' || $value !== trim($value) || strlen($value) > $maxBytes || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return '';
        }

        return $value;
    }

    private function safePendingPath(string $path): string
    {
        if ($path === '' || $path !== trim($path) || strlen($path) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '';
        }
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '';
        }
        if (preg_match('/(?:^|[?&])payment_token=/', $path) === 1) {
            return '';
        }
        if (preg_match('#^/(?:articles/[a-z0-9][a-z0-9-]{0,190}|[a-z0-9][a-z0-9-]{0,190})$#', $path) === 1) {
            return $path;
        }
        if (preg_match('#^/paid-content/[1-9][0-9]{0,17}/complete\?payment_key=[A-Za-z0-9._~-]{1,191}&claim=[a-f0-9]{64}$#', $path) === 1) {
            return $path;
        }

        return '';
    }
}
