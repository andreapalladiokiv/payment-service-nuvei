<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayDisputeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Nuvei\Webhook\Handler\ChargebackHandler;

/**
 * The Chargeback event DMN, from the payload's own shape to the recorder call.
 *
 * The payloads are the `.doc-sample.json` fixtures — hand-built from Nuvei's documented field list and
 * named for that reason, per F0's convention, so a captured delivery can replace them in place. They
 * are read as **arrays** here rather than as bytes: the checksum is a different layer's concern, and
 * this file is about what the handler makes of a delivery that has already been accepted and stored.
 *
 * The recorder is a mock because it is the persistence boundary, and its own suite covers what it does
 * with a snapshot. What is asserted here is the snapshot itself — that the handler reads the payload's
 * fields into the right ones, and that it hands a gap in the table *through* rather than filling it.
 *
 * Pest helpers are global, so every function in this file is named for this file.
 */
function chargebackHandlerFixture(string $name): array
{
    $path = dirname(__DIR__, 4).'/Fixtures/Disputes/'.$name;

    is_file($path) || throw new RuntimeException("missing dispute fixture {$name}");

    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The recorder, stubbed, with whatever it was handed kept for the assertions.
 *
 * Captured rather than matched with a boolean `withArgs` because a snapshot has eleven fields worth
 * asserting and a `false` from one such closure says only that something was wrong with all of them.
 *
 * @param  DisputeSnapshot|null  $captured  by reference
 */
function chargebackHandlerRecorder(?DisputeSnapshot &$captured, RecorderOutcome $answer = RecorderOutcome::Applied): GatewayDisputeRecorder
{
    $recorder = Mockery::mock(GatewayDisputeRecorder::class);
    $recorder->shouldReceive('onDisputeObserved')->once()
        ->andReturnUsing(function ($gatewayId, $paymentIntentId, DisputeSnapshot $snapshot) use (&$captured, $answer): RecorderOutcome {
            $captured = $snapshot;

            return $answer;
        });

    return $recorder;
}

it('opens the case a Chargeback DMN describes, against the payment it names', function () {
    // The whole read, in one place: Nuvei's reference for the case and for this delivery, the network
    // read off the reason code's namespace, the code reduced from the `"<code> - <description>"` string
    // Nuvei prints, the two axes the unified code settles, and the deadline that is the reason this
    // context exists at all.
    $gatewayId = GatewayId::generate();
    $paymentIntentId = PaymentIntentId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')
        ->once()
        ->with($gatewayId, '01929fa5-0000-7000-8000-0000000000a1')
        ->andReturn($paymentIntentId->toString());

    $recorder = chargebackHandlerRecorder($captured);
    $handler = new ChargebackHandler($resolver, $recorder);

    $outcome = $handler(new NuveiEvent(chargebackHandlerFixture('chargeback.opened.doc-sample.json')), $gatewayId);

    expect($outcome)->toBe(HandlerOutcome::Processed)
        ->and($captured)->toBeInstanceOf(DisputeSnapshot::class);

    expect($captured?->gatewayDisputeRef)->toBe('dc_1000000001')
        // Not the envelope's `EventId`: `DisputeEventId` is the dispute suite's id for this delivery,
        // and it is what the aggregate compares as its idempotency key.
        ->and($captured?->providerEventKey)->toBe('dce_1000000001')
        // The brand is nowhere in the payload — read off `10.4`'s namespace, which is Visa's.
        ->and($captured?->cardBrand)->toBe(CardBrand::Visa)
        ->and($captured?->reasonCode)->toBe('10.4')
        ->and($captured?->stage)->toBe(DisputeStage::Chargeback->value)
        ->and($captured?->status)->toBe(DisputeStatus::NeedsResponse->value)
        // Both axes carry Nuvei's own word for the case's position, which is what tells two visits to
        // one stage apart — and for Nuvei one code speaks to both.
        ->and($captured?->stageCode)->toBe('FC')
        ->and($captured?->statusCode)->toBe('FC')
        ->and($captured?->disputedAmount)->toEqual(new Money(2500, new Currency('USD')))
        ->and($captured?->responseDueAt?->format('Y-m-d'))->toBe('2026-08-20')
        // The provider's clock, not ours: a day-old delivery retried today must not be filed as today's.
        ->and($captured?->observedAt->format('Y-m-d H:i:s'))->toBe('2026-08-04 11:22:33')
        // Nuvei states none of ConnexPay's position signals, and no fee code anywhere.
        ->and($captured?->outcomeCode)->toBeNull()
        ->and($captured?->waitingOnCode)->toBeNull()
        ->and($captured?->hasResponse)->toBeNull()
        ->and($captured?->familyRef)->toBeNull()
        ->and($captured?->fees)->toBe([]);
});

it('moves the same case when the delivery reports it escalated, without reading the stage off its type', function () {
    // The escalation pair: same `DisputeId`, same `Type: Chargeback`, new `DisputeEventId`, and the code
    // has become `IPA`. The stage the handler reports is pre-arbitration — read from the code, because a
    // case keeps its origin type for its whole life and the type alone would pin it at `chargeback`
    // forever. The event key changing is what makes this a move rather than a redelivery.
    $gatewayId = GatewayId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->once()->andReturn(PaymentIntentId::generate()->toString());

    $recorder = chargebackHandlerRecorder($captured);
    $handler = new ChargebackHandler($resolver, $recorder);

    $handler(new NuveiEvent(chargebackHandlerFixture('chargeback.escalated.doc-sample.json')), $gatewayId);

    expect($captured?->gatewayDisputeRef)->toBe('dc_1000000001')
        ->and($captured?->providerEventKey)->toBe('dce_1000000002')
        ->and($captured?->stage)->toBe(DisputeStage::PreArbitration->value)
        ->and($captured?->status)->toBe(DisputeStatus::NeedsResponse->value)
        ->and($captured?->stageCode)->toBe('IPA')
        // The deadline moves with the phase, which is the operational fact: a pre-arbitration window is
        // a new window, and answering the chargeback one does not answer this.
        ->and($captured?->responseDueAt?->format('Y-m-d'))->toBe('2026-08-28');
});

it('falls back to the transaction the case was raised against when our own id no longer resolves', function () {
    // The case the first lookup cannot reach: a payment initiated over the REST API recorded the
    // *transaction* as its reference, and any intent that was since captured had that row overwritten by
    // the settle's — so the UUID Nuvei echoes back no longer matches anything, while the settled
    // transaction id does. Both lookups are load-bearing, and this is the one that proves the second.
    $gatewayId = GatewayId::generate();
    $paymentIntentId = PaymentIntentId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')
        ->once()
        ->with($gatewayId, '01929fa5-0000-7000-8000-0000000000a1')
        ->andReturnNull();
    $resolver->shouldReceive('resolvePaymentIntent')
        ->once()
        ->with($gatewayId, '1110000000123456')
        ->andReturn($paymentIntentId->toString());

    $recorder = chargebackHandlerRecorder($captured);
    $handler = new ChargebackHandler($resolver, $recorder);

    $outcome = $handler(new NuveiEvent(chargebackHandlerFixture('chargeback.opened.doc-sample.json')), $gatewayId);

    expect($outcome)->toBe(HandlerOutcome::Processed)
        ->and($captured)->toBeInstanceOf(DisputeSnapshot::class);
});

it('does not ask the transaction lookup at all when our own id resolves', function () {
    // The order is a decision, not a convenience: the two references resolve to one intent when they
    // both work, so a second query whose answer would be discarded is only a second chance for a row to
    // be wrong. Pinned by refusing the call rather than by ignoring its result.
    $gatewayId = GatewayId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')
        ->once()
        ->with($gatewayId, '01929fa5-0000-7000-8000-0000000000a1')
        ->andReturn(PaymentIntentId::generate()->toString());
    $resolver->shouldNotReceive('resolvePaymentIntent')->with($gatewayId, '1110000000123456');

    $handler = new ChargebackHandler($resolver, chargebackHandlerRecorder($captured));

    expect($handler(new NuveiEvent(chargebackHandlerFixture('chargeback.opened.doc-sample.json')), $gatewayId))
        ->toBe(HandlerOutcome::Processed);
});

it('delays a case that names one of our payments and cannot resolve it, because that is arrival order', function () {
    // A case can be reported before the reference row tying the transaction to our aggregate is written,
    // and that row exists a moment later. Retrying is the whole of the answer — which is why this is a
    // different outcome from the next test's.
    $gatewayId = GatewayId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->twice()->andReturnNull();

    $recorder = Mockery::mock(GatewayDisputeRecorder::class);
    $recorder->shouldNotReceive('onDisputeObserved');

    $handler = new ChargebackHandler($resolver, $recorder);

    expect($handler(new NuveiEvent(chargebackHandlerFixture('chargeback.opened.doc-sample.json')), $gatewayId))
        ->toBe(HandlerOutcome::Delay);
});

it('skips a case that names no payment of ours at all, because nothing is going to change', function () {
    // A case on another account, or one raised against a transaction this merchant site never processed.
    // No reference is one of ours to wait for, so a retry would repeat forever with the same answer.
    $gatewayId = GatewayId::generate();

    $payload = chargebackHandlerFixture('chargeback.opened.doc-sample.json');
    unset($payload['TransactionDetails']['ClientUniqueId'], $payload['TransactionDetails']['TransactionId']);

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldNotReceive('resolvePaymentIntent');

    $recorder = Mockery::mock(GatewayDisputeRecorder::class);
    $recorder->shouldNotReceive('onDisputeObserved');

    $handler = new ChargebackHandler($resolver, $recorder);

    expect($handler(new NuveiEvent($payload), $gatewayId))->toBe(HandlerOutcome::Skipped);
});

it('hands a status code the table does not cover to the recorder instead of choosing one for it', function () {
    // `FC-SPCSE` is documented and is not mapped, and the handler's job is to pass the gap through
    // rather than to close it: the code travels with no spelling beside it, which is the shape
    // `DisputeSnapshot` names a mapping gap. The recorder is what refuses it — see
    // `EloquentDisputeRecorderTest` — and the router turns that refusal into a retry and then a visibly
    // failed webhook row, which is recoverable by one table entry and is not a case filed under a
    // guessed phase.
    $gatewayId = GatewayId::generate();

    $payload = chargebackHandlerFixture('chargeback.opened.doc-sample.json');
    $payload['Chargeback']['DisputeUnifiedStatusCode'] = 'FC-SPCSE';

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->once()->andReturn(PaymentIntentId::generate()->toString());

    $handler = new ChargebackHandler($resolver, chargebackHandlerRecorder($captured));

    $handler(new NuveiEvent($payload), $gatewayId);

    expect($captured?->statusCode)->toBe('FC-SPCSE')
        ->and($captured?->status)->toBeNull()
        // The stage axis is still placed, because `Type` is a documented vocabulary of its own and the
        // fallback is what it is for. It is the *stage* the fallback answers about, and the refusal
        // above is on the status axis — the two axes fail independently, on purpose.
        ->and($captured?->stageCode)->toBe('FC-SPCSE')
        ->and($captured?->stage)->toBe(DisputeStage::Chargeback->value);
});

it('refuses a delivery whose reason code names no network we hold a list for', function () {
    // The brand is mandatory — it is half of the `(network, code)` pair the evidence requirements are
    // keyed on, so a case without it is not incomplete but unanswerable — and a Chargeback DMN states no
    // brand anywhere. So a code outside every list is refused here, naming the case and the code, rather
    // than filed against the nearest network's requirements. The recorder is never reached: there is no
    // snapshot to hand it.
    $gatewayId = GatewayId::generate();

    $payload = chargebackHandlerFixture('chargeback.opened.doc-sample.json');
    $payload['Chargeback']['ChargebackReason'] = 'Z9 - A code no list of ours carries';

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->once()->andReturn(PaymentIntentId::generate()->toString());

    $recorder = Mockery::mock(GatewayDisputeRecorder::class);
    $recorder->shouldNotReceive('onDisputeObserved');

    $handler = new ChargebackHandler($resolver, $recorder);

    expect(fn () => $handler(new NuveiEvent($payload), $gatewayId))
        ->toThrow(InvalidArgumentException::class, 'Z9');
});
