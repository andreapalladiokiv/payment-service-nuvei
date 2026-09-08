<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Nuvei\CreateCustomer;

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

it('calls Nuvei rather than answering a no-op success', function () {
    // The operation exists to register a user at the acquirer; a version of it that answered
    // success without leaving the process would hand back a userTokenId Nuvei has never heard of,
    // and the first payment quoting it would be rejected.
    $result = new CreateCustomer(
        nuveiSuiteSettings(['restClient' => nuveiSuiteUnreachableClient()]),
        email: 'test@example.com',
    )->create();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('Connection timed out');
});

it('answers with the email it registered, because for Nuvei that IS the reference', function () {
    $calls = [];

    $result = new CreateCustomer(
        nuveiSuiteSettings(['restClient' => nuveiSuiteRecordingClient($calls, ['status' => 'SUCCESS'])]),
        nuveiCustomerAddress(),
    )->create();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/createUser.do')
        ->and($result->success)->toBeTrue()
        ->and($result->reference)->toBe('ada@example.com');
});

it('reports Nuvei\'s reason when the user was not created', function () {
    $calls = [];

    $result = new CreateCustomer(
        nuveiSuiteSettings(['restClient' => nuveiSuiteRecordingClient($calls, ['status' => 'ERROR', 'reason' => 'User already exists'])]),
        nuveiCustomerAddress(),
    )->create();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('User already exists');
});

it('registers the customer under the name and country it was given', function () {
    // Deliberately not a US address, and deliberately asserting the values rather than the
    // keys. The previous version of this test passed `country => 'US'` and asserted 'US', so
    // it went on passing while the parameter was silently dropped and the request's own
    // fallback supplied the answer — a test that could not fail for the defect it covered.
    //
    // Every field here is read off the BillingAddress. When these keys had no setters, omnipay
    // discarded them and every Nuvei customer was registered as "N/A N/A" in the US.
    expect(new CreateCustomer(nuveiSuiteSettings(), nuveiCustomerAddress())->payload())
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
    expect(new CreateCustomer(nuveiSuiteSettings(), email: 'test@example.com')->payload())
        ->toHaveKey('firstName', 'N/A')
        ->toHaveKey('lastName', 'N/A')
        ->toHaveKey('countryCode', 'US');
});

it('refuses a customer with no email before the call leaves, as the SDK always did', function () {
    // `array_filter` drops an empty email, so `userTokenId` and `email` fall out of the body — and
    // the SDK's own `createUser()` marks both mandatory and throws `ValidationException` before
    // anything is sent. That is why the unguarded `$data['userTokenId']` read in the old
    // `sendData()` — an undefined-key Warning and a null reference — was never actually reached.
    // The mapping for it is preserved in the operation anyway (a failure with a null reference and
    // NO message, matching what that response would have carried), but this is the answer a caller
    // gets: the SDK's refusal, folded into a failed result rather than thrown.
    $calls = [];

    $result = new CreateCustomer(
        nuveiSuiteSettings(['restClient' => nuveiSuiteRecordingClient($calls, ['status' => 'SUCCESS'])]),
    )->create();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toContain('userTokenId')
        // Nothing left the process, which is the point of the SDK's client-side check.
        ->and($calls)->toBeEmpty();
});

it('filters out null optional fields', function () {
    $data = new CreateCustomer(nuveiSuiteSettings(), email: 'test@example.com')->payload();

    expect($data)->not->toHaveKey('address')
        ->and($data)->not->toHaveKey('city')
        ->and($data)->not->toHaveKey('zip')
        ->and($data)->not->toHaveKey('state');
});
