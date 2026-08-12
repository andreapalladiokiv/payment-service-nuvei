<?php

declare(strict_types=1);

use Money\Money;

use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Nuvei\Webhook\Handler\CreditHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundProcessingRecorder;

it('delegates Credit APPROVED to RefundProcessingRecorder', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . substr(uniqid(), 0, 12);

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->with($gatewayId, 'ppp_orig')->andReturn($piId);

    $processing = Mockery::mock(RefundProcessingRecorder::class);
    $processing->shouldReceive('onRefundProcessed')
        ->once()
        // '500' is $500.00: DMN amounts are major units, so the refund must be
        // recorded as 50000 minor units, not 500.
        // The refund is keyed on `TransactionID`, the id the refund API call
        // returned and stored — not on `PPP_TransactionID`, which Nuvei numbers
        // separately and which therefore matches no row we ever wrote.
        ->with($gatewayId, $piId, '8110000000030211270', Mockery::on(
            fn (Money $m) => $m->getAmount() === '50000' && $m->getCurrency()->getCode() === 'USD',
        ))
        ->andReturn(RecorderOutcome::Applied);

    $failure = Mockery::mock(RefundFailureRecorder::class);
    $feeRecorder = Mockery::mock(GatewayFeeRecorder::class);
    $feeRecorder->shouldNotReceive('onRefundFee');

    $event = new NuveiEvent([
        'transactionType' => 'Credit',
        'Status' => 'APPROVED',
        'PPP_TransactionID' => 'ppp_refund',
        'TransactionID' => '8110000000030211270',
        'relatedTransactionId' => 'ppp_orig',
        'totalAmount' => '500',
        'currency' => 'USD',
    ]);

    expect(new CreditHandler($resolver, $processing, $failure, $feeRecorder)($event, $gatewayId))
        ->toBe(HandlerOutcome::Processed);
});

it('delegates Credit DECLINED to RefundFailureRecorder', function () {
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn('01942f6e-1c3a-7b8d-9e4f-' . substr(uniqid(), 0, 12));

    $processing = Mockery::mock(RefundProcessingRecorder::class);
    $failure = Mockery::mock(RefundFailureRecorder::class);
    $failure->shouldReceive('onRefundFailed')->once()->andReturn(RecorderOutcome::Applied);

    $feeRecorder = Mockery::mock(GatewayFeeRecorder::class);
    $feeRecorder->shouldNotReceive('onRefundFee');

    $event = new NuveiEvent([
        'transactionType' => 'Credit',
        'Status' => 'DECLINED',
        'PPP_TransactionID' => 'ppp_refund',
        'TransactionID' => '8110000000030211270',
        'relatedTransactionId' => 'ppp_orig',
        'totalAmount' => '500',
        'currency' => 'USD',
        'Reason' => 'Refund declined',
    ]);

    expect(new CreditHandler($resolver, $processing, $failure, $feeRecorder)($event, GatewayId::generate()))
        ->toBe(HandlerOutcome::Processed);
});

it('returns Delay when relatedTransactionId does not resolve', function () {
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturnNull();

    $event = new NuveiEvent([
        'transactionType' => 'Credit',
        'Status' => 'APPROVED',
        'PPP_TransactionID' => 'ppp_refund',
        'TransactionID' => '8110000000030211270',
        'relatedTransactionId' => 'ppp_unknown',
        'totalAmount' => '500',
    ]);

    $handler = new CreditHandler(
        $resolver,
        Mockery::mock(RefundProcessingRecorder::class),
        Mockery::mock(RefundFailureRecorder::class),
        Mockery::mock(GatewayFeeRecorder::class),
    );

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Delay);
});

it('returns Skipped when refund or related references are missing', function () {
    $event = new NuveiEvent([
        'transactionType' => 'Credit',
        'Status' => 'APPROVED',
        'totalAmount' => '500',
    ]);

    $handler = new CreditHandler(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(RefundProcessingRecorder::class),
        Mockery::mock(RefundFailureRecorder::class),
        Mockery::mock(GatewayFeeRecorder::class),
    );

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});

/**
 * The incident this guards against: a Credit DMN keyed on `PPP_TransactionID`
 * matched no refund row — the refund API call had stored `transactionId` — so the
 * recorder took it for a refund raised in Nuvei's dashboard and fabricated a second
 * one. Two refunds then existed against one payment, and the next real refund was
 * refused for exceeding the payment.
 *
 * Falling back to the PPP id when `TransactionID` is absent would restore exactly
 * that, so the handler refuses instead. `Skipped` rather than `Delay`: a DMN with no
 * `TransactionID` will not grow one on redelivery.
 */
it('refuses a Credit that names no TransactionID rather than falling back to the PPP id', function () {
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldNotReceive('resolvePaymentIntent');

    $processing = Mockery::mock(RefundProcessingRecorder::class);
    $processing->shouldNotReceive('onRefundProcessed');

    $event = new NuveiEvent([
        'transactionType' => 'Credit',
        'Status' => 'APPROVED',
        'PPP_TransactionID' => 'ppp_refund',
        'relatedTransactionId' => '8110000000030211000',
        'totalAmount' => '4391.96',
        'currency' => 'USD',
    ]);

    $handler = new CreditHandler(
        $resolver,
        $processing,
        Mockery::mock(RefundFailureRecorder::class),
        Mockery::mock(GatewayFeeRecorder::class),
    );

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});

/**
 * The amount from the same incident. `(int) '4391.96'` is 4391 minor units — $43.91,
 * two orders of magnitude short and close enough to the real figure to read as a
 * plausible second refund. The fixtures that missed this used whole strings, where
 * major and minor units are indistinguishable.
 */
it('books a fractional DMN amount at its major-unit value', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-'.substr(uniqid(), 0, 12);

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn($piId);

    $processing = Mockery::mock(RefundProcessingRecorder::class);
    $processing->shouldReceive('onRefundProcessed')
        ->once()
        ->with($gatewayId, $piId, '8110000000030211270', Mockery::on(
            fn (Money $m) => $m->getAmount() === '439196' && $m->getCurrency()->getCode() === 'USD',
        ))
        ->andReturn(RecorderOutcome::Applied);

    $feeRecorder = Mockery::mock(GatewayFeeRecorder::class);
    $feeRecorder->shouldNotReceive('onRefundFee');

    $event = new NuveiEvent([
        'transactionType' => 'Credit',
        'Status' => 'APPROVED',
        'PPP_TransactionID' => 'ppp_refund',
        'TransactionID' => '8110000000030211270',
        'relatedTransactionId' => '8110000000030211000',
        'totalAmount' => '4391.96',
        'currency' => 'USD',
    ]);

    expect(new CreditHandler($resolver, $processing, Mockery::mock(RefundFailureRecorder::class), $feeRecorder)($event, $gatewayId))
        ->toBe(HandlerOutcome::Processed);
});

it('forwards feeAmount to FeeRecorder when refund is APPROVED and resolveRefund succeeds', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . substr(uniqid(), 0, 12);
    $refundId = '01942f6e-1c3a-7b8d-9e4f-aaaaaaaaaaaa';

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->with($gatewayId, 'ppp_orig')->andReturn($piId);
    $resolver->shouldReceive('resolveRefund')->with($gatewayId, '8110000000030211270')->andReturn($refundId);

    $processing = Mockery::mock(RefundProcessingRecorder::class);
    $processing->shouldReceive('onRefundProcessed')->once()->andReturn(RecorderOutcome::Applied);

    $feeRecorder = Mockery::mock(GatewayFeeRecorder::class);
    $feeRecorder->shouldReceive('onRefundFee')
        ->once()
        ->withArgs(function (GatewayId $gid, string $rid, Money $fee) use ($gatewayId, $refundId) {
            return $gid->equals($gatewayId)
                && $rid === $refundId
                && $fee->getAmount() === '1200'
                && $fee->getCurrency()->getCode() === 'USD';
        })
        ->andReturn(RecorderOutcome::Applied);

    $event = new NuveiEvent([
        'transactionType' => 'Credit',
        'Status' => 'APPROVED',
        'PPP_TransactionID' => 'ppp_refund',
        'TransactionID' => '8110000000030211270',
        'relatedTransactionId' => 'ppp_orig',
        'totalAmount' => '500',
        'feeAmount' => '12',
        'currency' => 'USD',
    ]);

    expect(new CreditHandler($resolver, $processing, Mockery::mock(RefundFailureRecorder::class), $feeRecorder)($event, $gatewayId))
        ->toBe(HandlerOutcome::Processed);
});

it('skips fee forwarding when our refund id cannot be resolved (avoids creating orphaned fee)', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . substr(uniqid(), 0, 12);

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn($piId);
    $resolver->shouldReceive('resolveRefund')->andReturnNull();

    $processing = Mockery::mock(RefundProcessingRecorder::class);
    $processing->shouldReceive('onRefundProcessed')->once()->andReturn(RecorderOutcome::Applied);

    $feeRecorder = Mockery::mock(GatewayFeeRecorder::class);
    $feeRecorder->shouldNotReceive('onRefundFee');

    $event = new NuveiEvent([
        'transactionType' => 'Credit',
        'Status' => 'APPROVED',
        'PPP_TransactionID' => 'ppp_refund',
        'TransactionID' => '8110000000030211270',
        'relatedTransactionId' => 'ppp_orig',
        'totalAmount' => '500',
        'feeAmount' => '12',
        'currency' => 'USD',
    ]);

    expect(new CreditHandler($resolver, $processing, Mockery::mock(RefundFailureRecorder::class), $feeRecorder)($event, $gatewayId))
        ->toBe(HandlerOutcome::Processed);
});
