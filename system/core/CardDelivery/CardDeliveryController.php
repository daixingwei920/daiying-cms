<?php

declare(strict_types=1);

namespace Cms\Core\CardDelivery;

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Payment\PaymentException;
use Cms\Core\Payment\PaymentProviderSelector;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;
use Throwable;

final class CardDeliveryController
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function checkout(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return Response::text('购买请求必须通过 POST 提交。', 405)->withHeaders(['Allow' => 'POST']);
        }
        $parts = explode('/', trim($request->path, '/'));
        $id = (int) ($parts[1] ?? 0);
        if (!CsrfToken::verify($request->body['_csrf'] ?? null)) {
            return Response::text('CSRF 校验失败，请刷新页面重试。', 403)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        try {
            if ($id <= 0) {
                throw new CardDeliveryException('Card product is not available.');
            }
            $pdo = ConnectionFactory::make($this->settings);
            $result = (new CardDeliveryService($pdo, $this->settings))->checkout(
                $id,
                $this->bodyString($request, 'provider_id'),
                'card-delivery-' . $id . '-' . bin2hex(random_bytes(8)),
                $this->bodyInt($request, 'quantity', 1),
            );

            if (($result['provider_redirect'] ?? false) === true && is_string($result['checkout_url'] ?? null)) {
                return Response::redirect((string) $result['checkout_url']);
            }
            if (($result['pending_confirmation'] ?? false) === true) {
                return $this->pendingResponse($result);
            }

            return $this->deliveryResponse($result);
        } catch (PaymentException|CardDeliveryException $exception) {
            return Response::html(View::page('自动发卡', '<h1>自动发卡</h1><p class="error">' . View::escape($this->publicErrorMessage($exception)) . '</p>'), 400)
                ->withHeaders(['Cache-Control' => 'private, no-store']);
        } catch (Throwable) {
            return Response::text('自动发卡暂不可用，请稍后重试或联系网站管理员。', 503)->withHeaders(['Cache-Control' => 'private, no-store']);
        }
    }

    public function complete(Request $request): Response
    {
        if ($request->method !== 'GET') {
            return Response::text('发卡完成页只能通过 GET 访问。', 405)->withHeaders(['Allow' => 'GET']);
        }
        $orderId = $this->orderPathId($request->path);
        try {
            if ($orderId <= 0) {
                throw new CardDeliveryException('Card order is not available.');
            }
            $result = (new CardDeliveryService(ConnectionFactory::make($this->settings), $this->settings))->completeHostedCheckout(
                $orderId,
                $this->queryString($request, 'payment_key'),
                $this->queryString($request, 'claim'),
            );

            return $this->deliveryResponse($result);
        } catch (PaymentException|CardDeliveryException $exception) {
            return Response::html(View::page('自动发卡', '<h1>自动发卡</h1><p class="error">' . View::escape($this->publicErrorMessage($exception)) . '</p>'), 400)
                ->withHeaders(['Cache-Control' => 'private, no-store']);
        } catch (Throwable) {
            return Response::text('自动发卡暂不可用，请稍后重试或联系网站管理员。', 503)->withHeaders(['Cache-Control' => 'private, no-store']);
        }
    }

    private function orderPathId(string $path): int
    {
        if (preg_match('#^/card-delivery/orders/([1-9][0-9]{0,17})/complete$#', $path, $matches) !== 1) {
            return 0;
        }
        $id = (int) $matches[1];

        return $id > 0 && (string) $id === (string) $matches[1] ? $id : 0;
    }

    private function publicErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof PaymentException) {
            $message = $exception->getMessage();
            if (str_contains($message, 'No enabled payment provider')
                || str_contains($message, 'Payment provider is not enabled')
                || str_contains($message, 'Payment provider checkout configuration is unavailable')
                || str_contains($message, 'Payment provider is not available')
            ) {
                return '当前没有可用的支付方式，请联系网站管理员。';
            }

            return '支付暂不可用，请稍后重试或联系网站管理员。';
        }

        return $exception->getMessage();
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

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query[$key] ?? '';
        if (!is_string($value)) {
            throw new PaymentException('Payment completion parameter is invalid.');
        }

        return $value;
    }

    private function bodyInt(Request $request, string $key, int $default): int
    {
        if (!array_key_exists($key, $request->body)) {
            return $default;
        }
        $value = $request->body[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,2}$/', $value) === 1) {
            return (int) $value;
        }

        throw new CardDeliveryException('Card order quantity is invalid.');
    }

    /** @param array<string,mixed> $result */
    private function deliveryResponse(array $result): Response
    {
        $product = is_array($result['product'] ?? null) ? $result['product'] : [];
        $order = is_array($result['order'] ?? null) ? $result['order'] : [];
        $delivery = is_array($result['delivery'] ?? null) ? $result['delivery'] : [];
        $items = is_array($delivery['items'] ?? null) ? $delivery['items'] : [];
        $secret = (string) ($delivery['secret'] ?? '');
        $status = (string) ($delivery['status'] ?? '');
        $title = $status === 'delivered' ? '发卡成功' : '等待人工处理';
        $body = '<h1>' . $title . '</h1><table><tbody>' .
            '<tr><th>商品</th><td>' . View::escape((string) ($product['name'] ?? $order['product_name'] ?? '')) . '</td></tr>' .
            '<tr><th>订单</th><td>' . View::escape((string) ($order['id'] ?? '')) . '</td></tr>' .
            '<tr><th>数量</th><td>' . View::escape((string) ($order['quantity'] ?? (count($items) > 0 ? count($items) : 1))) . '</td></tr>' .
            '<tr><th>状态</th><td>' . View::escape($status !== '' ? $status : (string) ($order['status'] ?? '')) . '</td></tr></tbody></table>';
        $secretLines = [];
        foreach ($items as $item) {
            if (is_array($item) && (string) ($item['status'] ?? '') === 'delivered') {
                $itemSecret = (string) ($item['secret'] ?? '');
                if ($itemSecret !== '' && $itemSecret !== '[encrypted]') {
                    $secretLines[] = $itemSecret;
                }
            }
        }
        if ($secretLines === [] && $status === 'delivered' && $secret !== '' && $secret !== '[encrypted]') {
            $secretLines[] = $secret;
        }
        if ($secretLines !== []) {
            $body .= '<h2>卡密</h2><pre>' . View::escape(implode("\n", $secretLines)) . '</pre><p class="muted">请立即妥善保存，后台默认只显示掩码。</p>';
        } else {
            $body .= '<p class="muted">支付已确认，但库存暂不可用，订单已进入人工处理队列。</p>';
        }

        return Response::html(View::page($title, $body), $status === 'delivered' ? 200 : 202)
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    /** @param array<string,mixed> $result */
    private function pendingResponse(array $result): Response
    {
        $payment = is_array($result['payment'] ?? null) ? $result['payment'] : [];
        $order = is_array($result['order'] ?? null) ? $result['order'] : [];
        $reference = $this->safeLine((string) ($payment['remote_id'] ?? ''), 191);
        $instructions = $this->safeText((string) ($result['instructions'] ?? ''), 4096);
        $completionUrl = $this->safeCompletionPath((string) ($result['completion_url'] ?? ''));
        $html = '<h1>等待支付确认</h1><p>发卡订单 #' . View::escape((string) ($order['id'] ?? '')) . ' 已经创建，管理员确认到账后会自动发卡。</p>' .
            ($reference !== '' ? '<p><strong>支付参考号</strong><br>' . View::escape($reference) . '</p>' : '') .
            ($instructions !== '' ? '<p><strong>付款说明</strong><br>' . nl2br(View::escape($instructions), false) . '</p>' : '') .
            '<p class="muted">支付确认前不会展示卡密。</p>' .
            ($completionUrl !== '' ? '<p><a class="button" href="' . View::escape($completionUrl) . '">检查确认状态</a></p>' : '');

        return Response::html(View::page('等待支付确认', $html), 202)
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function safeText(string $value, int $maxBytes): string
    {
        if ($value === '' || $value !== trim($value) || strlen($value) > $maxBytes || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return '';
        }

        return $value;
    }

    private function safeLine(string $value, int $maxBytes): string
    {
        if ($value === '' || $value !== trim($value) || strlen($value) > $maxBytes || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return '';
        }

        return $value;
    }

    private function safeCompletionPath(string $path): string
    {
        if ($path === '' || $path !== trim($path) || strlen($path) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '';
        }
        if (preg_match('#^/card-delivery/orders/[1-9][0-9]{0,17}/complete\?payment_key=[A-Za-z0-9._~-]{1,191}&claim=[a-f0-9]{64}$#', $path) === 1) {
            return $path;
        }

        return '';
    }
}
