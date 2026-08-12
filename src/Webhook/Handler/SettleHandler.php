<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook\Handler;

use DateTimeImmutable;
use Override;
use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

/**
 * Nuvei DMN `transactionType=Settle`: capture confirmed (APPROVED only).
 *
 * `clientUniqueId` is the value our saga sent when calling capture —
 * `<paymentIntentUuid>:capture` since the idempotency refactor. We
 * extract the UUID portion via {@see NuveiEvent::clientUniqueIdUuid}.
 *
 * `feeAmount` is the processor fee Nuvei booked for this settle — forwarded
 * to {@see GatewayFeeRecorder::onPaymentIntentFee} for admin display.
 * Settlement is the earliest moment the fee is finalized on Nuvei's side.
 *
 * @implements WebhookEventHandler<NuveiEvent>
 */
final readonly class SettleHandler implements WebhookEventHandler
{
    public function __construct(
        private GatewaySuccessRecorder $recorder,
        private GatewayFeeRecorder $feeRecorder,
    ) {}

    #[Override]
    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var NuveiEvent $event */
        if ($event->status() !== 'APPROVED') {
            return HandlerOutcome::Skipped;
        }

        $paymentIntentId = $this->parsePaymentIntentId($event);
        if ($paymentIntentId === null) {
            return HandlerOutcome::Skipped;
        }

        $reference = $event->transactionId();
        if ($reference === '') {
            return HandlerOutcome::Skipped;
        }

        $amount = $event->totalMoney();

        $outcome = $this->recorder->onGatewaySuccess($gatewayId, $paymentIntentId, $reference, $amount);
        $handlerOutcome = match ($outcome) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };

        $fee = $event->feeMoney();
        if ($outcome === RecorderOutcome::Applied && $fee !== null) {
            $this->feeRecorder->onPaymentIntentFee($gatewayId, $paymentIntentId, $fee, new DateTimeImmutable);
        }

        return $handlerOutcome;
    }

    private function parsePaymentIntentId(NuveiEvent $event): ?string
    {
        $uuid = $event->clientUniqueIdUuid();
        if ($uuid === '' || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) !== 1) {
            return null;
        }

        return $uuid;
    }
}
