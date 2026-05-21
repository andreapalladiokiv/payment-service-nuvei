<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook\Handler;

use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayCancellationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Nuvei DMN `transactionType=Void`: void confirmed — the linked PaymentIntent
 * should be cancelled.
 *
 * @implements WebhookEventHandler<NuveiEvent>
 */
final readonly class VoidHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private GatewayCancellationRecorder $recorder,
    ) {}

    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var NuveiEvent $event */
        if ($event->status() !== 'APPROVED') {
            return HandlerOutcome::Skipped;
        }

        $relatedTransactionId = $event->relatedTransactionId();
        if ($relatedTransactionId === '') {
            return HandlerOutcome::Skipped;
        }

        $paymentIntentId = $this->resolver->resolvePaymentIntent($gatewayId, $relatedTransactionId);
        if ($paymentIntentId === null) {
            return HandlerOutcome::Delay;
        }

        return match ($this->recorder->onGatewayCancellation($paymentIntentId)) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }
}
