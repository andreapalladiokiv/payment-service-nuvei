<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook;

use Override;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Webhook\Contract\InboundWebhook;

/**
 * Nuvei checksum verification. Pure protocol: the caller passes a single
 * candidate credential; we verify that (a) the request's merchant pair matches
 * this credential and (b) the checksum validates against `secretKey`.
 *
 * Nuvei ships two shapes; we handle both:
 *
 *   - **DMN** (form-encoded body, no `EventCorrelationId`): checksum field in
 *     the body, computed as
 *     `sha256(secretKey + totalAmount + currency + responseTimeStamp
 *             + PPP_TransactionID + Status + productId)`.
 *
 *   - **Notification** (`EventCorrelationId` in the body, `checksum` in a
 *     header): computed as `sha256(secretKey + rawBody)`.
 *
 * Multi-tenant iteration over candidate credentials is the caller's job.
 */
final readonly class ChecksumVerifier implements SignatureVerifier
{
    #[Override]
    public function verify(InboundWebhook $webhook, GatewayCredential $gateway): bool
    {
        $payload = $webhook->fields();

        // Nuvei sends the merchant pair under two different shapes depending
        // on the delivery channel: camelCase in JSON Notifications and
        // snake_case in form-encoded DMN posts.
        $merchantId = (string) ($payload['merchantId'] ?? $payload['merchant_id'] ?? '');
        $merchantSiteId = (string) ($payload['merchantSiteId'] ?? $payload['merchant_site_id'] ?? '');
        if ($merchantId === '' || $merchantSiteId === '') {
            return false;
        }

        $credentials = $gateway->getCredentials();
        if (($credentials['merchant_id'] ?? null) !== $merchantId
            || ($credentials['site_id'] ?? null) !== $merchantSiteId) {
            return false;
        }

        $secret = $credentials['secret_key'] ?? null;
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        if (isset($payload['EventCorrelationId'])) {
            return $this->verifyNotification($webhook, $secret);
        }

        return $this->verifyDmn($payload, $secret);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function verifyDmn(array $payload, string $secret): bool
    {
        $expected = hash('sha256', implode('', [
            $secret,
            (string) ($payload['totalAmount'] ?? ''),
            (string) ($payload['currency'] ?? ''),
            (string) ($payload['responseTimeStamp'] ?? ''),
            (string) ($payload['PPP_TransactionID'] ?? ''),
            (string) ($payload['Status'] ?? ''),
            (string) ($payload['productId'] ?? ''),
        ]));

        $received = strtolower((string) ($payload['advanceResponseChecksum'] ?? $payload['responseChecksum'] ?? ''));

        return hash_equals($expected, $received);
    }

    private function verifyNotification(InboundWebhook $webhook, string $secret): bool
    {
        $received = strtolower($webhook->header('checksum'));
        if ($received === '') {
            return false;
        }

        $body = $webhook->body;
        $expected = hash('sha256', $secret.$body);

        return hash_equals($expected, $received);
    }
}
