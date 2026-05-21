<?php

declare(strict_types=1);

use Techork\PaymentService\Nuvei\CreateCustomerRequest;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;

it('fails when no RestClient is available instead of silently succeeding', function () {
    $request = new CreateCustomerRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'email' => 'test@example.com',
        'country' => 'US',
    ]);

    $data = $request->getData();
    $response = $request->sendData($data);

    expect($response->isSuccessful())->toBeFalse('CreateCustomerRequest must call Nuvei API, not return a no-op success');
});

it('includes userTokenId and countryCode in request data', function () {
    $request = new CreateCustomerRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'email' => 'test@example.com',
        'country' => 'US',
        'address' => '123 Test St',
        'city' => 'Miami',
    ]);

    $data = $request->getData();

    expect($data)
        ->toHaveKey('userTokenId', 'test@example.com')
        ->toHaveKey('countryCode', 'US')
        ->toHaveKey('email', 'test@example.com')
        ->toHaveKey('firstName')
        ->toHaveKey('lastName')
        ->toHaveKey('clientRequestId');
});

it('filters out null optional fields', function () {
    $request = new CreateCustomerRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'email' => 'test@example.com',
        'country' => 'US',
    ]);

    $data = $request->getData();

    expect($data)->not->toHaveKey('address')
        ->and($data)->not->toHaveKey('city')
        ->and($data)->not->toHaveKey('zip')
        ->and($data)->not->toHaveKey('state');
});
