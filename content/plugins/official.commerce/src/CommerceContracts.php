<?php

declare(strict_types=1);

namespace Daiying\Commerce;

use Throwable;

interface CommerceAiModuleInterface
{
    public function moduleId(): string;

    /** @return list<string> */
    public function capabilities(): array;

    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function analyzeProduct(array $product, array $context = []): array;

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function analyzeOrder(array $order, array $context = []): array;
}

interface CommerceDistributionInterface
{
    public function providerId(): string;

    /**
     * @param array<string,mixed> $productSnapshot
     * @param array<string,mixed> $pricingSnapshot
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function publishProduct(array $productSnapshot, array $pricingSnapshot, array $context = []): array;

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function syncListing(string $externalListingId, array $context = []): array;

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function unpublishProduct(string $externalListingId, array $context = []): array;
}

interface CommerceLogisticsProviderInterface
{
    public function providerId(): string;

    public function normalizeStatus(string $rawStatus): string;

    public function trackingUrl(string $carrier, string $trackingNumber): ?string;

    /**
     * @param array<string,mixed> $orderSnapshot
     * @param array<string,mixed> $context
     * @return list<array<string,mixed>>
     */
    public function pullEvents(array $orderSnapshot, array $context = []): array;
}

interface CommerceVerificationProviderInterface
{
    public function providerId(): string;

    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $context
     * @return array{status:string,source_url?:string,checked_facts?:array<string,mixed>,raw_evidence?:array<string,mixed>,failure_reason?:string,provider?:string,record_type?:string}
     */
    public function verifySource(array $product, array $context = []): array;
}

final class CommerceProviderIsolation
{
    /**
     * @param callable():mixed $operation
     * @return array<string,mixed>
     */
    public static function capture(string $providerId, string $operationName, callable $operation): array
    {
        try {
            return [
                'ok' => true,
                'provider' => $providerId,
                'operation' => $operationName,
                'result' => $operation(),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'provider' => $providerId,
                'operation' => $operationName,
                'error_class' => $exception::class,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
