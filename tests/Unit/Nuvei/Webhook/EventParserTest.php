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

it('routes an event DMN — a Control Panel event, which states no transaction type — by its own event type', function () {
    // The shape a Chargeback delivery arrives in: it is JSON, it carries the envelope's `EventType`, and
    // it has no `transactionType` at all — so before this branch existed it took the payment path and
    // came out with type `''` and an id built from `uniqid()`. Both halves of that were silent: the
    // registry answers null for an unknown type and the router reports Skipped, which is what it also
    // reports for a delivery it has genuinely finished with.
    $parsed = (new EventParser)->parse([
        'EventType' => 'Chargeback',
        'EventId' => '1000000001',
        'Chargeback' => ['DisputeId' => 'dc_1'],
    ]);

    expect($parsed->type)->toBe(EventParser::TYPE_CHARGEBACK)
        // The envelope's id, not the dispute suite's `Chargeback.DisputeEventId` — this layer identifies
        // a *delivery*, and the aggregate's idempotency key is the dispute suite's own.
        ->and($parsed->externalId)->toBe('Chargeback:1000000001')
        ->and($parsed->native)->toBeInstanceOf(NuveiEvent::class);
});

it('synthesizes an externalId for an event DMN that carries no EventId', function () {
    // The same fallback the payment path applies to a missing `PPP_TransactionID`, with the same cost:
    // this layer can no longer recognise a redelivery. The aggregate still can, because the snapshot
    // carries `Chargeback.DisputeEventId` and refuses a blank one.
    $parsed = (new EventParser)->parse(['EventType' => 'Chargeback']);

    expect($parsed->type)->toBe('Chargeback')
        ->and($parsed->externalId)->toStartWith('Chargeback:nuvei_');
});

it('reads a delivery that carries both fields as the payment DMN it also is', function () {
    // A shape neither channel documents. The payment path wins because that is the branch that reads
    // `transactionType` and nothing else, and the alternative — letting an `EventType` reroute a payment
    // DMN — would take a delivery that verifies and resolves today and hand it to a handler written for
    // another vocabulary entirely. Pinned so the precedence is a decision rather than an accident of
    // the order the two `if`s were written in.
    $parsed = (new EventParser)->parse([
        'transactionType' => 'Sale',
        'EventType' => 'Chargeback',
        'totalAmount' => '10.00',
        'PPP_TransactionID' => 'ppp_999',
    ]);

    expect($parsed->type)->toBe(EventParser::TYPE_SALE)
        ->and($parsed->externalId)->toBe('Sale:ppp_999');
});
