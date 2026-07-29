<?php

declare(strict_types=1);

use Money\Money;

use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Nuvei\Webhook\Handler\SettleHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

it('delegates Settle APPROVED to GatewaySuccessRecorder', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . substr(uniqid(), 0, 12);
    $event = new NuveiEvent([
        'transactionType' => 'Settle',
        'Status' => 'APPROVED',
        'clientUniqueId' => $piId,
        'PPP_TransactionID' => 'ppp_123',
        'totalAmount' => '100',
        'currency' => 'USD',
    ]);

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')
        ->once()
        ->with($gatewayId, $piId, 'ppp_123', Mockery::on(fn (Money $m) => $m->getAmount() === '10000' && $m->getCurrency()->getCode() === 'USD'))
        ->andReturn(RecorderOutcome::Applied);

    $feeRecorder = Mockery::mock(GatewayFeeRecorder::class);
    $feeRecorder->shouldNotReceive('onPaymentIntentFee');

    expect((new SettleHandler($recorder, $feeRecorder))($event, $gatewayId))->toBe(HandlerOutcome::Processed);
});

it('returns Skipped when status is not APPROVED', function () {
    $event = new NuveiEvent([
        'transactionType' => 'Settle',
        'Status' => 'DECLINED',
        'clientUniqueId' => '01942f6e-1c3a-7b8d-9e4f-' . substr(uniqid(), 0, 12),
    ]);

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldNotReceive('onGatewaySuccess');

    $feeRecorder = Mockery::mock(GatewayFeeRecorder::class);
    $feeRecorder->shouldNotReceive('onPaymentIntentFee');

    expect((new SettleHandler($recorder, $feeRecorder))($event, GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});

it('strips :capture suffix from clientUniqueId before resolving (idempotency-key convention)', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . substr(uniqid(), 0, 12);
    $event = new NuveiEvent([
        'transactionType' => 'Settle',
        'Status' => 'APPROVED',
        'clientUniqueId' => $piId.':capture',
        'PPP_TransactionID' => 'ppp_456',
        'totalAmount' => '500',
        'currency' => 'USD',
    ]);

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')
        ->once()
        ->with($gatewayId, $piId, 'ppp_456', Mockery::any())
        ->andReturn(RecorderOutcome::Applied);

    $feeRecorder = Mockery::mock(GatewayFeeRecorder::class);
    $feeRecorder->shouldNotReceive('onPaymentIntentFee');

    expect((new SettleHandler($recorder, $feeRecorder))($event, $gatewayId))->toBe(HandlerOutcome::Processed);
});

it('forwards feeAmount to FeeRecorder when present and recorder Applied', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . substr(uniqid(), 0, 12);
    $event = new NuveiEvent([
        'transactionType' => 'Settle',
        'Status' => 'APPROVED',
        'clientUniqueId' => $piId,
        'PPP_TransactionID' => 'ppp_fee',
        'totalAmount' => '1000',
        'feeAmount' => '35',
        'currency' => 'USD',
    ]);

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')->once()->andReturn(RecorderOutcome::Applied);

    $feeRecorder = Mockery::mock(GatewayFeeRecorder::class);
    $feeRecorder->shouldReceive('onPaymentIntentFee')
        ->once()
        ->withArgs(function (GatewayId $gid, string $pi, Money $fee, DateTimeImmutable $observedAt) use ($gatewayId, $piId) {
            return $gid->equals($gatewayId)
                && $pi === $piId
                && $fee->getAmount() === '3500'
                && $fee->getCurrency()->getCode() === 'USD';
        })
        ->andReturn(RecorderOutcome::Applied);

    expect((new SettleHandler($recorder, $feeRecorder))($event, $gatewayId))->toBe(HandlerOutcome::Processed);
});

it('does not forward fee when feeAmount is absent or zero', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . substr(uniqid(), 0, 12);
    $event = new NuveiEvent([
        'transactionType' => 'Settle',
        'Status' => 'APPROVED',
        'clientUniqueId' => $piId,
        'PPP_TransactionID' => 'ppp_no_fee',
        'totalAmount' => '1000',
        'currency' => 'USD',
    ]);

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')->once()->andReturn(RecorderOutcome::Applied);

    $feeRecorder = Mockery::mock(GatewayFeeRecorder::class);
    $feeRecorder->shouldNotReceive('onPaymentIntentFee');

    expect((new SettleHandler($recorder, $feeRecorder))($event, $gatewayId))->toBe(HandlerOutcome::Processed);
});

it('does not forward fee when recorder did not Apply (e.g. NotFound)', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . substr(uniqid(), 0, 12);
    $event = new NuveiEvent([
        'transactionType' => 'Settle',
        'Status' => 'APPROVED',
        'clientUniqueId' => $piId,
        'PPP_TransactionID' => 'ppp_notfound',
        'totalAmount' => '1000',
        'feeAmount' => '35',
        'currency' => 'USD',
    ]);

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')->once()->andReturn(RecorderOutcome::NotFound);

    $feeRecorder = Mockery::mock(GatewayFeeRecorder::class);
    $feeRecorder->shouldNotReceive('onPaymentIntentFee');

    expect((new SettleHandler($recorder, $feeRecorder))($event, $gatewayId))->toBe(HandlerOutcome::Delay);
});
