<?php

declare(strict_types=1);

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Nuvei\VoidRequest;

it('builds void data without amount/currency (full void per Nuvei docs)', function () {
    $request = new VoidRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'txn_abc_123',
    ]);

    $data = $request->getData();

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
    $request = new VoidRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'txn_abc_123',
        'clientUniqueId' => 'my-custom-id',
    ]);

    $data = $request->getData();

    expect($data['clientUniqueId'])->toBe('my-custom-id')
        ->and($data['clientRequestId'])->toBe('my-custom-id');
});

it('requires transactionReference', function () {
    $request = new VoidRequest(new OmnipayClient, new HttpRequest);
    $request->initialize();

    $request->getData();
})->throws(InvalidRequestException::class);
