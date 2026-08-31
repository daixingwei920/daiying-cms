<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use Cms\Core\CardDelivery\CardDeliveryRepository;
use Cms\Core\CardDelivery\CardDeliveryService;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Throwable;

final class PaymentWebhookController
{
    private const DEFAULT_MAX_PAYLOAD_BYTES = 262144;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function receive(Request $request): Response
    {
        if ($request->method !== 'POST') {
            return $this->noStore(Response::text('支付通知接口只接受 POST 请求。', 405)->withHeaders(['Allow' => 'POST']));
        }

        try {
            $providerId = $this->pathProviderId($request->path);
            $contentLengthError = $this->contentLengthError($request);
            if ($contentLengthError !== '') {
                return $this->noStore(Response::json(['ok' => false, 'error' => $contentLengthError], $contentLengthError === 'Payment webhook payload is too large.' ? 413 : 400));
            }
            $rawPayload = $this->rawPayload($request);
            $contentLengthMismatchError = $this->contentLengthMismatchError($request, $rawPayload);
            if ($contentLengthMismatchError !== '') {
                return $this->noStore(Response::json(['ok' => false, 'error' => $contentLengthMismatchError], 400));
            }
            $contentTypeError = $this->contentTypeError($request);
            if ($contentTypeError !== '') {
                return $this->noStore(Response::json(['ok' => false, 'error' => $contentTypeError], 415));
            }
            if (strlen($rawPayload) > $this->maxPayloadBytes()) {
                return $this->noStore(Response::json(['ok' => false, 'error' => 'Payment webhook payload is too large.'], 413));
            }
            $payloadError = $this->payloadJsonError($rawPayload);
            if ($payloadError !== '') {
                return $this->noStore(Response::json(['ok' => false, 'error' => $payloadError], 400));
            }

            $pdo = ConnectionFactory::make($this->settings);
            $settings = new PaymentProviderSettingsRepository($pdo, (string) $this->settings->get('security.encryption_key', ''));
            $provider = PaymentProviderRegistry::get($providerId);
            if ($provider === null) {
                throw new PaymentException('Payment webhook provider is not registered.');
            }
            $setting = $settings->setting($providerId);
            if ($setting === null || (string) ($setting['status'] ?? '') !== 'enabled') {
                throw new PaymentException('Payment webhook provider is not enabled.');
            }

            $secrets = $settings->secrets($providerId);

            $receiptMetadata = [];
            if ($provider instanceof PaymentWebhookAdapterInterface) {
                $adapted = $provider->adaptWebhook($rawPayload, $request->server, $this->publicConfig($setting), $secrets);
                $eventId = (string) ($adapted['event_id'] ?? '');
                $this->verifyEventId($eventId);
                $rawPayload = (string) ($adapted['payload'] ?? '');
                $payloadError = $this->payloadJsonError($rawPayload);
                if ($payloadError !== '') {
                    throw new PaymentException($payloadError);
                }
                $this->verifyPayloadEventId($rawPayload, $eventId);
                $receiptMetadata = is_array($adapted['metadata'] ?? null) ? $adapted['metadata'] : [];
            } else {
                $timestamp = $this->serverString($request, 'HTTP_X_CMS_PAYMENT_TIMESTAMP');
                $signature = $this->serverString($request, 'HTTP_X_CMS_PAYMENT_SIGNATURE');
                $eventId = $this->serverString($request, 'HTTP_X_CMS_PAYMENT_EVENT');
                $secret = (string) ($secrets['webhook_secret'] ?? '');
                if ($secret === '') {
                    throw new PaymentException('Payment webhook secret is not configured.');
                }
                $this->verifyEventId($eventId);
                $this->verifyPayloadEventId($rawPayload, $eventId);
                $this->verifySignature($timestamp, $signature, $rawPayload, $secret);
                $receiptMetadata = $this->receiptMetadata($request, $timestamp);
            }

            $service = new PaymentService($pdo, new PaymentRepository($pdo), (string) $this->settings->get('security.encryption_key', ''));
            $receipt = $service->recordWebhookReceipt($providerId, $eventId, $rawPayload, 'received', $receiptMetadata);
            try {
                $payment = $service->applyWebhookPaymentStatus($providerId, (int) ($receipt['id'] ?? 0), $rawPayload);
            } catch (PaymentException $exception) {
                $service->markWebhookReceiptFailed((int) ($receipt['id'] ?? 0), $exception->getMessage());
                throw $exception;
            }
            $cardOrderId = is_array($payment) ? $this->fulfillCardDeliveryPayment($pdo, $payment) : null;

            return $this->noStore(Response::json([
                'ok' => true,
                'receipt_id' => (int) ($receipt['id'] ?? 0),
                'provider_id' => $providerId,
                'event_id' => $eventId,
                'applied_payment_id' => is_array($payment) ? (int) ($payment['id'] ?? 0) : null,
                'payment_status' => is_array($payment) ? (string) ($payment['status'] ?? '') : null,
                'card_delivery_order_id' => $cardOrderId,
            ]));
        } catch (PaymentException $exception) {
            return $this->noStore(Response::json(['ok' => false, 'error' => $exception->getMessage()], 400));
        } catch (Throwable) {
            return $this->noStore(Response::json(['ok' => false, 'error' => 'Payment webhook is unavailable.'], 500));
        }
    }

    private function noStore(Response $response): Response
    {
        return $response->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    /** @param array<string,mixed> $setting @return array<string,mixed> */
    private function publicConfig(array $setting): array
    {
        $raw = (string) ($setting['public_config_json'] ?? '{}');
        if ($raw === '') {
            $raw = '{}';
        }
        if ($raw !== trim($raw)) {
            throw new PaymentException('Payment provider public config is invalid.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Payment provider public config is invalid.');
        }
        if (!is_array($decoded)) {
            throw new PaymentException('Payment provider public config is invalid.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $payment */
    private function fulfillCardDeliveryPayment(\PDO $pdo, array $payment): ?int
    {
        if ((string) ($payment['subject_type'] ?? '') !== 'card_delivery_order'
            || !in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded'], true)
            || preg_match('/^order:([1-9][0-9]{0,17})$/', (string) ($payment['subject_id'] ?? ''), $matches) !== 1
        ) {
            return null;
        }
        $orderId = (int) $matches[1];
        $repo = new CardDeliveryRepository($pdo, (string) $this->settings->get('security.encryption_key', ''));
        $order = $repo->order($orderId);
        if ($order === null) {
            return null;
        }
        $trusted = (new PaymentRepository($pdo))->trustedStatus('card_delivery_order', 'order:' . $orderId, (string) ($payment['currency'] ?? ''));
        if ((string) ($trusted['status'] ?? '') !== 'paid') {
            return null;
        }
        $repo->markOrderPaid($orderId, (int) ($payment['id'] ?? 0));
        $delivery = (new CardDeliveryService($pdo, $this->settings))->deliverPaidOrder(
            (int) $order['product_id'],
            (string) $orderId,
            (string) ($payment['remote_id'] ?? $payment['id'] ?? ''),
            (int) ($order['quantity'] ?? 1),
        );
        $repo->markOrderFulfilled(
            $orderId,
            (string) ($delivery['status'] ?? '') === 'delivered' ? 'delivered' : 'out_of_stock',
            isset($delivery['id']) ? (int) $delivery['id'] : null,
            $this->cardDeliveryDeliveryIds($delivery),
        );

        return $orderId;
    }

    /** @param array<string,mixed> $delivery @return list<int> */
    private function cardDeliveryDeliveryIds(array $delivery): array
    {
        $ids = [];
        foreach (($delivery['items'] ?? []) as $item) {
            if (is_array($item) && isset($item['id']) && (int) $item['id'] > 0) {
                $ids[] = (int) $item['id'];
            }
        }
        if ($ids === [] && isset($delivery['id']) && (int) $delivery['id'] > 0) {
            $ids[] = (int) $delivery['id'];
        }

        return $ids;
    }

    private function verifyEventId(string $eventId): void
    {
        if (
            $eventId === ''
            || $eventId !== trim($eventId)
            || strlen($eventId) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $eventId) === 1
            || $this->eventIdContainsSecret($eventId)
        ) {
            throw new PaymentException('Payment webhook event id is invalid.');
        }
    }

    private function eventIdContainsSecret(string $eventId): bool
    {
        $pattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';
        $decodedEventId = rawurldecode($eventId);

        return preg_match($pattern, $eventId) === 1
            || $decodedEventId !== trim($decodedEventId)
            || preg_match('/[\x00-\x1F\x7F]/', $decodedEventId) === 1
            || preg_match($pattern, $decodedEventId) === 1;
    }

    private function verifyPayloadEventId(string $rawPayload, string $headerEventId): void
    {
        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Payment webhook payload must be a JSON object.');
        }
        if (!is_array($payload)) {
            throw new PaymentException('Payment webhook payload must be a JSON object.');
        }

        $nestedEvent = is_array($payload['event'] ?? null) ? $payload['event'] : [];
        $rawPayloadEventId = $payload['event_id'] ?? $payload['id'] ?? $nestedEvent['id'] ?? null;
        if ($rawPayloadEventId === null || $rawPayloadEventId === '') {
            return;
        }
        if (!is_string($rawPayloadEventId)) {
            throw new PaymentException('Payment webhook event id is invalid.');
        }
        $payloadEventId = $rawPayloadEventId;
        $this->verifyEventId($payloadEventId);
        if (!hash_equals($headerEventId, $payloadEventId)) {
            throw new PaymentException('Payment webhook event id does not match payload.');
        }
    }

    private function verifySignature(string $timestamp, string $signature, string $rawPayload, string $secret): void
    {
        if ($timestamp === '' || preg_match('/^[1-9][0-9]{0,11}$/', $timestamp) !== 1) {
            throw new PaymentException('Payment webhook timestamp is invalid.');
        }
        if (abs(time() - (int) $timestamp) > 300) {
            throw new PaymentException('Payment webhook timestamp is outside the allowed window.');
        }
        if ($signature === '') {
            throw new PaymentException('Payment webhook signature is missing.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            throw new PaymentException('Payment webhook signature is invalid.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new PaymentException('Payment webhook signature is invalid.');
        }
    }

    private function rawPayload(Request $request): string
    {
        if (array_key_exists('RAW_BODY', $request->server)) {
            if (!is_string($request->server['RAW_BODY'])) {
                throw new PaymentException('Payment webhook payload is invalid.');
            }

            return $request->server['RAW_BODY'];
        }

        $body = file_get_contents('php://input');
        return is_string($body) ? $body : '';
    }

    private function contentTypeError(Request $request): string
    {
        $rawContentType = $this->serverString($request, 'CONTENT_TYPE');
        if ($rawContentType === '') {
            $rawContentType = $this->serverString($request, 'HTTP_CONTENT_TYPE');
        }
        if ($rawContentType !== trim($rawContentType)) {
            return 'Payment webhook content type must be JSON.';
        }
        $contentType = strtolower($rawContentType);
        if ($contentType === '') {
            return '';
        }
        $mediaType = explode(';', $contentType, 2)[0];
        if ($mediaType !== trim($mediaType)) {
            return 'Payment webhook content type must be JSON.';
        }
        if ($mediaType === 'application/json' || str_ends_with($mediaType, '+json')) {
            return '';
        }

        return 'Payment webhook content type must be JSON.';
    }

    private function contentLengthError(Request $request): string
    {
        $rawContentLength = $this->serverString($request, 'CONTENT_LENGTH');
        if ($rawContentLength === '') {
            return '';
        }
        if (preg_match('/^(0|[1-9][0-9]{0,17})$/', $rawContentLength) !== 1) {
            return 'Payment webhook content length is invalid.';
        }
        if ((int) $rawContentLength > $this->maxPayloadBytes()) {
            return 'Payment webhook payload is too large.';
        }

        return '';
    }

    private function contentLengthMismatchError(Request $request, string $rawPayload): string
    {
        $rawContentLength = $this->serverString($request, 'CONTENT_LENGTH');
        if ($rawContentLength === '') {
            return '';
        }
        if ((int) $rawContentLength !== strlen($rawPayload)) {
            return 'Payment webhook content length does not match payload.';
        }

        return '';
    }

    private function payloadJsonError(string $rawPayload): string
    {
        if (trim($rawPayload) === '') {
            return 'Payment webhook payload is empty.';
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'Payment webhook payload must be a JSON object.';
        }
        if (!is_array($payload)) {
            return 'Payment webhook payload must be a JSON object.';
        }

        return '';
    }

    private function maxPayloadBytes(): int
    {
        $missing = new \stdClass();
        $configured = $this->settings->get('payment.webhook_max_payload_bytes', $missing);
        if ($configured === $missing) {
            return self::DEFAULT_MAX_PAYLOAD_BYTES;
        }
        if (is_int($configured)) {
            $bytes = $configured;
        } elseif (is_string($configured) && preg_match('/^[1-9][0-9]{0,17}$/', $configured) === 1) {
            $bytes = (int) $configured;
        } else {
            throw new PaymentException('Payment webhook payload limit is invalid.');
        }
        if ($bytes < 1024 || $bytes > 1048576) {
            throw new PaymentException('Payment webhook payload limit is invalid.');
        }

        return $bytes;
    }

    /** @return array<string,mixed> */
    private function receiptMetadata(Request $request, string $timestamp): array
    {
        $contentType = strtolower($this->serverString($request, 'CONTENT_TYPE'));
        if ($contentType === '') {
            $contentType = strtolower($this->serverString($request, 'HTTP_CONTENT_TYPE'));
        }
        $mediaType = $contentType !== '' ? explode(';', $contentType, 2)[0] : '';
        $remoteAddr = $this->serverString($request, 'REMOTE_ADDR');
        $metadata = [
            'content_type' => $mediaType,
            'webhook_timestamp' => $timestamp,
        ];
        if ($this->remoteAddrCanonical($remoteAddr)) {
            $secret = (string) $this->settings->get('security.encryption_key', '');
            $metadata['source_ip_hash'] = $secret !== ''
                ? hash_hmac('sha256', $remoteAddr, $secret)
                : hash('sha256', $remoteAddr);
        }

        return $metadata;
    }

    private function serverString(Request $request, string $key): string
    {
        if (!array_key_exists($key, $request->server)) {
            return '';
        }
        $value = $request->server[$key];
        if (!is_string($value)) {
            throw new PaymentException('Payment webhook header is invalid.');
        }

        return $value;
    }

    private function remoteAddrCanonical(string $remoteAddr): bool
    {
        return $remoteAddr !== ''
            && $remoteAddr === trim($remoteAddr)
            && strlen($remoteAddr) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $remoteAddr) !== 1
            && filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false;
    }

    private function pathProviderId(string $path): string
    {
        if (preg_match('#^/payment/webhooks/([^/]+)$#', $path, $matches) !== 1) {
            throw new PaymentException('Payment webhook provider is invalid.');
        }

        try {
            $providerId = (string) $matches[1];
            $normalized = PaymentProviderRegistry::normalize($providerId);
            if ($providerId !== $normalized) {
                throw new PaymentException('Payment webhook provider is invalid.');
            }

            return $normalized;
        } catch (Throwable) {
            throw new PaymentException('Payment webhook provider is invalid.');
        }
    }
}
