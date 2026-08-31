<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use Cms\Core\Config\Settings;
use PDO;
use Throwable;

final class PaidDownloadService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Settings $settings,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function configFor(int $contentId, int $mediaId): ?array
    {
        if ($contentId <= 0 || $mediaId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare("SELECT id, title, blocks_json FROM cms_contents WHERE id = :id AND status = 'published' LIMIT 1");
        $stmt->execute([':id' => $contentId]);
        $content = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($content)) {
            return null;
        }

        foreach ($this->contentBlocks((string) $content['blocks_json']) as $block) {
            if (!is_array($block) || (string) ($block['type'] ?? '') !== 'attachment') {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $blockMediaId = $this->canonicalBlockMediaId($data['media_id'] ?? null);
            if ($blockMediaId !== $mediaId || !($data['paid_enabled'] ?? false)) {
                continue;
            }

            $invalidReasons = [];
            $amount = $this->canonicalAmount($data['price_minor'] ?? null);
            if ($amount <= 0) {
                $invalidReasons[] = 'amount_minor';
            }
            $currency = $this->configString($data, 'currency', 'USD');
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $invalidReasons[] = 'currency';
                $currency = '';
            }
            $available = $invalidReasons === [];

            $label = $this->displayLabel($data['payment_label'] ?? '', '解锁下载');

            return [
                'content_id' => $contentId,
                'content_title' => (string) $content['title'],
                'media_id' => $mediaId,
                'subject_type' => 'paid_download',
                'subject_id' => $this->subjectId($contentId, $mediaId),
                'amount_minor' => $amount,
                'currency' => $currency,
                'label' => $label,
                'available' => $available,
                'invalid_reasons' => $invalidReasons,
            ];
        }

        return null;
    }

    public function requiresAuthorizationForMedia(int $mediaId): bool
    {
        if ($mediaId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare("SELECT id, blocks_json FROM cms_contents WHERE status = 'published' AND blocks_json LIKE :needle");
        $stmt->execute([':needle' => '%"media_id"%']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $content) {
            $rawBlocks = (string) ($content['blocks_json'] ?? '');
            try {
                if ($this->configFor((int) $content['id'], $mediaId) !== null) {
                    return true;
                }
            } catch (PaymentException) {
                if ($this->malformedBlocksMayReferencePaidMedia($rawBlocks, $mediaId)) {
                    return true;
                }
                continue;
            }
            try {
                $blocks = $this->contentBlocks($rawBlocks);
            } catch (PaymentException) {
                if ($this->malformedBlocksMayReferencePaidMedia($rawBlocks, $mediaId)) {
                    return true;
                }
                continue;
            }
            foreach ($blocks as $block) {
                if (!is_array($block) || (string) ($block['type'] ?? '') !== 'attachment') {
                    continue;
                }
                $data = is_array($block['data'] ?? null) ? $block['data'] : [];
                if (($data['paid_enabled'] ?? false) && $this->blockMediaIdAliases($data['media_id'] ?? null, $mediaId)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<mixed> */
    private function contentBlocks(string $json): array
    {
        try {
            $blocks = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Paid download payment configuration is invalid.');
        }
        if (!is_array($blocks) || !array_is_list($blocks)) {
            throw new PaymentException('Paid download payment configuration is invalid.');
        }

        return $blocks;
    }

    private function malformedBlocksMayReferencePaidMedia(string $json, int $mediaId): bool
    {
        return str_contains($json, '"media_id"')
            && str_contains($json, (string) $mediaId)
            && str_contains($json, '"paid_enabled"');
    }

    private function canonicalBlockMediaId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/', $value) === 1) {
            $id = (int) $value;
            return (string) $id === $value ? $id : null;
        }

        return null;
    }

    private function blockMediaIdAliases(mixed $value, int $mediaId): bool
    {
        if ($this->canonicalBlockMediaId($value) === $mediaId) {
            return true;
        }
        if (!is_scalar($value) || is_bool($value)) {
            return false;
        }
        $raw = (string) $value;
        if (preg_match('/^[0-9]+/', $raw) !== 1) {
            return false;
        }

        return (int) $raw === $mediaId;
    }

    /** @return array<string,mixed> */
    public function checkout(int $contentId, int $mediaId, string $providerId, string $idempotencyKey): array
    {
        $config = $this->configFor($contentId, $mediaId);
        if ($config === null) {
            throw new PaymentException('Paid download is not available.');
        }
        if (($config['available'] ?? false) !== true) {
            throw new PaymentException('Paid download payment configuration is invalid.');
        }
        $providerId = $providerId !== ''
            ? (new PaymentProviderSelector($this->pdo, $this->settings))->requireEnabled($providerId, (string) $config['currency'])
            : (new PaymentProviderSelector($this->pdo, $this->settings))->defaultProviderId((string) $config['currency']);
        $this->secret();
        $this->tokenTtlSeconds();
        $this->tokenMaxUses();
        $successPath = '/paid-download/' . $contentId . '/' . $mediaId . '/complete';
        $claim = $this->completionClaim($contentId, $mediaId, $idempotencyKey);

        $payment = (new PaymentService($this->pdo, new PaymentRepository($this->pdo), $this->secret()))->createProviderPayment(
            (string) $config['subject_type'],
            (string) $config['subject_id'],
            $providerId,
            (int) $config['amount_minor'],
            (string) $config['currency'],
            $idempotencyKey,
            'success',
            [
                'content_id' => $contentId,
                'media_id' => $mediaId,
                'success_url' => $successPath . '?payment_key=' . rawurlencode($idempotencyKey) . '&claim=' . rawurlencode($claim),
                'cancel_url' => '/articles/' . $contentId,
            ],
        );

        if ((string) ($payment['status'] ?? '') !== 'paid') {
            $checkoutUrl = $this->providerCheckoutUrl($payment);
            if (in_array((string) ($payment['status'] ?? ''), ['pending', 'authorized'], true) && $checkoutUrl !== '') {
                return [
                    'payment' => $payment,
                    'authorization' => null,
                    'download_url' => $checkoutUrl,
                    'provider_redirect' => true,
                    'pending_confirmation' => false,
                ];
            }

            if (in_array((string) ($payment['status'] ?? ''), ['pending', 'authorized'], true)) {
                return [
                    'payment' => $payment,
                    'authorization' => null,
                    'download_url' => '/articles/' . $contentId,
                    'completion_url' => $successPath . '?payment_key=' . rawurlencode($idempotencyKey) . '&claim=' . rawurlencode($claim),
                    'provider_redirect' => false,
                    'pending_confirmation' => true,
                    'instructions' => $this->pendingInstructions($payment),
                ];
            }

            throw new PaymentException('Paid download payment was not completed.');
        }

        $authorization = $this->createFreshAuthorizationForPayment($payment, $contentId, $mediaId);

        return [
            'payment' => $payment,
            'authorization' => $authorization['record'],
            'download_url' => '/media/' . $mediaId . '?download=1&content_id=' . $contentId . '&payment_token=' . rawurlencode($authorization['token']),
            'provider_redirect' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function completeHostedCheckout(int $contentId, int $mediaId, string $idempotencyKey, string $claim): array
    {
        $idempotencyKey = $this->normalizeCompletionKey($idempotencyKey);
        $claim = $this->normalizeCompletionClaim($claim);
        $config = $this->configFor($contentId, $mediaId);
        if ($config === null) {
            throw new PaymentException('Paid download is not available.');
        }
        if (($config['available'] ?? false) !== true) {
            throw new PaymentException('Paid download payment configuration is invalid.');
        }
        if (!$this->completionClaimValid($contentId, $mediaId, $idempotencyKey, $claim)) {
            throw new PaymentException('Paid download completion claim is invalid.');
        }

        $repo = new PaymentRepository($this->pdo);
        $payment = $repo->paymentByIdempotency($idempotencyKey);
        if (is_array($payment) && in_array((string) ($payment['status'] ?? ''), ['pending', 'authorized'], true)) {
            $payment = (new PaymentService($this->pdo, $repo, $this->secret()))->syncProviderPaymentStatus((int) $payment['id']);
        }

        $this->pdo->beginTransaction();
        try {
            $payment = $repo->paymentByIdempotencyForUpdate($idempotencyKey);
            if (!is_array($payment)
                || (string) ($payment['subject_type'] ?? '') !== 'paid_download'
                || (string) ($payment['subject_id'] ?? '') !== $this->subjectId($contentId, $mediaId)
            ) {
                throw new PaymentException('Paid download payment is not complete.');
            }
            if (!in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded'], true)) {
                throw new PaymentException('Paid download payment is not complete.');
            }
            $trusted = $repo->trustedStatus('paid_download', $this->subjectId($contentId, $mediaId), (string) ($payment['currency'] ?? ''));
            if ((string) ($trusted['status'] ?? '') !== 'paid') {
                throw new PaymentException('Paid download payment is not complete.');
            }
            if ($repo->authorizationForPaymentSubject((int) $payment['id'], 'paid_download', $this->subjectId($contentId, $mediaId)) !== null) {
                throw new PaymentException('Paid download hosted checkout was already completed.');
            }

            $authorization = $this->createAuthorization($payment, $contentId, $mediaId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'payment' => $payment,
            'authorization' => $authorization['record'],
            'download_url' => '/media/' . $mediaId . '?download=1&content_id=' . $contentId . '&payment_token=' . rawurlencode($authorization['token']),
            'provider_redirect' => false,
        ];
    }

    public function isAuthorized(int $contentId, int $mediaId, string $token): bool
    {
        return $this->authorize($contentId, $mediaId, $token, false);
    }

    public function consumeAuthorization(int $contentId, int $mediaId, string $token): bool
    {
        return $this->authorize($contentId, $mediaId, $token, true);
    }

    public function isEntitled(int $contentId, int $mediaId, string $principalType, string $principalId): bool
    {
        if ($contentId <= 0 || $mediaId <= 0) {
            return false;
        }

        return (new PaymentEntitlementService($this->pdo, new PaymentRepository($this->pdo)))->isEntitled($principalType, $principalId, 'paid_download', $this->subjectId($contentId, $mediaId));
    }

    private function authorize(int $contentId, int $mediaId, string $token, bool $consume): bool
    {
        if ($contentId <= 0 || $mediaId <= 0) {
            return false;
        }

        $payload = $this->verifyToken($token);
        if ($payload === null) {
            return false;
        }
        $payloadContentId = $payload['content_id'];
        $payloadMediaId = $payload['media_id'];
        $payloadAuthorizationId = $payload['authorization_id'];
        if (!is_int($payloadContentId) || !is_int($payloadMediaId) || !is_int($payloadAuthorizationId) || $payloadContentId !== $contentId || $payloadMediaId !== $mediaId) {
            return false;
        }

        if ((string) ($payload['subject_id'] ?? '') !== $this->subjectId($contentId, $mediaId)) {
            return false;
        }

        $repo = new PaymentRepository($this->pdo);
        $authorization = $repo->authorization($payloadAuthorizationId);
        if (!is_array($authorization) || (string) ($authorization['status'] ?? '') !== 'active') {
            return false;
        }

        $secret = (string) ($payload['token_secret'] ?? '');
        if ($secret === '' || !hash_equals((string) $authorization['token_hash'], hash('sha256', $secret))) {
            return false;
        }

        if ((string) ($authorization['subject_type'] ?? '') !== 'paid_download' || (string) ($authorization['subject_id'] ?? '') !== $this->subjectId($contentId, $mediaId)) {
            return false;
        }

        if ((string) ($payload['expires_at'] ?? '') !== (string) ($authorization['expires_at'] ?? '')) {
            return false;
        }

        if (!$this->authorizationExpiryActive((string) ($authorization['expires_at'] ?? ''))) {
            return false;
        }

        $maxUses = $this->authorizationCounter($authorization['max_uses'] ?? null);
        $usedCount = $this->authorizationCounter($authorization['used_count'] ?? null);
        if ($maxUses === null || $usedCount === null) {
            return false;
        }
        if ($maxUses > 0 && $usedCount >= $maxUses) {
            return false;
        }

        $paymentId = $this->storedPositiveInt($authorization['payment_id'] ?? null);
        if ($paymentId === null) {
            return false;
        }
        $payment = $repo->payment($paymentId);
        if (!is_array($payment) || !in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded'], true)) {
            return false;
        }

        $status = $repo->trustedStatus('paid_download', $this->subjectId($contentId, $mediaId), (string) ($payment['currency'] ?? ''));
        if ((string) $status['status'] !== 'paid') {
            return false;
        }

        $authorizationId = $this->storedPositiveInt($authorization['id'] ?? null);
        if ($authorizationId === null) {
            return false;
        }

        return !$consume || $repo->consumeAuthorization($authorizationId);
    }

    public function subjectId(int $contentId, int $mediaId): string
    {
        $contentId = $this->positiveSubjectId($contentId, 'Paid download subject content id is invalid.');
        $mediaId = $this->positiveSubjectId($mediaId, 'Paid download subject media id is invalid.');

        return 'content:' . $contentId . ':media:' . $mediaId;
    }

    private function positiveSubjectId(int $value, string $message): int
    {
        if ($value <= 0) {
            throw new PaymentException($message);
        }

        return $value;
    }

    private function canonicalAmount(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    /** @param array<string,mixed> $values */
    private function configString(array $values, string $key, string $default): string
    {
        if (!array_key_exists($key, $values)) {
            return $default;
        }

        return is_string($values[$key]) ? $values[$key] : '';
    }

    private function displayLabel(mixed $label, string $fallback): string
    {
        if (!is_string($label)) {
            return $fallback;
        }
        if ($label === '') {
            return $fallback;
        }
        if ($label !== trim($label) || strlen($label) > 191 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $label) === 1) {
            return $fallback;
        }

        return $label;
    }

    private function authorizationExpiryActive(string $expiresAt): bool
    {
        if (!$this->canonicalUtcTimestamp($expiresAt)) {
            return false;
        }
        $timestamp = strtotime($expiresAt);

        return $timestamp !== false && $timestamp > time();
    }

    private function authorizationCounter(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /** @param array<string,mixed> $payment @return array{record:array<string,mixed>,token:string} */
    private function createFreshAuthorizationForPayment(array $payment, int $contentId, int $mediaId): array
    {
        $idempotencyKey = $payment['idempotency_key'] ?? null;
        if (!is_string($idempotencyKey)) {
            throw new PaymentException('Paid download payment is not complete.');
        }

        $subjectId = $this->subjectId($contentId, $mediaId);
        $repo = new PaymentRepository($this->pdo);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $lockedPayment = $repo->paymentByIdempotencyForUpdate($idempotencyKey);
            if (!is_array($lockedPayment)
                || (int) ($lockedPayment['id'] ?? 0) !== (int) ($payment['id'] ?? 0)
                || (string) ($lockedPayment['subject_type'] ?? '') !== 'paid_download'
                || (string) ($lockedPayment['subject_id'] ?? '') !== $subjectId
                || (string) ($lockedPayment['status'] ?? '') !== 'paid'
            ) {
                throw new PaymentException('Paid download payment is not complete.');
            }
            $trusted = $repo->trustedStatus('paid_download', $subjectId, (string) ($lockedPayment['currency'] ?? ''));
            if ((string) ($trusted['status'] ?? '') !== 'paid') {
                throw new PaymentException('Paid download payment is not complete.');
            }
            if ($repo->authorizationForPaymentSubject((int) $lockedPayment['id'], 'paid_download', $subjectId) !== null) {
                throw new PaymentException('Paid download checkout was already completed.');
            }

            $authorization = $this->createAuthorization($lockedPayment, $contentId, $mediaId);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $authorization;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $payment @return array{record:array<string,mixed>,token:string} */
    private function createAuthorization(array $payment, int $contentId, int $mediaId): array
    {
        $repo = new PaymentRepository($this->pdo);
        $secret = bin2hex(random_bytes(32));
        $expiresAt = gmdate('c', time() + $this->tokenTtlSeconds());
        $authorizationId = $repo->insertAuthorization([
            'payment_id' => (int) $payment['id'],
            'subject_type' => 'paid_download',
            'subject_id' => $this->subjectId($contentId, $mediaId),
            'token_hash' => hash('sha256', $secret),
            'status' => 'active',
            'max_uses' => $this->tokenMaxUses(),
            'used_count' => 0,
            'expires_at' => $expiresAt,
            'metadata' => ['content_id' => $contentId, 'media_id' => $mediaId],
        ]);
        $record = $repo->authorization($authorizationId) ?? [];

        return ['record' => $record, 'token' => $this->tokenForAuthorization($record, $secret, $contentId, $mediaId)];
    }

    /** @param array<string,mixed> $authorization */
    private function tokenForAuthorization(array $authorization, string $secret, int $contentId, int $mediaId): string
    {
        $payload = [
            'authorization_id' => (int) $authorization['id'],
            'token_secret' => $secret,
            'content_id' => $contentId,
            'media_id' => $mediaId,
            'subject_id' => $this->subjectId($contentId, $mediaId),
            'expires_at' => (string) $authorization['expires_at'],
            'issued_at' => time(),
        ];
        $json = $this->tokenPayloadJson($payload);
        $body = $this->base64Url((string) $json);
        $sig = $this->base64Url(hash_hmac('sha256', $body, $this->secret(), true));

        return $body . '.' . $sig;
    }

    /** @return array<string,mixed>|null */
    private function verifyToken(string $token): ?array
    {
        if (!$this->tokenShapeValid($token)) {
            return null;
        }

        $parts = explode('.', $token, 2);
        try {
            $expected = $this->base64Url(hash_hmac('sha256', $parts[0], $this->secret(), true));
        } catch (PaymentException) {
            return null;
        }
        if (!hash_equals($expected, $parts[1])) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if (!is_string($json)) {
            return null;
        }
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) && $this->tokenPayloadShapeValid($payload) ? $payload : null;
    }

    /** @param array<string,mixed> $payload */
    private function tokenPayloadJson(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Payment token payload is invalid.');
        }
    }

    private function tokenShapeValid(string $token): bool
    {
        if ($token === '' || strlen($token) > 1024) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_-]{16,768}\.[A-Za-z0-9_-]{32,128}$/', $token) === 1;
    }

    /** @param array<string,mixed> $payload */
    private function tokenPayloadShapeValid(array $payload): bool
    {
        if (!$this->positivePayloadInt($payload['authorization_id'] ?? null)
            || !$this->positivePayloadInt($payload['content_id'] ?? null)
            || !$this->positivePayloadInt($payload['media_id'] ?? null)
            || !$this->positivePayloadInt($payload['issued_at'] ?? null)
        ) {
            return false;
        }
        if ((int) $payload['issued_at'] > time() + 300) {
            return false;
        }

        $secret = $payload['token_secret'] ?? null;
        if (!is_string($secret) || preg_match('/^[a-f0-9]{64}$/', $secret) !== 1) {
            return false;
        }

        $subjectId = $payload['subject_id'] ?? null;
        if (!is_string($subjectId) || $subjectId === '' || $subjectId !== trim($subjectId) || strlen($subjectId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $subjectId) === 1) {
            return false;
        }

        $expiresAt = $payload['expires_at'] ?? null;
        return is_string($expiresAt)
            && $this->canonicalUtcTimestamp($expiresAt)
            && strtotime($expiresAt) !== false;
    }

    private function canonicalUtcTimestamp(string $value): bool
    {
        if ($value === '' || $value !== trim($value)) {
            return false;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\+00:00$/', $value, $matches) !== 1) {
            return false;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        $hour = (int) $matches[4];
        $minute = (int) $matches[5];
        $second = (int) $matches[6];

        return checkdate($month, $day, $year)
            && $hour >= 0 && $hour <= 23
            && $minute >= 0 && $minute <= 59
            && $second >= 0 && $second <= 59;
    }

    private function positivePayloadInt(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function storedPositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function secret(): string
    {
        $secret = $this->settings->get('security.encryption_key', '');
        if (
            !is_string($secret)
            || $secret === ''
            || $secret !== trim($secret)
            || strlen($secret) < 16
            || preg_match('/[\x00-\x1F\x7F]/', $secret) === 1
        ) {
            throw new PaymentException('Payment token signing key is not configured.');
        }

        return $secret;
    }

    private function tokenTtlSeconds(): int
    {
        return $this->boundedConfigInt(
            $this->settings->get('payment.paid_download_token_ttl_seconds', 86400),
            60,
            2592000,
            'Paid download token TTL is invalid.',
        );
    }

    private function tokenMaxUses(): int
    {
        return $this->boundedConfigInt(
            $this->settings->get('payment.paid_download_token_max_uses', 0),
            0,
            100000,
            'Paid download token max uses is invalid.',
        );
    }

    private function boundedConfigInt(mixed $value, int $min, int $max, string $message): int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1) {
            $int = (int) $value;
        } else {
            throw new PaymentException($message);
        }
        if ($int < $min || $int > $max) {
            throw new PaymentException($message);
        }

        return $int;
    }

    /** @param array<string,mixed> $payment */
    private function providerCheckoutUrl(array $payment): string
    {
        $providerId = is_string($payment['provider_id'] ?? null) ? (string) $payment['provider_id'] : '';
        $transientUrl = $payment['_provider_checkout_url'] ?? '';
        if (is_string($transientUrl) && $this->isSafeProviderRedirectUrlForProvider($providerId, $transientUrl)) {
            return $transientUrl;
        }

        $metadata = $this->paymentMetadata($payment);

        foreach (['checkout_url', 'payment_url', 'redirect_url'] as $key) {
            $url = $metadata[$key] ?? '';
            if (!is_string($url)) {
                continue;
            }
            if ($this->isSafeProviderRedirectUrlForProvider($providerId, $url)) {
                return $url;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $payment */
    private function pendingInstructions(array $payment): string
    {
        $metadata = $this->paymentMetadata($payment);

        $instructions = $metadata['manual_instructions'] ?? '';
        if (!is_string($instructions) || $instructions !== trim($instructions) || strlen($instructions) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $instructions) === 1) {
            return '';
        }

        return $instructions;
    }

    /** @param array<string,mixed> $payment @return array<string,mixed> */
    private function paymentMetadata(array $payment): array
    {
        $raw = $payment['metadata_json'] ?? '{}';
        if (!is_string($raw)) {
            return [];
        }
        try {
            $metadata = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function isSafeProviderRedirectUrlForProvider(string $providerId, string $url): bool
    {
        if ($providerId === 'official.payment.stripe' && $this->isSafeStripeCheckoutRedirectUrl($url)) {
            return true;
        }

        return $this->isSafeProviderRedirectUrl($url);
    }

    private function isSafeStripeCheckoutRedirectUrl(string $url): bool
    {
        if ($url === '' || $url !== trim($url) || strlen($url) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'checkout.stripe.com'
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }
        if (preg_match('#cs_(?:test|live)_[A-Za-z0-9_=-]+#', $url) !== 1) {
            return false;
        }
        $query = (string) ($parts['query'] ?? '');
        $fragment = (string) ($parts['fragment'] ?? '');
        if ($query !== '' && $this->providerRedirectUrlValueContainsSecret(rawurldecode($query))) {
            return false;
        }
        if ($fragment !== '' && (
            strlen($fragment) > 4096
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $fragment) === 1
            || preg_match('/^[A-Za-z0-9._~!$&\'()*+,;=:@\/?%=-]+$/', $fragment) !== 1
            || $this->providerRedirectUrlValueContainsSecret(rawurldecode($fragment))
        )) {
            return false;
        }

        return true;
    }

    private function isSafeProviderRedirectUrl(string $url): bool
    {
        if ($url === '' || $url !== trim($url) || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }
        if ($this->providerRedirectUrlValueContainsSecret(rawurldecode((string) ($parts['path'] ?? '')))) {
            return false;
        }
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            foreach ($query as $key => $value) {
                if ((string) $key !== 'cms_signature' && preg_match('/token|secret|signature|authorization|auth|key|password|private/i', (string) $key) === 1) {
                    return false;
                }
                if (!is_scalar($value)) {
                    return false;
                }
                if ((string) $key === 'cms_signature' && (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1)) {
                    return false;
                }
                if ($this->providerRedirectUrlValueContainsSecret(rawurldecode((string) $value))) {
                    return false;
                }
            }
        }

        return true;
    }

    private function providerRedirectUrlValueContainsSecret(string $value): bool
    {
        return preg_match('/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i', $value) === 1;
    }

    private function completionClaim(int $contentId, int $mediaId, string $idempotencyKey): string
    {
        return hash_hmac('sha256', 'paid_download|' . $contentId . '|' . $mediaId . '|' . $idempotencyKey, $this->secret());
    }

    private function completionClaimValid(int $contentId, int $mediaId, string $idempotencyKey, string $claim): bool
    {
        return hash_equals($this->completionClaim($contentId, $mediaId, $idempotencyKey), $claim);
    }

    private function normalizeCompletionKey(string $idempotencyKey): string
    {
        if (
            $idempotencyKey === ''
            || $idempotencyKey !== trim($idempotencyKey)
            || strlen($idempotencyKey) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey) === 1
            || $this->completionKeyContainsSecret($idempotencyKey)
        ) {
            throw new PaymentException('Paid download completion key is invalid.');
        }

        return $idempotencyKey;
    }

    private function completionKeyContainsSecret(string $value): bool
    {
        $pattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';
        $decodedValue = rawurldecode($value);

        return preg_match($pattern, $value) === 1
            || $decodedValue !== trim($decodedValue)
            || preg_match('/[\x00-\x1F\x7F]/', $decodedValue) === 1
            || preg_match($pattern, $decodedValue) === 1;
    }

    private function normalizeCompletionClaim(string $claim): string
    {
        if ($claim !== trim($claim) || $claim !== strtolower($claim)) {
            throw new PaymentException('Paid download completion claim is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $claim) !== 1) {
            throw new PaymentException('Paid download completion claim is invalid.');
        }

        return $claim;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
