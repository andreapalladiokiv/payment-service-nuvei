<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Nuvei\CreateCustomer;

function nuveiCustomerIdentity(): CustomerIdentity
{
    return new CustomerIdentity('Ada', 'Lovelace', new Email('ada@example.com'));
}

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
        nuveiSuiteCustomerId(),
        nuveiCustomerIdentity(),
    )->create();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('Connection timed out');
});

/**
 * The reference for a Nuvei user genuinely is the token WE chose, so the operation answers with
 * our customer id. It used to answer with the payer's email, because that is what it sent as the
 * token — and Nuvei documents `userTokenId` as the id that "uniquely identifies your
 * consumer/user in your system", so an email there made a change of address into a change of
 * person and orphaned every payment option stored under the old one.
 */
it('answers with our customer id, because that is the token it registered', function () {
    $calls = [];

    $result = new CreateCustomer(
        nuveiSuiteSettings(['restClient' => nuveiSuiteRecordingClient($calls, ['status' => 'SUCCESS'])]),
        nuveiSuiteCustomerId(),
        nuveiCustomerIdentity(),
        nuveiCustomerAddress(),
    )->create();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/createUser.do')
        ->and($result->success)->toBeTrue()
        ->and($result->reference)->toBe(nuveiSuiteCustomerId()->toString());
});

/**
 * The token does not move when the email does, which is the whole point of minting it ourselves.
 */
it('sends the same token for two identities that differ only by email', function () {
    $tokens = [];

    foreach (['first@example.com', 'second@example.com'] as $email) {
        $tokens[] = new CreateCustomer(
            nuveiSuiteSettings(),
            nuveiSuiteCustomerId(),
            new CustomerIdentity('Ada', 'Lovelace', new Email($email)),
        )->payload()['userTokenId'];
    }

    expect($tokens)->toBe([nuveiSuiteCustomerId()->toString(), nuveiSuiteCustomerId()->toString()]);
});

it('reports Nuvei\'s reason when the user was not created', function () {
    $calls = [];

    $result = new CreateCustomer(
        nuveiSuiteSettings(['restClient' => nuveiSuiteRecordingClient($calls, ['status' => 'ERROR', 'reason' => 'User already exists'])]),
        nuveiSuiteCustomerId(),
        nuveiCustomerIdentity(),
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
    // The person comes from the identity and the place from the address. When these keys had no
    // setters, omnipay discarded them and every Nuvei customer was registered as "N/A N/A" in
    // the US.
    expect(new CreateCustomer(nuveiSuiteSettings(), nuveiSuiteCustomerId(), nuveiCustomerIdentity(), nuveiCustomerAddress())->payload())
        ->toHaveKey('userTokenId', nuveiSuiteCustomerId()->toString())
        ->toHaveKey('email', 'ada@example.com')
        ->toHaveKey('firstName', 'Ada')
        ->toHaveKey('lastName', 'Lovelace')
        ->toHaveKey('countryCode', 'DE')
        ->toHaveKey('address', 'Unter den Linden 1')
        ->toHaveKey('city', 'Berlin')
        ->toHaveKey('zip', '10117')
        ->toHaveKey('clientRequestId');
});

it('falls back to the placeholders Nuvei requires only when there is nobody to name', function () {
    // The 'N/A' and 'US' defaults stay, because Nuvei marks firstName and lastName required
    // and a placeholder is the honest answer for a name nobody supplied. They are the last
    // resort now rather than what every customer got.
    expect(new CreateCustomer(nuveiSuiteSettings(), nuveiSuiteCustomerId())->payload())
        ->toHaveKey('firstName', 'N/A')
        ->toHaveKey('lastName', 'N/A')
        ->toHaveKey('countryCode', 'US');
});

/**
 * With nobody named, the address still answers for the person — it is where the payer's name and
 * email have been kept all along, so it is the honest reading of what we have. It stops being
 * consulted the moment an identity arrives.
 */
it('reads the person off the address when no identity was passed', function () {
    expect(new CreateCustomer(nuveiSuiteSettings(), nuveiSuiteCustomerId(), null, nuveiCustomerAddress())->payload())
        ->toHaveKey('firstName', 'Ada')
        ->toHaveKey('lastName', 'Lovelace')
        ->toHaveKey('email', 'ada@example.com');
});

it('refuses a customer with no token before the call leaves, as the SDK always did', function () {
    // `array_filter` drops an empty token, so `userTokenId` falls out of the body — and the SDK's
    // own `createUser()` marks it mandatory and throws `ValidationException` before anything is
    // sent. This is the answer a caller gets: the SDK's refusal, folded into a failed result
    // rather than thrown.
    //
    // Unreachable through the gateway, which refuses an empty customer id with
    // {@see \Techork\PaymentService\Gateway\Exception\RegistrationNeedsCustomer} first. It used
    // to be reachable, and by the commonest route there was: the token was the email, the email
    // was optional on our side, so a customer with no email had no token.
    $calls = [];

    $result = new CreateCustomer(
        nuveiSuiteSettings(['restClient' => nuveiSuiteRecordingClient($calls, ['status' => 'SUCCESS'])]),
        nuveiSuiteCustomerId(''),
        nuveiCustomerIdentity(),
    )->create();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toContain('userTokenId')
        // Nothing left the process, which is the point of the SDK's client-side check.
        ->and($calls)->toBeEmpty();
});

it('filters out null optional fields', function () {
    $data = new CreateCustomer(nuveiSuiteSettings(), nuveiSuiteCustomerId(), nuveiCustomerIdentity())->payload();

    expect($data)->not->toHaveKey('address')
        ->and($data)->not->toHaveKey('city')
        ->and($data)->not->toHaveKey('zip')
        ->and($data)->not->toHaveKey('state');
});
