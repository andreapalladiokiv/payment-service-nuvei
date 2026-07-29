<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook\Handler;

use DateTimeImmutable;
use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundProcessingRecorder;

/**
 * Nuvei DMN `transactionType=Credit`: refund processed (APPROVED) or failed.
 *
 * On APPROVED with a non-zero `feeAmount`, also forwards the fee Nuvei
 * booked for this credit to {@see FeeRecorder} for admin display. We
 * resolve our internal refund id via
 * {@see TransactionIdResolver::resolveRefund} — the gateway-side refund
 * reference is `PPP_TransactionID`.
 *
 * @implements WebhookEventHandler<NuveiEvent>
 */
final readonly class CreditHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private RefundProcessingRecorder $processingRecorder,
        private RefundFailureRecorder $failureRecorder,
        private GatewayFeeRecorder $feeRecorder,
    ) {}

    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var NuveiEvent $event */
        $refundReference = $event->pppTransactionId();
        if ($refundReference === '') {
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

        $amount = $event->totalMoney();
        $isApproved = $event->status() === 'APPROVED';

        $outcome = $isApproved
            ? $this->processingRecorder->onRefundProcessed($gatewayId, $paymentIntentId, $refundReference, $amount)
            : $this->failureRecorder->onRefundFailed(
                $gatewayId,
                $paymentIntentId,
                $refundReference,
                $amount,
                $event->reason() ?: 'Refund declined',
            );

        $handlerOutcome = match ($outcome) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };

        $fee = $event->feeMoney();
        if ($isApproved && $outcome === RecorderOutcome::Applied && $fee !== null) {
            $refundId = $this->resolver->resolveRefund($gatewayId, $refundReference);
            if ($refundId !== null) {
                $this->feeRecorder->onRefundFee($gatewayId, $refundId, $fee, new DateTimeImmutable);
            }
        }

        return $handlerOutcome;
    }
}
