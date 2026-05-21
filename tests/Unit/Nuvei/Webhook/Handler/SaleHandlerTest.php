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
                && $amount->getCurrency()->getCode() === 'USD';
        })
        ->andReturn(RecorderOutcome::Applied);

    $handler = new SaleHandler($successRec, Mockery::mock(GatewayFailureRecorder::class));

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Processed);
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
