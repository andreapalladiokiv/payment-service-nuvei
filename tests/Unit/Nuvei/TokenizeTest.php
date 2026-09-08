<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Nuvei\NuveiTransactionOutcome;
use Techork\PaymentService\Nuvei\Tokenize;

/**
 * @param  array<string, mixed>  $overrides
 */
function nuveiTokenization(PaymentInstrument $instrument, array $overrides = []): Tokenize
{
    return new Tokenize(
        nuveiSuiteSettings($overrides),
        nuveiSuiteInfrastructure(['decrypter' => $overrides['decrypter'] ?? nuveiSuiteDecrypter()]),
        new VaultCommand(GatewayId::generate(), $instrument),
    );
}

// ──────────────────────────────────────────────
//  the body
// ──────────────────────────────────────────────

it('builds card tokenization data for credit card', function () {
    $card = new CreditCard(
        Number::fromNumber('4761344136141390', nuveiSuiteEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Jane Doe'),
        Cvc::fromCvc('999', nuveiSuiteEncrypter()),
    );

    $data = nuveiTokenization($card)->payload();

    expect($data['cardData']['cardNumber'])->toBe('4761344136141390')
        ->and($data['cardData']['cardHolderName'])->toBe('Jane Doe')
        ->and($data['cardData']['expirationMonth'])->toBe('12')
        ->and($data['cardData']['expirationYear'])->toBe('2030')
        ->and($data['cardData']['CVV'])->toBe('999');
});

it('omits empty holder and CVV from card data', function () {
    $card = new CreditCard(
        Number::fromNumber('4761344136141390', nuveiSuiteEncrypter()),
        Expiration::fromMonthAndYear(6, 2028),
        new Holder(''),
        new Cvc,
    );

    $data = nuveiTokenization($card)->payload();

    expect($data['cardData'])->not->toHaveKey('cardHolderName')
        ->and($data['cardData'])->not->toHaveKey('CVV')
        ->and($data['cardData']['cardNumber'])->toBe('4761344136141390');
});

it('throws on token instrument', function () {
    $token = new Token(
        TokenId::generate(),
        new CreditCard(new Number('476134', '1390', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    nuveiTokenization($token)->payload();
})->throws(RuntimeException::class, 'Token does not support tokenization');

it('throws on cash instrument', function () {
    nuveiTokenization(new Cash)->payload();
})->throws(ValueError::class, 'Nuvei does not support cash');

// ──────────────────────────────────────────────
//  the answer
//
//  This reading decides whether a card was tokenized, so a wrong one either stores a reference the
//  acquirer never issued or throws away one it did. It used to be a response class of its own; the
//  cases below drive the operation instead, through a transport that answers with the payload each
//  row names.
// ──────────────────────────────────────────────

/**
 * @param  array<string, mixed>  $reply
 */
function nuveiTokenizationAnswering(array $reply): Techork\PaymentService\Gateway\Contract\RegistrationResult
{
    $calls = [];

    $card = new CreditCard(
        Number::fromNumber('4761344136141390', nuveiSuiteEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Jane Doe'),
        Cvc::fromCvc('999', nuveiSuiteEncrypter()),
    );

    return nuveiTokenization($card, ['restClient' => nuveiSuiteRecordingClient($calls, $reply)])->tokenize();
}

it('tokenizes through cardTokenization.do, carrying the session the settings hold', function () {
    $calls = [];

    $card = new CreditCard(
        Number::fromNumber('4761344136141390', nuveiSuiteEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Jane Doe'),
        Cvc::fromCvc('999', nuveiSuiteEncrypter()),
    );

    $client = nuveiSuiteRecordingClient($calls, ['status' => 'SUCCESS', 'ccTempToken' => 'tok_abc']);
    $result = nuveiTokenization($card, ['restClient' => $client, 'sessionToken' => 'sess_1'])->tokenize();

    // The session is a deployment fact the settings carry, not something a command names — Nuvei
    // scopes a temp token to the session it was created in.
    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/cardTokenization.do')
        ->and($calls[0]['params']['sessionToken'])->toBe('sess_1')
        ->and($calls[0]['params']['cardData']['cardNumber'])->toBe('4761344136141390')
        ->and($result->success)->toBeTrue()
        ->and($result->reference)->toBe('tok_abc');
});

it('refuses to call a tokenization successful without the token itself', function () {
    // Nuvei answers SUCCESS on the *call* while omitting ccTempToken when the
    // card data was rejected downstream. Treating that as success would store a
    // null reference against the instrument and fail the first payment that used
    // it — far from the operation that actually failed.
    expect(nuveiTokenizationAnswering(['status' => 'SUCCESS'])->success)->toBeFalse();
});

it('is unsuccessful for every status that is not exactly SUCCESS', function (array $reply) {
    // Exact string match, so casing and Nuvei's other statuses all land on the
    // failure side. 'APPROVED' is included because it is the word the DMN
    // webhook uses for the same event, and copying it here would silently
    // accept declines.
    expect(nuveiTokenizationAnswering($reply)->success)->toBeFalse();
})->with([
    'declined with a token present' => [['status' => 'DECLINED', 'ccTempToken' => 'tok_abc']],
    'the webhook vocabulary, not this one' => [['status' => 'APPROVED', 'ccTempToken' => 'tok_abc']],
    'lower case' => [['status' => 'success', 'ccTempToken' => 'tok_abc']],
    'no status at all' => [['ccTempToken' => 'tok_abc']],
    'empty payload' => [[]],
]);

it('reports a call that never left as a failure carrying its reason', function () {
    // The acquirer is unreachable, so no reply exists to read. The operation substitutes a failure
    // rather than letting the exception out as what would read like a card rejection.
    $card = new CreditCard(
        Number::fromNumber('4761344136141390', nuveiSuiteEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Jane Doe'),
        Cvc::fromCvc('999', nuveiSuiteEncrypter()),
    );

    $result = nuveiTokenization($card, ['restClient' => nuveiSuiteUnreachableClient()])->tokenize();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('Connection timed out');
});

it('casts a numeric temp token to a string rather than leaking an int', function () {
    // Nuvei's JSON has returned ccTempToken unquoted. The cast is what keeps the
    // reference a string all the way into storage, where the column and every
    // consumer are typed on string.
    expect(nuveiTokenizationAnswering(['status' => 'SUCCESS', 'ccTempToken' => 123456789])->reference)
        ->toBe('123456789');
});

it('has no reference when no token was issued', function () {
    // Null, not ''. An empty-string reference would be stored as a real link to
    // a token that does not exist; null is what the caller checks for.
    expect(nuveiTokenizationAnswering(['status' => 'ERROR', 'reason' => 'boom'])->reference)->toBeNull();
});

it('refuses a success whose token is the empty string', function () {
    // Empty counts as absent for the same reason a missing one does: nothing could ever pay with
    // it, so it must not be stored as a link.
    expect(nuveiTokenizationAnswering(['status' => 'SUCCESS', 'ccTempToken' => ''])->message)
        ->toBe(GatewayResult::UNNAMED_SUCCESS);
});

it('prefers the human reason over the error code in its message', function () {
    // Both arrive together on a real Nuvei rejection. The reason is what an
    // operator can act on; the code alone is not.
    expect(nuveiTokenizationAnswering(['status' => 'ERROR', 'reason' => 'Invalid card number', 'errCode' => 1102])->message)
        ->toBe('Invalid card number');
});

it('reports a genuine error code whether Nuvei quoted it or not', function () {
    // The cast the sibling reference always had: Nuvei sends errCode unquoted, and a `?string`
    // method returning it raw used to raise a TypeError.
    expect(nuveiTokenizationAnswering(['status' => 'ERROR', 'errCode' => 1102])->message)->toBe('1102')
        ->and(nuveiTokenizationAnswering(['status' => 'ERROR', 'errCode' => '1102'])->message)->toBe('1102');
});

it('says nothing for the zero error code that marks a clean answer', function () {
    // What actually reaches that branch is `errCode => 0` — the SDK converts a non-empty code into
    // its own error shape first — and 0 is Nuvei's marker for NO error, so the honest answer is
    // nothing to say rather than "0". Reached here through the failure path, because on a success
    // there is no message to report at all.
    expect(nuveiTokenizationAnswering(['status' => 'DECLINED', 'errCode' => 0])->message)
        ->toBe('Gateway returned an unsuccessful response.');
});

it('keeps an empty reason as the message instead of falling through to the code', function () {
    // `array_key_exists`, not `??`: a present-but-blank reason wins over errCode. Pinned because it
    // is the one input where the fallback does not fire, and a reader would reasonably expect the
    // opposite.
    expect(nuveiTokenizationAnswering(['status' => 'ERROR', 'reason' => '', 'errCode' => 1102])->message)->toBe('');
});

/*
 * `it('exposes the session token Nuvei echoed back')` lived here. The response class had a
 * `getSessionToken()` accessor for it and nothing ever called it: a
 * {@see \Techork\PaymentService\Gateway\Contract\RegistrationResult} has no slot for a session, and
 * the shared assembler that folded the old response never read one either — so the value was
 * already being dropped one layer further out. Reinstating it would mean widening the result
 * contract for a field no caller has asked for.
 */
