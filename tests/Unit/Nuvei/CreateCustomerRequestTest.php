<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Nuvei\CreateCustomerRequest;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;

function nuveiCustomerAddress(): BillingAddress
{
    return new BillingAddress(
        firstName: 'Ada',
        lastName: 'Lovelace',
        line: 'Unter den Linden 1',
        city: 'Berlin',
        country: new Country('DE'),
        postalCode: '10117',
        email: new Email('ada@example.com'),
    );
}

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

it('registers the customer under the name and country it was given', function () {
    // Deliberately not a US address, and deliberately asserting the values rather than the
    // keys. The previous version of this test passed `country => 'US'` and asserted 'US', so
    // it went on passing while the parameter was silently dropped and the request's own
    // fallback supplied the answer — a test that could not fail for the defect it covered.
    //
    // Every field here is read off the BillingAddress. When these keys had no setters, omnipay
    // discarded them and every Nuvei customer was registered as "N/A N/A" in the US.
    $request = new CreateCustomerRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'billingAddress' => nuveiCustomerAddress(),
    ]);

    expect($request->getData())
        ->toHaveKey('userTokenId', 'ada@example.com')
        ->toHaveKey('email', 'ada@example.com')
        ->toHaveKey('firstName', 'Ada')
        ->toHaveKey('lastName', 'Lovelace')
        ->toHaveKey('countryCode', 'DE')
        ->toHaveKey('address', 'Unter den Linden 1')
        ->toHaveKey('city', 'Berlin')
        ->toHaveKey('zip', '10117')
        ->toHaveKey('clientRequestId');
});

it('falls back to the placeholders Nuvei requires only when there is no address at all', function () {
    // The 'N/A' and 'US' defaults stay, because Nuvei marks firstName and lastName required
    // and a placeholder is the honest answer for a name nobody supplied. They are the last
    // resort now rather than what every customer got.
    $request = new CreateCustomerRequest(new OmnipayClient, new HttpRequest);
    $request->initialize(['email' => 'test@example.com']);

    expect($request->getData())
        ->toHaveKey('firstName', 'N/A')
        ->toHaveKey('lastName', 'N/A')
        ->toHaveKey('countryCode', 'US');
});

it('filters out null optional fields', function () {
    $request = new CreateCustomerRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'email' => 'test@example.com',
    ]);

    $data = $request->getData();

    expect($data)->not->toHaveKey('address')
        ->and($data)->not->toHaveKey('city')
        ->and($data)->not->toHaveKey('zip')
        ->and($data)->not->toHaveKey('state');
});
