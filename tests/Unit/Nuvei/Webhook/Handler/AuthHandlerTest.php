<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Nuvei\Webhook\Handler\AuthHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayAuthorizationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

function authEvent(string $status, string $piId, array $extra = []): NuveiEvent
{
    return new NuveiEvent(array_replace([
        'transactionType' => 'Auth',
        'Status' => $status,
        'clientUniqueId' => $piId,
        'PPP_TransactionID' => 'ppp_'.bin2hex(random_bytes(4)),
        'totalAmount' => '100',
    ], $extra));
}

it('records authorization on APPROVED and attempts a best-effort PM upsert', function () {
    $gatewayId = GatewayId::generate();
    $piId = PaymentIntentId::generate();
    $event = authEvent('APPROVED', $piId->toString(), [
        'userPaymentOptionId' => 'upo_1',
        'userTokenId' => 'utk_1',
        'bin' => '424242',
        'last4Digits' => '4242',
        'brand' => 'visa',
        'ccExpMonth' => '12',
        'ccExpYear' => '2030',
        'address' => '1 Main',
        'city' => 'NYC',
        'country' => 'US',
        'zip' => '10001',
    ]);

    $authRec = Mockery::mock(GatewayAuthorizationRecorder::class);
    $authRec->shouldReceive('onGatewayAuthorization')->once()->andReturn(RecorderOutcome::Applied);
    $failRec = Mockery::mock(GatewayFailureRecorder::class);
    $pmRec = Mockery::mock(GatewayPaymentMethodRecorder::class);
    $pmRec->shouldReceive('onPaymentMethodRecord')->once()->andReturn(RecorderOutcome::Applied);

    $handler = new AuthHandler($authRec, $failRec, $pmRec, Mockery::mock(LoggerInterface::class));

    expect($handler($event, $gatewayId))->toBe(HandlerOutcome::Processed);
});

it('records a failure on non-APPROVED status', function () {
    $piId = PaymentIntentId::generate();
    $event = authEvent('DECLINED', $piId->toString(), ['Reason' => 'Card declined']);

    $failRec = Mockery::mock(GatewayFailureRecorder::class);
    $failRec->shouldReceive('onGatewayFailure')->once()->with($piId->toString(), 'Card declined')->andReturn(RecorderOutcome::Applied);

    $handler = new AuthHandler(
        Mockery::mock(GatewayAuthorizationRecorder::class),
        $failRec,
        Mockery::mock(GatewayPaymentMethodRecorder::class),
        Mockery::mock(LoggerInterface::class),
    );

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Processed);
});

it('returns Skipped when clientUniqueId is not a valid PaymentIntentId', function () {
    $event = new NuveiEvent([
        'transactionType' => 'Auth',
        'Status' => 'APPROVED',
        'clientUniqueId' => 'not-a-uuid',
        'totalAmount' => '100',
    ]);

    $handler = new AuthHandler(
        Mockery::mock(GatewayAuthorizationRecorder::class),
        Mockery::mock(GatewayFailureRecorder::class),
        Mockery::mock(GatewayPaymentMethodRecorder::class),
        Mockery::mock(LoggerInterface::class),
    );

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});
