<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Nuvei\CaptureRequest;
use Techork\PaymentService\Nuvei\RefundRequest;

it('builds capture data with amount and relatedTransactionId', function () {
    $request = new CaptureRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'transactionReference' => 'auth_ref_123',
    ]);

    $data = $request->getData();

    expect($data['amount'])->toBe('50.00')
        ->and($data['currency'])->toBe('USD')
        ->and($data['relatedTransactionId'])->toBe('auth_ref_123')
        ->and($data)->toHaveKey('clientUniqueId');
});

it('builds refund data with amount and relatedTransactionId', function () {
    $request = new RefundRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('EUR')),
        'transactionReference' => 'charge_ref_456',
    ]);

    $data = $request->getData();

    expect($data['amount'])->toBe('25.00')
        ->and($data['currency'])->toBe('EUR')
        ->and($data['relatedTransactionId'])->toBe('charge_ref_456')
        ->and($data)->toHaveKey('clientUniqueId');
});

it('uses provided clientUniqueId instead of generating one', function () {
    $request = new CaptureRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1000, new Currency('USD')),
        'transactionReference' => 'auth_xyz',
        'clientUniqueId' => 'custom-id-123',
    ]);

    $data = $request->getData();

    expect($data['clientUniqueId'])->toBe('custom-id-123');
});
