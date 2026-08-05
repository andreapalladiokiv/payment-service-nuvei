<?php

declare(strict_types=1);

use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Nuvei\Webhook\Handler\VoidHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayCancellationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

it('cancels the linked PaymentIntent on Void APPROVED', function () {
    $gatewayId = GatewayId::generate();
    $piId = PaymentIntentId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->with($gatewayId, 'ppp_orig')->andReturn($piId->toString());

    $recorder = Mockery::mock(GatewayCancellationRecorder::class);
    $recorder->shouldReceive('onGatewayCancellation')->once()->with($piId->toString())->andReturn(RecorderOutcome::Applied);

    $event = new NuveiEvent([
        'transactionType' => 'Void',
        'Status' => 'APPROVED',
        'relatedTransactionId' => 'ppp_orig',
    ]);

    expect(new VoidHandler($resolver, $recorder)($event, $gatewayId))->toBe(HandlerOutcome::Processed);
});

it('returns Skipped when status is not APPROVED', function () {
    $event = new NuveiEvent([
        'transactionType' => 'Void',
        'Status' => 'DECLINED',
        'relatedTransactionId' => 'ppp_orig',
    ]);

    $handler = new VoidHandler(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(GatewayCancellationRecorder::class),
    );

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});

it('returns Skipped when relatedTransactionId is missing', function () {
    $event = new NuveiEvent([
        'transactionType' => 'Void',
        'Status' => 'APPROVED',
    ]);

    $handler = new VoidHandler(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(GatewayCancellationRecorder::class),
    );

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});
