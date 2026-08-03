<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
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
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Nuvei\CreatePaymentMethodRequest;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

it('builds UPO creation data from token reference', function () {
    $token = new Token(
        TokenId::generate(),
        new CreditCard(new Number('476134', '1390', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $credential = new readonly class implements GatewayCredential {
        public function getId(): GatewayId { return GatewayId::generate(); }
        public function getGatewayName(): string { return 'Nuvei'; }
        public function getCredentials(): array { return []; }
    };

    $refResolver = Mockery::mock(GatewayInstrumentRepository::class);
    $refResolver->shouldReceive('find')->andReturn('ccTempToken_abc123');

    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => $token,
        'gateway' => $credential,
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => $refResolver,
        'customerReference' => 'user@test.com',
    ]);

    $data = $request->getData();

    expect($data['ccTempToken'])->toBe('ccTempToken_abc123')
        ->and($data['userTokenId'])->toBe('user@test.com');
});

it('builds a zero-amount verification from a raw card', function () {
    // The card goes to `payment.do` rather than `addUPOCreditCard.do` because
    // only the payment route answers with the issuer's AVS and CVV verdicts,
    // which is what a lazy registration is performed to learn.
    $card = new CreditCard(
        Number::fromNumber('4761341234561390', new EncryptsToItself),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('T Cardholder'),
        Cvc::fromCvc('123', new EncryptsToItself),
    );

    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => $card,
        'decrypter' => new EncryptsToItself,
        'customerReference' => 'user@test.com',
    ]);

    $data = $request->getData();

    expect($data)->not->toHaveKey('ccTempToken')
        ->and($data['userTokenId'])->toBe('user@test.com')
        ->and($data['paymentOption']['card'])->toBe([
            'cardNumber' => '4761341234561390',
            'cardHolderName' => 'T Cardholder',
            'expirationMonth' => '12',
            'expirationYear' => '2030',
            'CVV' => '123',
        ]);
});

/**
 * A decrypter that returns what it was given, so a test can assert the card
 * fields the request assembles without standing up encryption.
 */
final readonly class EncryptsToItself implements DecryptInterface, Techork\PaymentService\Common\Contract\EncryptInterface
{
    public function decrypt(string $value): string
    {
        return $value;
    }

    public function encrypt(string $value): string
    {
        return $value;
    }
}

it('throws on cash instrument', function () {
    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => new Cash,
        'decrypter' => Mockery::mock(DecryptInterface::class),
    ]);

    $request->getData();
})->throws(ValueError::class, 'Nuvei does not support cash');
