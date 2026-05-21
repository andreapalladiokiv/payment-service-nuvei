<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
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
use Techork\PaymentService\Nuvei\CreateCardRequest;

function nuveiEncrypter(): EncryptInterface
{
    return new class implements EncryptInterface {
        public function encrypt(string $data): string { return $data; }
    };
}

function nuveiDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface {
        public function decrypt(string $data): string { return $data; }
    };
}

it('builds card tokenization data for credit card', function () {
    $card = new CreditCard(
        Number::fromNumber('4761344136141390', nuveiEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Jane Doe'),
        Cvc::fromCvc('999', nuveiEncrypter()),
    );

    $request = new CreateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => $card,
        'decrypter' => nuveiDecrypter(),
    ]);

    $data = $request->getData();

    expect($data['cardData']['cardNumber'])->toBe('4761344136141390')
        ->and($data['cardData']['cardHolderName'])->toBe('Jane Doe')
        ->and($data['cardData']['expirationMonth'])->toBe('12')
        ->and($data['cardData']['expirationYear'])->toBe('2030')
        ->and($data['cardData']['CVV'])->toBe('999');
});

it('omits empty holder and CVV from card data', function () {
    $card = new CreditCard(
        Number::fromNumber('4761344136141390', nuveiEncrypter()),
        Expiration::fromMonthAndYear(6, 2028),
        new Holder(''),
        new Cvc,
    );

    $request = new CreateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => $card,
        'decrypter' => nuveiDecrypter(),
    ]);

    $data = $request->getData();

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

    $request = new CreateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize(['instrument' => $token, 'decrypter' => nuveiDecrypter()]);

    $request->getData();
})->throws(RuntimeException::class, 'Token does not support tokenization');

it('throws on cash instrument', function () {
    $request = new CreateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize(['instrument' => new Cash, 'decrypter' => nuveiDecrypter()]);

    $request->getData();
})->throws(ValueError::class, 'Nuvei does not support cash');
