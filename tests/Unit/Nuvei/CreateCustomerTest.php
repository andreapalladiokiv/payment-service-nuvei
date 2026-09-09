<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Customer;
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
        line: 'Unter den Linden 1',
        city: 'Berlin',
        country: new Country('DE'),
        postalCode: '10117',
    );
}

/**
 * The payer this operation now takes, from the loose parts these tests were written around.
 *
 * `CreateCustomer` used to take the id, the identity and the address as three arguments — the two
 * optional — so the tests could name any subset. It takes one {@see Customer}; the parts are still
 * distinct inside it, they are simply no longer separately omissible.
 */
function nuveiCustomerFor(?CustomerIdentity $identity = null, ?BillingAddress $address = null): Customer
{
    return nuveiSuiteCustomer(
        firstName: $identity?->firstName ?? 'Ada',
        lastName: $identity?->lastName ?? 'Lovelace',
        email: $identity?->email,
        phone: $identity?->phone,
        address: $address ?? BillingAddress::unknown(),
    );
}

it('calls Nuvei rather than answering a no-op success', function () {
    // The operation exists to register a user at the acquirer; a version of it that answered
    // success without leaving the process would hand back a userTokenId Nuvei has never heard of,
    // and the first payment quoting it would be rejected.
    $result = new CreateCustomer(
        nuveiSuiteSettings(['restClient' => nuveiSuiteUnreachableClient()]),
        nuveiCustomerFor(identity: nuveiCustomerIdentity()),
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
        nuveiCustomerFor(identity: nuveiCustomerIdentity(), address: nuveiCustomerAddress()),
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
            nuveiCustomerFor(identity: new CustomerIdentity('Ada', 'Lovelace', new Email($email))),
        )->payload()['userTokenId'];
    }

    expect($tokens)->toBe([nuveiSuiteCustomerId()->toString(), nuveiSuiteCustomerId()->toString()]);
});

it('reports Nuvei\'s reason when the user was not created', function () {
    $calls = [];

    $result = new CreateCustomer(
        nuveiSuiteSettings(['restClient' => nuveiSuiteRecordingClient($calls, ['status' => 'ERROR', 'reason' => 'User already exists'])]),
        nuveiCustomerFor(identity: nuveiCustomerIdentity(), address: nuveiCustomerAddress()),
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
    expect(new CreateCustomer(nuveiSuiteSettings(), nuveiCustomerFor(nuveiCustomerIdentity(), nuveiCustomerAddress()))->payload())
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

/**
 * The `'N/A'` and `'US'` defaults are gone, and what replaced them is a caller that has to say
 * what it means.
 *
 * They existed because Nuvei marks `firstName`, `lastName` and `countryCode` required while all
 * three arrived through keys omnipay silently dropped — so every customer registered as "N/A N/A"
 * in the US. A {@see Customer} has a name and a country, so there is no absence left to
 * substitute for: where either is genuinely unknown the caller says so with the shredding stub
 * and `ZZ`, which is the same statement made somewhere that knows whether it is true.
 *
 * The test that used to sit beside this one — `it('reads the person off the address when no
 * identity was passed')` — described the fallback the whole split removes, and its premise is no
 * longer expressible.
 */
it('sends what the customer says rather than a placeholder of its own', function () {
    $unknown = new CreateCustomer(nuveiSuiteSettings(), nuveiSuiteCustomer(
        firstName: ShreddingStubs::NAME,
        lastName: ShreddingStubs::NAME,
        address: BillingAddress::unknown(),
    ))->payload();

    expect($unknown)
        ->toHaveKey('firstName', ShreddingStubs::NAME)
        ->toHaveKey('lastName', ShreddingStubs::NAME)
        ->toHaveKey('countryCode', ShreddingStubs::COUNTRY);
});

/**
 * An empty token is not expressible any anymore, which is a better answer than the one this test
 * used to record.
 *
 * It pinned the SDK's own refusal: `array_filter` dropped an empty `userTokenId`, `createUser()`
 * marks the field mandatory, and the `ValidationException` came back folded into a failed result.
 * That was reachable by the commonest route there was — the token WAS the payer's email, and an
 * email was optional on our side, so a customer with no email had no token. The token is a
 * {@see \Techork\PaymentService\Common\ValueObject\CustomerId} now and refuses the empty
 * string on construction, so the operation cannot be reached in that state at all.
 */
it('cannot be given a customer with no token', function () {
    expect(fn () => nuveiSuiteCustomerId(''))->toThrow(InvalidArgumentException::class);
});

it('filters out null optional fields', function () {
    // Only `state` can be genuinely absent now: an address is required on a customer, so the
    // street, city and zip are always present — as the stub when nobody gave them, which
    // `array_filter` keeps because a stub is a value.
    $data = new CreateCustomer(nuveiSuiteSettings(), nuveiCustomerFor(nuveiCustomerIdentity()))->payload();

    expect($data)->not->toHaveKey('state')
        ->and($data)->toHaveKey('address')
        ->and($data)->toHaveKey('city');
});
