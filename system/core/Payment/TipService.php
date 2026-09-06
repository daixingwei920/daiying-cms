<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Support\CurrencyRegistry;
use Cms\Core\Support\Money;
use InvalidArgumentException;
use PDO;

final class TipService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Settings $settings,
    ) {
    }

    /** @return array<string,mixed> */
    public function checkout(int $contentId, int $blockIndex, string $amount, string $providerId, string $idempotencyKey): array
    {
        $content = $this->content($contentId);
        if ($content === null) {
            throw new PaymentException('Tip content is not available.');
        }
        $config = $this->configFor($content, $blockIndex);
        if ($config === null || ($config['available'] ?? false) !== true) {
            throw new PaymentException('Tip block is not available.');
        }

        $currency = (string) $config['currency'];
        $amountMinor = $this->tipAmountMinor($amount, $config);
        $providerId = $providerId !== ''
            ? (new PaymentProviderSelector($this->pdo, $this->settings))->requireEnabled($providerId, $currency)
            : (new PaymentProviderSelector($this->pdo, $this->settings))->defaultProviderId($currency);

        $payment = (new PaymentService($this->pdo, new PaymentRepository($this->pdo), $this->secret()))->createProviderPayment(
            'content_tip',
            $this->subjectId($contentId, $blockIndex),
            $providerId,
            $amountMinor,
            $currency,
            $idempotencyKey,
            'success',
            [
                'content_id' => $contentId,
                'block_index' => $blockIndex,
                'tip_title' => (string) $config['title'],
                'success_url' => $this->contentPath($content) . '?tip=success',
                'cancel_url' => $this->contentPath($content) . '?tip=cancelled',
            ],
        );

        $checkoutUrl = $this->providerCheckoutUrl($payment);
        if (in_array((string) ($payment['status'] ?? ''), ['pending', 'authorized'], true) && $checkoutUrl !== '') {
            return [
                'payment' => $payment,
                'content_url' => $checkoutUrl,
                'provider_redirect' => true,
                'pending_confirmation' => false,
            ];
        }

        if (in_array((string) ($payment['status'] ?? ''), ['pending', 'authorized'], true)) {
            return [
                'payment' => $payment,
                'content_url' => $this->contentPath($content),
                'provider_redirect' => false,
                'pending_confirmation' => true,
                'instructions' => $this->pendingInstructions($payment),
            ];
        }

        if ((string) ($payment['status'] ?? '') === 'paid') {
            return [
                'payment' => $payment,
                'content_url' => $this->contentPath($content) . '?tip=success',
                'provider_redirect' => false,
                'pending_confirmation' => false,
            ];
        }

        throw new PaymentException('Tip payment was not created.');
    }

    /** @param array<string,mixed> $content @return array<string,mixed>|null */
    public function configFor(array $content, int $blockIndex): ?array
    {
        if ($blockIndex < 0 || (string) ($content['status'] ?? '') !== 'published') {
            return null;
        }
        $blocks = is_array($content['blocks'] ?? null) ? array_values($content['blocks']) : [];
        $block = is_array($blocks[$blockIndex] ?? null) ? $blocks[$blockIndex] : null;
        if ($block === null || (string) ($block['type'] ?? '') !== 'tip') {
            return null;
        }

        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $currency = $this->currency((string) ($data['currency'] ?? $this->settings->get('site.default_currency', 'USD')));
        $amounts = $this->amountList($data['amounts'] ?? [], $currency);
        $customAmount = (bool) ($data['custom_amount'] ?? true);
        $available = $currency !== '' && ($amounts !== [] || $customAmount);

        return [
            'content_id' => (int) ($content['id'] ?? 0),
            'block_index' => $blockIndex,
            'title' => $this->label((string) ($data['title'] ?? '喜欢这篇内容？'), '喜欢这篇内容？'),
            'description' => $this->text((string) ($data['description'] ?? '可以打赏支持作者继续创作。')),
            'button_text' => $this->label((string) ($data['button_text'] ?? '打赏支持'), '打赏支持'),
            'currency' => $currency !== '' ? $currency : 'USD',
            'amounts' => $amounts,
            'custom_amount' => $customAmount,
            'available' => $available,
        ];
    }

    /** @param array<string,mixed> $config */
    private function tipAmountMinor(string $amount, array $config): int
    {
        $amount = $this->normalizeAmountForCompare($amount);
        if ($amount === '') {
            throw new PaymentException('Tip amount is invalid.');
        }
        $allowed = array_map(fn (string $value): string => $this->normalizeAmountForCompare($value), is_array($config['amounts'] ?? null) ? $config['amounts'] : []);
        if (!in_array($amount, $allowed, true) && ($config['custom_amount'] ?? false) !== true) {
            throw new PaymentException('Tip amount is not allowed.');
        }

        try {
            $minor = Money::toMinor($amount, (string) ($config['currency'] ?? 'USD'));
        } catch (InvalidArgumentException $exception) {
            throw new PaymentException('Tip amount is invalid: ' . $exception->getMessage(), 0, $exception);
        }
        if ($minor <= 0) {
            throw new PaymentException('Tip amount must be positive.');
        }

        return $minor;
    }

    /** @return array<string,mixed>|null */
    private function content(int $contentId): ?array
    {
        if ($contentId <= 0) {
            return null;
        }

        return (new ContentRepository($this->pdo, new ContentTypeRegistry()))->find($contentId);
    }

    /** @param array<string,mixed> $content */
    private function contentPath(array $content): string
    {
        $slug = trim((string) ($content['slug'] ?? ''), '/');
        if ($slug === '') {
            return '/';
        }

        return ((string) ($content['content_type'] ?? 'article') === 'article' ? '/articles/' : '/') . rawurlencode($slug);
    }

    private function subjectId(int $contentId, int $blockIndex): string
    {
        return 'content:' . $contentId . ':block:' . $blockIndex;
    }

    private function secret(): string
    {
        $secret = $this->settings->get('security.encryption_key', '');
        if (!is_string($secret) || $secret === '' || $secret !== trim($secret) || strlen($secret) < 16 || preg_match('/[\x00-\x1F\x7F]/', $secret) === 1) {
            throw new PaymentException('Payment token signing key is not configured.');
        }

        return $secret;
    }

    /** @param array<string,mixed> $payment */
    private function providerCheckoutUrl(array $payment): string
    {
        $url = $payment['_provider_checkout_url'] ?? '';

        return is_string($url) && $url !== '' ? $url : '';
    }

    /** @param array<string,mixed> $payment */
    private function pendingInstructions(array $payment): string
    {
        $raw = $payment['metadata_json'] ?? '{}';
        if (!is_string($raw)) {
            return '';
        }
        $metadata = json_decode($raw, true);
        $instructions = is_array($metadata) ? (string) ($metadata['manual_instructions'] ?? '') : '';
        if ($instructions === '' || $instructions !== trim($instructions) || strlen($instructions) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $instructions) === 1) {
            return '';
        }

        return $instructions;
    }

    private function currency(string $currency): string
    {
        try {
            $code = CurrencyRegistry::normalizeCode($currency);
            CurrencyRegistry::require($code);
            return $code;
        } catch (InvalidArgumentException) {
            return '';
        }
    }

    /** @return list<string> */
    private function amountList(mixed $amounts, string $currency): array
    {
        if (is_string($amounts)) {
            $amounts = preg_split('/[,\s]+/', $amounts) ?: [];
        }
        if (!is_array($amounts) || $currency === '') {
            return [];
        }
        $clean = [];
        foreach (array_slice($amounts, 0, 12) as $amount) {
            if (!is_string($amount) && !is_int($amount)) {
                continue;
            }
            $value = $this->normalizeAmountForCompare((string) $amount);
            if ($value === '') {
                continue;
            }
            try {
                if (Money::toMinor($value, $currency) > 0) {
                    $clean[$value] = $value;
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return array_values($clean);
    }

    private function normalizeAmountForCompare(string $amount): string
    {
        $value = str_replace(["\r", "\n", "\t", ' '], '', trim($amount));
        if (str_starts_with($value, '.')) {
            $value = '0' . $value;
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '0' ? '' : $value;
    }

    private function label(string $value, string $fallback): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');
        if ($value === '' || strlen($value) > 191) {
            return $fallback;
        }

        return $value;
    }

    private function text(string $value): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $value) ?? '');

        return strlen($value) > 1000 ? substr($value, 0, 1000) : $value;
    }
}
