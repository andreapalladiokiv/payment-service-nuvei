<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Nuvei\Capture;
use Techork\PaymentService\Nuvei\Refund;

/**
 * {@see Capture} and {@see Refund} are the two operations that act on a transaction Nuvei already
 * holds, so they name an amount and the reference of what they act on and nothing else. That body
 * used to be an abstract request the two extended; it is a helper on the shared trait now, and
 * these tests pin that both still build it and that only capture adds a transaction type.
 */
it('builds capture data with amount and relatedTransactionId', function () {
    $data = new Capture(nuveiSuiteSettings(), new CaptureCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'auth_ref_123',
        amount: new Money(5000, new Currency('USD')),
    ))->payload();

    expect($data['amount'])->toBe('50.00')
        ->and($data['currency'])->toBe('USD')
        ->and($data['relatedTransactionId'])->toBe('auth_ref_123')
        ->and($data)->toHaveKey('clientUniqueId');
});

it('builds refund data with amount and relatedTransactionId', function () {
    $data = new Refund(nuveiSuiteSettings(), new RefundCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'charge_ref_456',
        amount: new Money(2500, new Currency('EUR')),
    ))->payload();

    expect($data['amount'])->toBe('25.00')
        ->and($data['currency'])->toBe('EUR')
        ->and($data['relatedTransactionId'])->toBe('charge_ref_456')
        ->and($data)->toHaveKey('clientUniqueId');
});

it('uses provided clientUniqueId instead of generating one', function () {
    $data = new Capture(nuveiSuiteSettings(), new CaptureCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'auth_xyz',
        amount: new Money(1000, new Currency('USD')),
        clientUniqueId: 'custom-id-123',
    ))->payload();

    expect($data['clientUniqueId'])->toBe('custom-id-123');
});

/**
 * The transaction type is part of the capture's payload rather than something bolted on while
 * sending it. `settleTransaction.do` takes a `transactionType` and it has to say `Settle`; when the
 * field was added inside `sendData()` the payload a test could inspect was not the payload that
 * went out, and a separate wire-level test had to exist purely to close that gap.
 */
it('declares the settle type in the capture payload and nowhere in the refund', function () {
    $capture = new Capture(nuveiSuiteSettings(), new CaptureCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'auth_ref_123',
        amount: new Money(5000, new Currency('USD')),
    ))->payload();

    $refund = new Refund(nuveiSuiteSettings(), new RefundCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'charge_ref_456',
        amount: new Money(2500, new Currency('EUR')),
    ))->payload();

    expect($capture['transactionType'])->toBe('Settle')
        ->and($refund)->not->toHaveKey('transactionType');
});

// ──────────────────────────────────────────────
//  what reaches the wire
//
//  Driven through a recording transport rather than by reading the payload alone: both operations
//  fold every Throwable into a failed result, so one that never got out would still answer in the
//  right shape. The recorded call is what rules that out — and the endpoint each picks is the whole
//  difference between capturing an authorization and refunding a settlement.
// ──────────────────────────────────────────────

it('captures through settleTransaction, restating the type in the body', function () {
    $calls = [];

    $result = new Capture(
        nuveiSuiteSettings(['restClient' => nuveiSuiteRecordingClient($calls)]),
        new CaptureCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: '1110000000123456',
            amount: new Money(2500, new Currency('USD')),
            clientUniqueId: 'cuid-capture',
        ),
    )->capture();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/settleTransaction.do')
        ->and($calls[0]['params']['transactionType'])->toBe('Settle')
        ->and($calls[0]['params']['relatedTransactionId'])->toBe('1110000000123456')
        ->and($calls[0]['params']['amount'])->toBe('25.00')
        ->and($result->success)->toBeTrue()
        ->and($result->reference)->toBe('txn-1')
        // A settle records no opening reference: `reference` is overwritten on transition, and
        // writing this key here would bury the authorization's under the settle's own.
        ->and($result->metadata)->toBe([]);
});

it('refunds through refundTransaction, adding no transaction type of its own', function () {
    // Unlike capture, the refund sends the body untouched — the endpoint is the whole declaration.
    // Pinned in the negative too: a transactionType borrowed from the capture would be a field
    // Nuvei does not expect on this call.
    $calls = [];

    $result = new Refund(
        nuveiSuiteSettings(['restClient' => nuveiSuiteRecordingClient($calls)]),
        new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: '1110000000654321',
            amount: new Money(1000, new Currency('EUR')),
            clientUniqueId: 'cuid-refund',
        ),
    )->refund();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/refundTransaction.do')
        ->and($calls[0]['params'])->not->toHaveKey('transactionType')
        ->and($calls[0]['params']['relatedTransactionId'])->toBe('1110000000654321')
        ->and($calls[0]['params']['currency'])->toBe('EUR')
        ->and($result->success)->toBeTrue();
});

it('answers with a failed outcome of its own when the call never leaves', function (string $operation) {
    // The acquirer is unreachable, so no reply exists to read. Each operation has to substitute a
    // failure rather than let the exception out: the gateway stack folds a raw Throwable into what
    // the merchant reads as an acquirer decline, which would make an unreachable gateway
    // indistinguishable from a rejected refund.
    $settings = nuveiSuiteSettings(['restClient' => nuveiSuiteUnreachableClient()]);

    $result = $operation === 'capture'
        ? new Capture($settings, new CaptureCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: '1110000000123456',
            amount: new Money(1000, new Currency('USD')),
        ))->capture()
        : new Refund($settings, new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: '1110000000123456',
            amount: new Money(1000, new Currency('USD')),
        ))->refund();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('Connection timed out');
})->with(['capture', 'refund']);
