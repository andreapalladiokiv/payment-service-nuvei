<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Nuvei\NuveiTransactionOutcome;
use Techork\PaymentService\Nuvei\VoidTransaction;

/*
 * `it('requires transactionReference')` lived here and cannot come back: the reference is a
 * constructor argument on {@see CancelCommand}, so a void with nothing to void is unconstructable
 * rather than something to validate at send time.
 */

it('builds void data without amount/currency (full void per Nuvei docs)', function () {
    $data = new VoidTransaction(
        nuveiSuiteSettings(),
        new CancelCommand(GatewayId::generate(), 'txn_abc_123'),
    )->payload();

    // The Nuvei PHP SDK incorrectly marks amount/currency as mandatory for
    // /voidTransaction. Per the official API docs they are optional and,
    // when omitted, the gateway voids the original transaction amount in
    // full. Sending them risks a "Invalid Amount" error if any value other
    // than the exact original amount is passed.
    expect($data['relatedTransactionId'])->toBe('txn_abc_123')
        ->and($data)->toHaveKey('clientUniqueId')
        ->and($data)->toHaveKey('clientRequestId')
        ->and($data)->not->toHaveKey('amount')
        ->and($data)->not->toHaveKey('currency');
});

it('reuses clientUniqueId for both clientUniqueId and clientRequestId', function () {
    $data = new VoidTransaction(
        nuveiSuiteSettings(),
        new CancelCommand(GatewayId::generate(), 'txn_abc_123', 'my-custom-id'),
    )->payload();

    expect($data['clientUniqueId'])->toBe('my-custom-id')
        ->and($data['clientRequestId'])->toBe('my-custom-id');
});

// ──────────────────────────────────────────────
//  what a real void answer means
//
//  Payload below is a real Nuvei sandbox response for POST /voidTransaction.do
//  captured on 2026-05-06. Notable shape: AVSCode and CVV2Reply are PascalCase
//  top-level keys (auth/sale put them under paymentOption.card with camelCase),
//  but they are always empty for void responses so we don't read them anyway.
//
//  The reading itself is {@see NuveiTransactionOutcome}'s and is exercised in full by its own
//  test; what these three rows pin is that a void's answers fold the way a void needs them to.
// ──────────────────────────────────────────────

function nuveiApprovedVoidPayload(): array
{
    return [
        'internalRequestId' => 184075517111,
        'status' => 'SUCCESS',
        'errCode' => 0,
        'reason' => '',
        'merchantId' => '6116870607565500968',
        'merchantSiteId' => '245388',
        'version' => '1.0',
        'clientRequestId' => 'void-amt-1778065563',
        'transactionId' => '8110000000029314234',
        'externalTransactionId' => '',
        'gwErrorCode' => 0,
        'gwExtendedErrorCode' => 0,
        'transactionStatus' => 'APPROVED',
        'authCode' => '111187',
        'clientUniqueId' => 'cuid-voidamt-1778065563',
        'transactionType' => 'Void',
        'orderId' => '16700803111',
    ];
}

it('marks an approved void as successful', function () {
    $result = new NuveiTransactionOutcome(nuveiApprovedVoidPayload())->toGatewayResult();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('8110000000029314234');
});

it('marks an exception-bridge payload as not successful', function () {
    // Shape produced when the SDK throws before a reply exists (e.g. ValidationException for
    // missing fields) — the operation substitutes it rather than letting the exception out.
    $result = NuveiTransactionOutcome::fromThrowable(
        new RuntimeException('Missing input parameters: amount,currency'),
    )->toGatewayResult();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Missing input parameters: amount,currency');
});

it('marks an APPROVED-but-not-SUCCESS response as not successful', function () {
    // Defensive: status SUCCESS only at protocol level; transactionStatus
    // ERROR means Nuvei rejected the void (e.g. Invalid Amount when caller
    // sent a wrong amount). Success must require both.
    $result = new NuveiTransactionOutcome([
        'status' => 'SUCCESS',
        'transactionId' => '8110000000029314606',
        'transactionStatus' => 'ERROR',
        'gwErrorCode' => -1100,
        'gwErrorReason' => 'Invalid Amount',
    ])->toGatewayResult();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Invalid Amount');
});
