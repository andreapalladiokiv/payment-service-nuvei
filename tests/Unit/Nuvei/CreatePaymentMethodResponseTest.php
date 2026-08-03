<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Nuvei\CreatePaymentMethodRequest;
use Techork\PaymentService\Nuvei\CreatePaymentMethodResponse;

function upoResponse(array $data): CreatePaymentMethodResponse
{
    return new CreatePaymentMethodResponse(
        new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest),
        $data,
    );
}

it('reads the flat shape the temp-token conversion answers with', function () {
    $response = upoResponse(['status' => 'SUCCESS', 'userPaymentOptionId' => '4242']);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('4242')
        // A vault write asks the issuer nothing, so there is no verdict to report.
        ->and($response->getAddressLineCheck())->toBeNull()
        ->and($response->getCvcCheck())->toBeNull();
});

it('reads the nested shape the zero-amount verification answers with', function () {
    $response = upoResponse([
        'status' => 'SUCCESS',
        'transactionStatus' => 'APPROVED',
        'paymentOption' => [
            'userPaymentOptionId' => '9001',
            'card' => ['avsCode' => 'Y', 'cvv2Reply' => 'M'],
        ],
    ]);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('9001')
        ->and($response->getAddressLineCheck())->toBe(CheckResult::Pass)
        ->and($response->getPostalCodeCheck())->toBe(CheckResult::Pass)
        ->and($response->getCvcCheck())->toBe(CheckResult::Pass);
});

it('treats a declined verification as a failure', function () {
    // `status` only says Nuvei accepted the request. Reading it alone would take
    // a decline for a registration.
    $response = upoResponse([
        'status' => 'SUCCESS',
        'transactionStatus' => 'DECLINED',
        'gwErrorReason' => 'Insufficient funds',
        'paymentOption' => ['userPaymentOptionId' => '9002'],
    ]);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Insufficient funds');
});

it('fails when the call itself was rejected', function () {
    $response = upoResponse(['status' => 'ERROR', 'reason' => 'Invalid checksum']);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Invalid checksum');
});

it('fails an approved verification that named no payment option', function () {
    $response = upoResponse(['status' => 'SUCCESS', 'transactionStatus' => 'APPROVED']);

    expect($response->isSuccessful())->toBeFalse();
});

it('reports a split AVS verdict on each field separately', function () {
    // Schemes pack street and postal into one letter; 'A' means the street
    // matched and the postal code did not.
    $response = upoResponse([
        'status' => 'SUCCESS',
        'transactionStatus' => 'APPROVED',
        'paymentOption' => [
            'userPaymentOptionId' => '9003',
            'card' => ['avsCode' => 'A'],
        ],
    ]);

    expect($response->getAddressLineCheck())->toBe(CheckResult::Pass)
        ->and($response->getPostalCodeCheck())->toBe(CheckResult::Fail)
        ->and($response->getCvcCheck())->toBeNull();
});
