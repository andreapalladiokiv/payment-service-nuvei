<?php

declare(strict_types=1);

use Techork\PaymentService\Nuvei\Webhook\DTO\NuveiEvent;
use Techork\PaymentService\Nuvei\Webhook\EventParser;

it('parses a regular Auth DMN', function () {
    $parsed = (new EventParser)->parse([
        'transactionType' => 'Auth',
        'totalAmount' => '10.00',
        'PPP_TransactionID' => 'ppp_123',
    ]);

    expect($parsed->type)->toBe(EventParser::TYPE_AUTH)
        ->and($parsed->externalId)->toBe('Auth:ppp_123')
        ->and($parsed->native)->toBeInstanceOf(NuveiEvent::class);
});

it('routes zero-amount Auth to the PaymentMethod tokenization event type', function () {
    $parsed = (new EventParser)->parse([
        'transactionType' => 'Auth',
        'totalAmount' => '0',
        'PPP_TransactionID' => 'ppp_456',
    ]);

    expect($parsed->type)->toBe(EventParser::TYPE_AUTH_PAYMENT_METHOD);
});

it('preserves Settle / Credit / Void transaction types', function () {
    foreach (['Settle', 'Credit', 'Void'] as $type) {
        $parsed = (new EventParser)->parse([
            'transactionType' => $type,
            'totalAmount' => '100',
            'PPP_TransactionID' => 'ppp_x',
        ]);
        expect($parsed->type)->toBe($type);
    }
});

it('synthesizes an externalId when PPP_TransactionID is missing', function () {
    $parsed = (new EventParser)->parse(['transactionType' => 'Auth', 'totalAmount' => '10']);

    expect($parsed->externalId)->toStartWith('Auth:nuvei_');
});
