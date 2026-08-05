<?php

declare(strict_types=1);

use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Nuvei\Webhook\Handler\PaymentMethodCreationHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

function pmCreationEvent(array $overrides = []): NuveiEvent
{
    return new NuveiEvent(array_replace([
        'transactionType' => 'Auth',
        'Status' => 'APPROVED',
        'totalAmount' => '0',
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
    ], $overrides));
}

it('delegates to GatewayPaymentMethodRecorder when APPROVED', function () {
    $gatewayId = GatewayId::generate();

    $recorder = Mockery::mock(GatewayPaymentMethodRecorder::class);
    $recorder->shouldReceive('onPaymentMethodRecord')->once()->andReturn(RecorderOutcome::Applied);

    expect(new PaymentMethodCreationHandler($recorder)(pmCreationEvent(), $gatewayId))
        ->toBe(HandlerOutcome::Processed);
});

it('returns Skipped when status is not APPROVED', function () {
    $recorder = Mockery::mock(GatewayPaymentMethodRecorder::class);
    $recorder->shouldNotReceive('onPaymentMethodRecord');

    expect(new PaymentMethodCreationHandler($recorder)(pmCreationEvent(['Status' => 'DECLINED']), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});

it('returns Skipped when UPO or user token refs are missing', function () {
    $recorder = Mockery::mock(GatewayPaymentMethodRecorder::class);
    $recorder->shouldNotReceive('onPaymentMethodRecord');

    expect(new PaymentMethodCreationHandler($recorder)(pmCreationEvent(['userPaymentOptionId' => '']), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});
