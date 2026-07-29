<?php

declare(strict_types=1);

use Money\Money;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Nuvei\Webhook\Handler\SaleHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

function saleEvent(string $status, string $piId, array $extra = []): NuveiEvent
{
    return new NuveiEvent(array_replace([
        'transactionType' => 'Sale',
        'Status' => $status,
        'clientUniqueId' => $piId,
        'PPP_TransactionID' => 'ppp_'.bin2hex(random_bytes(4)),
        'totalAmount' => '10.50',
        'currency' => 'USD',
    ], $extra));
}

it('records gateway success on APPROVED Sale DMN', function () {
    $piId = Uuid::uuid4()->toString();
    $event = saleEvent('APPROVED', $piId, [
        'PPP_TransactionID' => 'ppp_abcdef',
    ]);

    $successRec = Mockery::mock(GatewaySuccessRecorder::class);
    $successRec->shouldReceive('onGatewaySuccess')
        ->once()
        ->withArgs(function ($gatewayId, $piIdArg, $reference, Money $amount) use ($piId) {
            return $piIdArg === $piId
                && $reference === 'ppp_abcdef'
                // '10.50' is $10.50: DMN amounts are major units, so the
                // recorded Money must be 1050 minor units, not 10.
                && $amount->getAmount() === '1050'
                && $amount->getCurrency()->getCode() === 'USD';
        })
        ->andReturn(RecorderOutcome::Applied);

    $handler = new SaleHandler($successRec, Mockery::mock(GatewayFailureRecorder::class));

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Processed);
});

it('parses the DMN amount against the currency ISO scale, not as minor units', function () {
    $piId = Uuid::uuid4()->toString();
    // JPY has no minor unit: '5000' is Y5,000 and stays 5000. Under a
    // minor-unit reading USD '10.50' would collapse to 10 cents.
    $event = saleEvent('APPROVED', $piId, ['totalAmount' => '5000', 'currency' => 'JPY']);

    $successRec = Mockery::mock(GatewaySuccessRecorder::class);
    $successRec->shouldReceive('onGatewaySuccess')
        ->once()
        ->withArgs(fn ($g, $p, $r, Money $amount) => $amount->getAmount() === '5000'
            && $amount->getCurrency()->getCode() === 'JPY')
        ->andReturn(RecorderOutcome::Applied);

    $handler = new SaleHandler($successRec, Mockery::mock(GatewayFailureRecorder::class));

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Processed);
});

it('refuses to book a DMN that names no currency instead of assuming USD', function () {
    $piId = Uuid::uuid4()->toString();
    $event = saleEvent('APPROVED', $piId, ['currency' => '']);

    $successRec = Mockery::mock(GatewaySuccessRecorder::class);
    $successRec->shouldNotReceive('onGatewaySuccess');

    $handler = new SaleHandler($successRec, Mockery::mock(GatewayFailureRecorder::class));

    expect(fn () => $handler($event, GatewayId::generate()))
        ->toThrow(RuntimeException::class, 'names no currency');
});

it('records a failure on non-APPROVED Sale status', function () {
    $piId = Uuid::uuid4()->toString();
    $event = saleEvent('DECLINED', $piId, ['Reason' => 'Insufficient funds']);

    $failRec = Mockery::mock(GatewayFailureRecorder::class);
    $failRec->shouldReceive('onGatewayFailure')
        ->once()
        ->with($piId, 'Insufficient funds')
        ->andReturn(RecorderOutcome::Applied);

    $handler = new SaleHandler(Mockery::mock(GatewaySuccessRecorder::class), $failRec);

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Processed);
});

it('falls back to a default reason when DMN omits one', function () {
    $piId = Uuid::uuid4()->toString();
    $event = saleEvent('DECLINED', $piId);

    $failRec = Mockery::mock(GatewayFailureRecorder::class);
    $failRec->shouldReceive('onGatewayFailure')
        ->once()
        ->with($piId, 'Hosted payment declined')
        ->andReturn(RecorderOutcome::Applied);

    $handler = new SaleHandler(Mockery::mock(GatewaySuccessRecorder::class), $failRec);

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Processed);
});

it('returns Skipped when clientUniqueId is not a valid PaymentIntentId', function () {
    $event = saleEvent('APPROVED', 'not-a-uuid');

    $handler = new SaleHandler(
        Mockery::mock(GatewaySuccessRecorder::class),
        Mockery::mock(GatewayFailureRecorder::class),
    );

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});

it('maps NotFound recorder outcome to Delay so the webhook gets retried', function () {
    $piId = Uuid::uuid4()->toString();
    $event = saleEvent('APPROVED', $piId);

    $successRec = Mockery::mock(GatewaySuccessRecorder::class);
    $successRec->shouldReceive('onGatewaySuccess')->once()->andReturn(RecorderOutcome::NotFound);

    $handler = new SaleHandler($successRec, Mockery::mock(GatewayFailureRecorder::class));

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Delay);
});
