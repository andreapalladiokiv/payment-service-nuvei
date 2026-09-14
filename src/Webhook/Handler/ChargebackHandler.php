<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Webhook\Handler;

use Override;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayDisputeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Nuvei\Webhook\DTO\ChargebackEvent;
use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;

/**
 * Nuvei Chargeback event DMN — a case has been raised, or an existing case has moved.
 *
 * This is the whole of what classic API 1.0 can carry for disputes. Reading a case and answering one
 * are Control Panel work on 1.0, so nothing here reads or submits: a delivery becomes a fact on a
 * `Dispute` aggregate, with its deadline, and that is the point — the failure this context exists to
 * prevent is a chargeback whose response window closed while nobody in this service knew it existed.
 *
 * ## Which PaymentIntent the case belongs to, and the two ways that can fail
 *
 * Two references are tried, in this order, and the order is a decision rather than a convenience:
 *
 *  1. **`TransactionDetails.ClientUniqueId`, reduced to its UUID portion.** This is *our* own value
 *     echoed back — the `<aggregateUuid>` form every Nuvei call of ours sends — so resolving by it
 *     does not depend on which transaction the DMN names. That question is genuinely open (a
 *     chargeback is raised against the settled transaction while the row also holds the opening
 *     one), and a lookup that never has to answer it cannot be wrong about it.
 *  2. **`TransactionDetails.TransactionId`**, the way {@see VoidHandler} resolves a void. It is what
 *     saves the cases the first path cannot reach: a payment initiated through the REST API rather
 *     than the hosted page recorded the *transaction* as its reference, and any intent that has
 *     since been captured had its reference overwritten by the settle's — so the UUID the DMN echoes
 *     no longer matches the row, while the settled transaction id does.
 *
 * Neither finding the payment is a different outcome from the payload naming no payment at all, and
 * the difference is the one {@see \Techork\PaymentService\Stripe\Webhook\Handler\ChargeDisputeCreatedHandler}
 * draws:
 *
 *  - **no reference at all → `Skipped`.** Nuvei names no transaction of ours, so this is not about
 *    one of our payments — a case on another account, or a delivery for a merchant site whose
 *    transactions we never processed. `Skipped` is final because nothing is going to change.
 *  - **named but not found → `Delay`.** This is arrival order. A case can be reported before the
 *    reference row tying the transaction to our aggregate is written, and that row exists a moment
 *    later. Retrying is the whole of the answer.
 *
 * ## An unmapped code is a failure, not a dropped case
 *
 * {@see ChargebackEvent::snapshot()} refuses a reason code whose network cannot be read, and the
 * recorder refuses a unified status code this service has not mapped. Both are throws, which the
 * router turns into a retry and, at exhaustion, a visibly `Failed` webhook row. That is deliberate
 * and it is the plan's rule for an unmapped provider signal: a case filed under a guessed stage
 * carries another phase's deadline and evidence set — and the evidence set is the thing an operator
 * would have to answer with — so it is worse than a delivery that failed where someone can see it.
 *
 * @implements WebhookEventHandler<NuveiEvent>
 */
final readonly class ChargebackHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private GatewayDisputeRecorder $recorder,
    ) {}

    #[Override]
    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var NuveiEvent $event */
        $case = ChargebackEvent::from($event);

        $paymentIntentId = $this->resolvePaymentIntent($case, $gatewayId);

        if ($paymentIntentId === null) {
            return $this->namesNoPayment($case) ? HandlerOutcome::Skipped : HandlerOutcome::Delay;
        }

        return match ($this->recorder->onDisputeObserved($gatewayId, $paymentIntentId, $case->snapshot())) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }

    /**
     * The aggregate id behind the case, or null — the order is argued in the class docblock, and the
     * second lookup is skipped entirely when the first answers.
     *
     * Skipped rather than "also tried" because the two resolve to one intent when they both work,
     * and a second query whose answer is discarded is a second chance for a row to be wrong.
     */
    private function resolvePaymentIntent(ChargebackEvent $case, GatewayId $gatewayId): ?string
    {
        $clientUniqueId = $case->clientUniqueIdUuid();

        if ($clientUniqueId !== '') {
            $resolved = $this->resolver->resolvePaymentIntent($gatewayId, $clientUniqueId);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        $transactionId = $case->transactionId();

        return $transactionId === ''
            ? null
            : $this->resolver->resolvePaymentIntent($gatewayId, $transactionId);
    }

    /**
     * Whether the delivery named no transaction of ours at all, as against naming one we could not
     * find — see the class docblock for why the two are different outcomes.
     */
    private function namesNoPayment(ChargebackEvent $case): bool
    {
        return $case->clientUniqueIdUuid() === '' && $case->transactionId() === '';
    }
}
