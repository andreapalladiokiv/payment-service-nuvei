<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Throwable;

/**
 * Releases money that was reserved and will not be taken — `voidTransaction.do` by
 * relatedTransactionId. This is what {@see NuveiGateway::cancel()} routes to: cancelling an intent
 * is voiding its authorization, and nothing else in the codebase makes that mapping visible.
 *
 * Voids the full original amount. It deliberately sends no `amount` / `currency` — they are
 * optional per Nuvei's docs and, if sent with anything other than the exact original value, the
 * gateway rejects with "Invalid Amount". {@see NuveiPaymentService} exists to override the SDK's
 * broken client-side mandatory-field check on those two fields.
 *
 * Named for the provider's verb rather than the caller's, and not `Void`, which PHP reserves.
 */
final readonly class VoidTransaction
{
    public function __construct(
        private NuveiSettings $settings,
        private CancelCommand $command,
    ) {}

    /**
     * @return array{clientRequestId: string, clientUniqueId: string, relatedTransactionId: string}
     */
    public function payload(): array
    {
        // One id for both fields, as on a payment: two independent fallback UUIDs would leave a
        // retried void uncorrelatable.
        $clientId = $this->command->clientUniqueId ?? Uuid::uuid4()->toString();

        return [
            'clientRequestId' => $clientId,
            'clientUniqueId' => $clientId,
            'relatedTransactionId' => $this->command->transactionReference,
        ];
    }

    public function void(): GatewayResult
    {
        $data = $this->payload();

        try {
            $result = new NuveiPaymentService($this->settings->restClient)->voidTransaction($data);
        } catch (Throwable $e) {
            return NuveiTransactionOutcome::fromThrowable($e)->toGatewayResult();
        }

        return new NuveiTransactionOutcome($result)->toGatewayResult();
    }
}
