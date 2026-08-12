<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook\Handler;

use Override;
use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

/**
 * Nuvei DMN `transactionType=Sale`: hosted Cashier purchase completed.
 *
 * The Cashier flow is a one-shot redirect — the PaymentIntent sits in
 * `RequiresAction` until this DMN arrives, at which point the recorder's
 * `onGatewaySuccess` calls `confirmChallenge` and the aggregate transitions
 * to `Charged`.
 *
 * `clientUniqueId` was set to the PaymentIntent UUID when we built the
 * Cashier form, so we recover it here and use `TransactionID` as the gateway
 * reference saved against the PI.
 *
 * `TransactionID` rather than `PPP_TransactionID` even though a hosted sale has
 * no API response to agree with: a later Credit DMN refunding this sale carries
 * `relatedTransactionId` in the `TransactionID` family, so a PI stored under the
 * PPP id could never be resolved from its own refund.
 *
 * @implements WebhookEventHandler<NuveiEvent>
 */
final readonly class SaleHandler implements WebhookEventHandler
{
    public function __construct(
        private GatewaySuccessRecorder $successRecorder,
        private GatewayFailureRecorder $failureRecorder,
    ) {}

    #[Override]
    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var NuveiEvent $event */
        $paymentIntentId = $this->parsePaymentIntentId($event);
        if ($paymentIntentId === null) {
            return HandlerOutcome::Skipped;
        }

        if ($event->status() !== 'APPROVED') {
            $reason = $event->reason() ?: 'Hosted payment declined';

            return $this->map($this->failureRecorder->onGatewayFailure($paymentIntentId, $reason));
        }

        $reference = $event->transactionId();
        if ($reference === '') {
            return HandlerOutcome::Skipped;
        }

        $amount = $event->totalMoney();

        return $this->map($this->successRecorder->onGatewaySuccess(
            $gatewayId,
            $paymentIntentId,
            $reference,
            $amount,
        ));
    }

    private function parsePaymentIntentId(NuveiEvent $event): ?string
    {
        $uuid = $event->clientUniqueIdUuid();
        if ($uuid === '' || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) !== 1) {
            return null;
        }

        return $uuid;
    }

    private function map(RecorderOutcome $outcome): HandlerOutcome
    {
        return match ($outcome) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }
}
