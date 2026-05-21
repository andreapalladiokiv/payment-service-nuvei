<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\Nuvei\VoidResponse;

/**
 * Payload below is a real Nuvei sandbox response for POST /voidTransaction.do
 * captured on 2026-05-06. Notable shape: AVSCode and CVV2Reply are PascalCase
 * top-level keys (auth/sale put them under paymentOption.card with camelCase),
 * but they are always empty for void responses so we don't read them anyway.
 */
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

it('marks an approved void response as successful', function () {
    $response = new VoidResponse(Mockery::mock(RequestInterface::class), nuveiApprovedVoidPayload());

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('8110000000029314234');
});

it('marks an exception-bridge payload as not successful', function () {
    // Shape produced by VoidRequest::sendData() catch block when the SDK
    // throws (e.g. ValidationException for missing fields).
    $response = new VoidResponse(Mockery::mock(RequestInterface::class), [
        'status' => 'ERROR',
        'reason' => 'Missing input parameters: amount,currency',
    ]);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Missing input parameters: amount,currency');
});

it('marks an APPROVED-but-not-SUCCESS response as not successful', function () {
    // Defensive: status SUCCESS only at protocol level; transactionStatus
    // ERROR means Nuvei rejected the void (e.g. Invalid Amount when caller
    // sent a wrong amount). isSuccessful() must require both.
    $response = new VoidResponse(Mockery::mock(RequestInterface::class), [
        'status' => 'SUCCESS',
        'transactionId' => '8110000000029314606',
        'transactionStatus' => 'ERROR',
        'gwErrorCode' => -1100,
        'gwErrorReason' => 'Invalid Amount',
    ]);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Invalid Amount');
});
