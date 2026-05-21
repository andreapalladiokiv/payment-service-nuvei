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
        ->and($address->postalCode)->toBe(ShreddingStubs::POSTAL_CODE)
        ->and($address->firstName)->toBe(ShreddingStubs::NAME)
        ->and($address->lastName)->toBe(ShreddingStubs::NAME);
});
