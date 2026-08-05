<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Nuvei\CreateCardRequest;
use Techork\PaymentService\Nuvei\CreateCardResponse;

/**
 * {@see CreateCardResponse} reads Nuvei's cardTokenization answer. It was
 * unexecuted, and it is the class that decides whether a card was tokenized —
 * so a wrong reading here either stores a reference the acquirer never issued,
 * or throws away one it did.
 *
 * Two shapes reach it. The gateway's own answer, and the local
 * `['status' => 'ERROR', 'reason' => …]` that
 * {@see CreateCardRequest::sendData()} substitutes when the call throws before
 * a reply exists. Both are covered below, because the second is the one an
 * outage produces and it must not read as a success.
 *
 * Helpers prefixed `nuveiCardToken…`.
 */
function nuveiCardTokenResponse(array $data): CreateCardResponse
{
    return new CreateCardResponse(new CreateCardRequest(new OmnipayClient, new HttpRequest), $data);
}

it('is successful only when the status is SUCCESS and a token came with it', function () {
    expect(nuveiCardTokenResponse(['status' => 'SUCCESS', 'ccTempToken' => 'tok_abc'])->isSuccessful())
        ->toBeTrue();
});

it('refuses to call a tokenization successful without the token itself', function () {
    // Nuvei answers SUCCESS on the *call* while omitting ccTempToken when the
    // card data was rejected downstream. Treating that as success would store a
    // null reference against the instrument and fail the first payment that used
    // it — far from the operation that actually failed.
    expect(nuveiCardTokenResponse(['status' => 'SUCCESS'])->isSuccessful())->toBeFalse();
});

it('is unsuccessful for every status that is not exactly SUCCESS', function (array $data) {
    // Exact string match, so casing and Nuvei's other statuses all land on the
    // failure side. 'APPROVED' is included because it is the word the DMN
    // webhook uses for the same event, and copying it here would silently
    // accept declines.
    expect(nuveiCardTokenResponse($data)->isSuccessful())->toBeFalse();
})->with([
    'declined with a token present' => [['status' => 'DECLINED', 'ccTempToken' => 'tok_abc']],
    'local error substituted by sendData' => [['status' => 'ERROR', 'reason' => 'Connection timed out']],
    'the webhook vocabulary, not this one' => [['status' => 'APPROVED', 'ccTempToken' => 'tok_abc']],
    'lower case' => [['status' => 'success', 'ccTempToken' => 'tok_abc']],
    'no status at all' => [['ccTempToken' => 'tok_abc']],
    'empty payload' => [[]],
]);

it('surfaces the temp token as the transaction reference', function () {
    // This value is what GatewayInstrumentRepository stores and what
    // NuveiPaymentRequest::visitToken() later sends as paymentOption.card.ccTempToken.
    expect(nuveiCardTokenResponse(['status' => 'SUCCESS', 'ccTempToken' => 'tok_abc'])->getTransactionReference())
        ->toBe('tok_abc');
});

it('casts a numeric temp token to a string rather than leaking an int', function () {
    // Nuvei's JSON has returned ccTempToken unquoted. The cast is what keeps the
    // reference a string all the way into storage, where the column and every
    // consumer are typed on string.
    expect(nuveiCardTokenResponse(['status' => 'SUCCESS', 'ccTempToken' => 123456789])->getTransactionReference())
        ->toBe('123456789');
});

it('has no transaction reference when no token was issued', function () {
    // Null, not ''. An empty-string reference would be stored as a real link to
    // a token that does not exist; null is what the caller checks for.
    expect(nuveiCardTokenResponse(['status' => 'ERROR', 'reason' => 'boom'])->getTransactionReference())
        ->toBeNull();
});

it('exposes the session token Nuvei echoed back', function () {
    // Nuvei scopes a temp token to the session it was created in, so the session
    // travels with the response for the payment that follows.
    expect(nuveiCardTokenResponse(['status' => 'SUCCESS', 'ccTempToken' => 'tok_abc', 'sessionToken' => 'sess_1'])->getSessionToken())
        ->toBe('sess_1')
        ->and(nuveiCardTokenResponse(['status' => 'SUCCESS', 'ccTempToken' => 'tok_abc'])->getSessionToken())
        ->toBeNull();
});

it('prefers the human reason over the error code in its message', function () {
    // Both arrive together on a real Nuvei rejection. The reason is what an
    // operator can act on; the code alone is not.
    expect(nuveiCardTokenResponse(['status' => 'ERROR', 'reason' => 'Invalid card number', 'errCode' => 1102])->getMessage())
        ->toBe('Invalid card number');
});

it('falls back to the error code when Nuvei sent no reason', function () {
    // Only a code Nuvei quoted as a string survives the fallback — see the
    // numeric case below, which does not. Pinned in this shape so the branch is
    // covered without asserting the broken half is fine.
    expect(nuveiCardTokenResponse(['status' => 'ERROR', 'errCode' => '1102'])->getMessage())->toBe('1102');
});

it('reports no message for the zero error code that marks a successful answer', function () {
    // Was a TypeError: getMessage() is declared `?string` and returned `errCode` uncast, while
    // Nuvei sends it unquoted so json_decode keeps it an int. The value that actually reaches
    // this branch is 0 — the SDK converts a non-empty code into its own error shape first — and
    // 0 is Nuvei's marker for NO error, so the honest answer is nothing to say rather than "0".
    expect(nuveiCardTokenResponse(['status' => 'SUCCESS', 'ccTempToken' => 'tok_abc', 'errCode' => 0])->getMessage())
        ->toBeNull();
});

it('reports a genuine error code whether Nuvei quoted it or not', function () {
    // The cast the sibling getTransactionReference() always had.
    expect(nuveiCardTokenResponse(['status' => 'ERROR', 'errCode' => 1102])->getMessage())->toBe('1102')
        ->and(nuveiCardTokenResponse(['status' => 'ERROR', 'errCode' => '1102'])->getMessage())->toBe('1102');
});

it('has no message on a clean success', function () {
    expect(nuveiCardTokenResponse(['status' => 'SUCCESS', 'ccTempToken' => 'tok_abc'])->getMessage())->toBeNull();
});

it('keeps an empty reason as the message instead of falling through to the code', function () {
    // `??` only skips null, so a present-but-blank reason wins over errCode. Pinned
    // because it is the one input where the fallback does not fire, and a reader
    // would reasonably expect the opposite.
    expect(nuveiCardTokenResponse(['status' => 'ERROR', 'reason' => '', 'errCode' => 1102])->getMessage())->toBe('');
});
