<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook\Handler;

use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Nuvei\Webhook\PayloadParser;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayAuthorizationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Psr\Log\LoggerInterface;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Throwable;

/**
 * Nuvei DMN `transactionType=Auth` with `totalAmount > 0`: gateway has
 * authorized (or declined) the PaymentIntent.
 *
 * Side effect on approval: if the payload references a UPO the customer saved
 * their card at Nuvei during this flow — we upsert a local PaymentMethod
 * best-effort.
 *
 * @implements WebhookEventHandler<NuveiEvent>
 */
final readonly class AuthHandler implements WebhookEventHandler
{
    public function __construct(
        private GatewayAuthorizationRecorder $authorizationRecorder,
        private GatewayFailureRecorder $failureRecorder,
        private GatewayPaymentMethodRecorder $paymentMethodRecorder,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var NuveiEvent $event */
        $paymentIntentId = $this->parsePaymentIntentId($event);
        if ($paymentIntentId === null) {
            return HandlerOutcome::Skipped;
        }

        if ($event->status() === 'APPROVED') {
            $outcome = $this->authorizationRecorder->onGatewayAuthorization(
                $gatewayId,
                $paymentIntentId,
                $event->pppTransactionId(),
            );

            if ($outcome === RecorderOutcome::Applied) {
                $this->tryUpsertPaymentMethod($gatewayId, $event);
            }

            return $this->map($outcome);
        }

        $reason = $event->reason() ?: 'Authorization declined';

        return $this->map($this->failureRecorder->onGatewayFailure($paymentIntentId, $reason));
    }

    private function parsePaymentIntentId(NuveiEvent $event): ?string
    {
        $clientUniqueId = $event->clientUniqueId();
        if ($clientUniqueId === '' || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clientUniqueId) !== 1) {
            return null;
        }

        return $clientUniqueId;
    }

    private function tryUpsertPaymentMethod(GatewayId $gatewayId, NuveiEvent $event): void
    {
        $paymentMethodReference = $event->userPaymentOptionId();
        $customerReference = $event->userTokenId();
        if ($paymentMethodReference === '' || $customerReference === '') {
            return;
        }

        $creditCard = PayloadParser::creditCard($event->payload);
        if ($creditCard === null) {
            return;
        }

        $billingAddress = PayloadParser::billingAddress($event->payload);

        try {
            $this->paymentMethodRecorder->onPaymentMethodRecord(
                gatewayId: $gatewayId,
                customerReference: $customerReference,
                paymentMethodReference: $paymentMethodReference,
                creditCard: $creditCard,
                billingAddress: $billingAddress,
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Nuvei Auth DMN: PaymentMethod upsert failed (best-effort)', [
                'gateway_id' => $gatewayId->toString(),
                'userPaymentOptionId' => $paymentMethodReference,
                'error' => $exception->getMessage(),
            ]);
        }
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
