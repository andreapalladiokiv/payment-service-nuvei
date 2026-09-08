<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\Service\PaymentService;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use Throwable;

/**
 * Returns money to the card it came from — `refundTransaction.do` against the settle's
 * transactionId.
 *
 * Unlike {@see Capture} it declares no transaction type of its own: the endpoint is the whole
 * declaration, and a `transactionType` borrowed from the capture would be a field Nuvei does not
 * expect on this call.
 *
 * Refunding onto a DIFFERENT card is not this operation — at Nuvei that is a payout, and it lives
 * in {@see Payout}.
 */
final readonly class Refund
{
    use NuveiRequestParameters;

    public function __construct(
        private NuveiSettings $settings,
        private RefundCommand $command,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->relatedTransactionBody(
            $this->command->amount,
            $this->command->transactionReference,
            $this->command->clientUniqueId,
        );
    }

    public function refund(): GatewayResult
    {
        $data = $this->payload();

        try {
            $result = new PaymentService($this->settings->restClient)->refundTransaction($data);
        } catch (Throwable $e) {
            return NuveiTransactionOutcome::fromThrowable($e)->toGatewayResult();
        }

        return new NuveiTransactionOutcome($result)->toGatewayResult();
    }
}
