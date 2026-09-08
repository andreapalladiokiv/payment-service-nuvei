<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\Service\PaymentService;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use Throwable;

/**
 * Takes money that was reserved — `settleTransaction.do` against the authorization's
 * transactionId.
 *
 * The endpoint alone does not tell Nuvei what this is: `settleTransaction.do` takes a
 * `transactionType` and it has to say `Settle`. Both are pinned by tests because either being
 * wrong leaves the authorization uncaptured until it expires. The field used to be bolted on
 * inside `sendData()`, so the payload a test could inspect was not the payload that went out;
 * it is part of {@see payload()} now, and the wire is unchanged either way.
 *
 * Answers with a bare {@see GatewayResult}: a settle carries no challenge and no card checks —
 * the issuer already gave its verdict on the authorization.
 */
final readonly class Capture
{
    use NuveiRequestParameters;

    public function __construct(
        private NuveiSettings $settings,
        private CaptureCommand $command,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            ...$this->relatedTransactionBody(
                $this->command->amount,
                $this->command->transactionReference,
                $this->command->clientUniqueId,
            ),
            'transactionType' => 'Settle',
        ];
    }

    public function capture(): GatewayResult
    {
        // Outside the try for the reason every operation builds it outside: omnipay wrapped only
        // the sending, so assembling a body has never been able to answer as a gateway refusal.
        $data = $this->payload();

        try {
            $result = new PaymentService($this->settings->restClient)->settleTransaction($data);
        } catch (Throwable $e) {
            return NuveiTransactionOutcome::fromThrowable($e)->toGatewayResult();
        }

        return new NuveiTransactionOutcome($result)->toGatewayResult();
    }
}
