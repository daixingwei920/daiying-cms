<?php
declare(strict_types=1);
namespace Cms\Core\Payment;
/** Optional trusted-provider contract. No browser or business plugin may assert verified dispute state. */
interface PaymentDisputeProviderInterface {
 public function isDisputeWebhook(string $rawPayload):bool;
 /** Must authenticate signature, timestamp and provider-specific channel before returning normalized facts. */
 public function verifyDisputeWebhook(string $rawPayload,array $server,array $publicConfig,array $secrets):array;
 /** True only for an authenticated, strictly ordered per-dispute sequence guaranteed by the provider. */
 public function hasReliableDisputeSequence():bool;
 /** Authoritative server lookup when sequence is unavailable. Never merely echo the callback. */
 public function fetchDisputeSnapshot(array $payment,string $disputeId,array $publicConfig,array $secrets):array;
}
