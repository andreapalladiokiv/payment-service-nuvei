<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook\Handler;

use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Nuvei\Webhook\PayloadParser;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Nuvei DMN `transactionType=Auth` with `totalAmount=0` is Nuvei's tokenization
 * flow — no real transaction, just a UPO (saved card). We upsert a local
 * PaymentMethod with a reference to the UPO on this gateway.
 *
 * @implements WebhookEventHandler<NuveiEvent>
 */
final readonly class PaymentMethodCreationHandler implements WebhookEventHandler
{
    public function __construct(
        private GatewayPaymentMethodRecorder $recorder,
    ) {}

    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var NuveiEvent $event */
        if ($event->status() !== 'APPROVED') {
            return HandlerOutcome::Skipped;
        }

        $paymentMethodReference = $event->userPaymentOptionId();
        $customerReference = $event->userTokenId();
        if ($paymentMethodReference === '' || $customerReference === '') {
            return HandlerOutcome::Skipped;
        }

        $creditCard = PayloadParser::creditCard($event->payload);
        if ($creditCard === null) {
            return HandlerOutcome::Skipped;
        }

        $billingAddress = PayloadParser::billingAddress($event->payload);

        return match ($this->recorder->onPaymentMethodRecord(
            gatewayId: $gatewayId,
            customerReference: $customerReference,
            paymentMethodReference: $paymentMethodReference,
            creditCard: $creditCard,
            billingAddress: $billingAddress,
        )) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }
}
