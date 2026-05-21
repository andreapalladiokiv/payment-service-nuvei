<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;
use Techork\PaymentService\Nuvei\NuveiTransactionResponse;

function makeNuveiResponse(array $data): NuveiTransactionResponse
{
    $request = Mockery::mock(RequestInterface::class);

    return new NuveiTransactionResponse($request, $data);
}

it('implements CardChecksProvider', function () {
    expect(makeNuveiResponse([]))->toBeInstanceOf(CardChecksProvider::class);
});

it('returns null for all checks when paymentOption.card data absent', function () {
    $response = makeNuveiResponse(['status' => 'SUCCESS', 'transactionId' => '1']);

    expect($response->getAddressLineCheck())->toBeNull()
        ->and($response->getPostalCodeCheck())->toBeNull()
        ->and($response->getCvcCheck())->toBeNull();
});

it('decomposes a Y AVS letter into (Pass, Pass) for line + postal', function () {
    $response = makeNuveiResponse([
        'paymentOption' => ['card' => ['avsCode' => 'Y']],
    ]);

    expect($response->getAddressLineCheck())->toBe(CheckResult::Pass)
        ->and($response->getPostalCodeCheck())->toBe(CheckResult::Pass);
});

it('decomposes A AVS letter into (Pass, Fail) — street match, postal mismatch', function () {
    $response = makeNuveiResponse([
        'paymentOption' => ['card' => ['avsCode' => 'A']],
    ]);

    expect($response->getAddressLineCheck())->toBe(CheckResult::Pass)
        ->and($response->getPostalCodeCheck())->toBe(CheckResult::Fail);
});

it('decomposes Z AVS letter into (Fail, Pass) — postal match, street mismatch', function () {
    $response = makeNuveiResponse([
        'paymentOption' => ['card' => ['avsCode' => 'Z']],
    ]);

    expect($response->getAddressLineCheck())->toBe(CheckResult::Fail)
        ->and($response->getPostalCodeCheck())->toBe(CheckResult::Pass);
});

it('maps CVV M to Pass and N to Fail', function () {
    expect(makeNuveiResponse(['paymentOption' => ['card' => ['cvv2Reply' => 'M']]])->getCvcCheck())
        ->toBe(CheckResult::Pass)
        ->and(makeNuveiResponse(['paymentOption' => ['card' => ['cvv2Reply' => 'N']]])->getCvcCheck())
        ->toBe(CheckResult::Fail);
});

it('maps CVV S to Fail (protocol violation, real signal)', function () {
    expect(makeNuveiResponse(['paymentOption' => ['card' => ['cvv2Reply' => 'S']]])->getCvcCheck())
        ->toBe(CheckResult::Fail);
});

it('returns null for cvc when cvv2Reply is empty string', function () {
    $response = makeNuveiResponse([
        'paymentOption' => ['card' => ['cvv2Reply' => '']],
    ]);

    expect($response->getCvcCheck())->toBeNull();
});
