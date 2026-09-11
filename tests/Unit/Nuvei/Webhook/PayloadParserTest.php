<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Nuvei\Webhook\PayloadParser;

it('parses a credit card from a Nuvei DMN payload', function () {
    $card = PayloadParser::creditCard([
        'bin' => '424242',
        'last4Digits' => '4242',
        'brand' => 'visa',
        'ccExpMonth' => '12',
        'ccExpYear' => '2030',
        'nameOnCard' => 'Jane Doe',
    ]);

    expect($card)->not->toBeNull()
        ->and($card->number->last4)->toBe('4242')
        ->and($card->number->first6)->toBe('424242')
        ->and((string)$card->holder)->toBe('Jane Doe');
});

it('returns null when required card fields are missing', function () {
    expect(PayloadParser::creditCard(['last4Digits' => '4242']))->toBeNull();
});

it('returns null rather than throwing when the expiry is an unreadable date', function () {
    // A malformed DMN must not kill the handler — it already knows the card fields
    // it could not parse come back null.
    expect(PayloadParser::creditCard([
        'last4Digits' => '4242',
        'cardCompany' => 'Visa',
        'ccExpMonth' => '13',
        'ccExpYear' => '2030',
    ]))->toBeNull();
});

it('parses a billing address', function () {
    $address = PayloadParser::billingAddress([
        'firstName' => 'Jane',
        'lastName' => 'Doe',
        'address' => '1 Main',
        'city' => 'NYC',
        'country' => 'US',
        'zip' => '10001',
        'state' => 'NY',
        'email' => 'jane@example.com',
    ]);

    expect($address)->not->toBeNull()
        ->and($address->line)->toBe('1 Main')
        ->and($address->city)->toBe('NYC')
        ->and($address->postalCode)->toBe('10001');
});

it('fills shredding stubs when required address fields are missing', function () {
    $address = PayloadParser::billingAddress(['city' => 'NYC']);

    expect($address->city)->toBe('NYC')
        ->and($address->line)->toBe(ShreddingStubs::ADDRESS_LINE)
        ->and((string) $address->country)->toBe(ShreddingStubs::COUNTRY)
        ->and($address->postalCode)->toBe(ShreddingStubs::POSTAL_CODE);
});

it('records an unresolvable country as no data rather than throwing', function () {
    // `ZZZ` is a well-formed alpha-3 Symfony Intl cannot resolve; the row must
    // survive with the same "no data" marker an absent country gets.
    $address = PayloadParser::billingAddress(['country' => 'ZZZ']);

    expect((string) $address->country)->toBe(ShreddingStubs::COUNTRY);
});

/**
 * The payer Nuvei's billing block names, read separately from the place.
 *
 * One parser call used to return both, because a `BillingAddress` held a name and an email — so
 * a webhook recorded the address and the person as one thing, and the recorder could not tell a
 * caller which of the two it had. Same stub treatment on this half: a name Nuvei did not send
 * reads as "no data" rather than being left out.
 */
it('parses the payer separately, with stubs for what Nuvei did not send', function () {
    $named = PayloadParser::customerIdentity([
        'firstName' => 'Jane',
        'lastName' => 'Doe',
        'email' => 'jane@example.com',
    ]);

    expect($named->firstName)->toBe('Jane')
        ->and($named->lastName)->toBe('Doe')
        ->and((string) $named->email)->toBe('jane@example.com');

    $unnamed = PayloadParser::customerIdentity(['city' => 'NYC']);

    expect($unnamed->firstName)->toBe(ShreddingStubs::NAME)
        ->and($unnamed->lastName)->toBe(ShreddingStubs::NAME)
        ->and($unnamed->email)->toBeNull();
});

it('reads a malformed email as no email rather than throwing', function () {
    $identity = PayloadParser::customerIdentity([
        'firstName' => 'Jane',
        'lastName' => 'Doe',
        'email' => 'not-an-email',
    ]);

    expect($identity->firstName)->toBe('Jane')
        ->and($identity->email)->toBeNull();
});
