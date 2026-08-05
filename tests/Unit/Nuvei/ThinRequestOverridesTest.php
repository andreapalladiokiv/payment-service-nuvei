<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Nuvei\Api\Environment;
use Nuvei\Api\Interfaces\HttpClientInterface;
use Nuvei\Api\Interfaces\ServiceInterface;
use Nuvei\Api\RestClient;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\ValueObject\CardBrand;
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
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Nuvei\AuthorizeRequest;
use Techork\PaymentService\Nuvei\AuthorizeResponse;
use Techork\PaymentService\Nuvei\CaptureRequest;
use Techork\PaymentService\Nuvei\CaptureResponse;
use Techork\PaymentService\Nuvei\RefundRequest;
use Techork\PaymentService\Nuvei\RefundResponse;

/**
 * {@see AuthorizeRequest}, {@see CaptureRequest} and {@see RefundRequest} are
 * three-line subclasses: each names a transaction type or an endpoint and wraps
 * the answer in its own response class. All three were unexecuted.
 *
 * They are driven here through `send()` against an offline transport rather than
 * by calling their protected methods with reflection. The difference matters:
 * what has to hold is that the type each declares reaches the WIRE and that the
 * SDK method each picks is the one actually called — a reflection smoke test
 * would confirm the return values while leaving both of those unproven. The
 * base classes swallow every Throwable into an ERROR response, so a request
 * that never got out would still yield the right response class; the recorded
 * transport call is what rules that out.
 *
 * Helpers prefixed `nuveiThinOverride…`.
 */
const NUVEI_THIN_OVERRIDE_SECRET = 'sek_thin_1';

/**
 * A transport that records the endpoint and params the SDK service handed it.
 */
function nuveiThinOverrideTransport(array &$calls): HttpClientInterface
{
    return new class($calls) implements HttpClientInterface
    {
        /** @param array<int, array{url: string, params: array}> $calls */
        public function __construct(private array &$calls) {}

        public function requestJson(ServiceInterface $service, $requestUrl, $params)
        {
            $this->calls[] = ['url' => $requestUrl, 'params' => $params];

            return ['status' => 'SUCCESS', 'transactionStatus' => 'APPROVED', 'transactionId' => 'txn-1'];
        }

        public function requestPost(ServiceInterface $service, $requestUrl, $params)
        {
            throw new LogicException('These operations go through requestJson.');
        }
    };
}

/**
 * A RestClient wired to the recorder. Subclassing is the only seam the SDK
 * leaves: `$httpClient` is private and lazily built, with no setter.
 */
function nuveiThinOverrideClient(array &$calls): RestClient
{
    return new class(nuveiThinOverrideTransport($calls)) extends RestClient
    {
        public function __construct(private HttpClientInterface $transport)
        {
            parent::__construct([
                'environment' => Environment::TEST,
                'merchantId' => 'mid-thin',
                'merchantSiteId' => 'site-thin',
                'merchantSecretKey' => NUVEI_THIN_OVERRIDE_SECRET,
            ]);
        }

        public function getHttpClient()
        {
            return $this->transport;
        }
    };
}

function nuveiThinOverrideCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential
    {
        public function getId(): GatewayId
        {
            return GatewayId::generate();
        }

        public function getGatewayName(): string
        {
            return 'nuvei';
        }

        public function getCredentials(): array
        {
            return [];
        }
    };
}

/** A tokenized instrument, the only shape a Nuvei payment request accepts. */
function nuveiThinOverrideToken(): Token
{
    return new Token(
        TokenId::generate(),
        new CreditCard(
            new Number('476134', '1390', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test'),
            new Cvc,
        ),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );
}

function nuveiThinOverrideResolver(string $reference): GatewayInstrumentRepository
{
    $mock = Mockery::mock(GatewayInstrumentRepository::class);
    $mock->shouldReceive('find')->andReturn($reference);
    $mock->shouldReceive('findMetadata')->andReturn([]);

    return $mock;
}

// ──────────────────────────────────────────────
//  AuthorizeRequest
// ──────────────────────────────────────────────

it('authorizes as an Auth that settles later, and answers as an authorization', function () {
    // transactionType 'Auth' with settleType 0 is what makes this a hold rather
    // than a sale. Either half alone is wrong: 'Auth' with the default settle type
    // captures immediately, which would take the customer's money on a booking
    // that is only being held.
    $calls = [];

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'instrument' => nuveiThinOverrideToken(),
        'gateway' => nuveiThinOverrideCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => nuveiThinOverrideResolver('temp_token_auth'),
        'sessionToken' => 'sess_auth',
        'restClient' => nuveiThinOverrideClient($calls),
    ]);

    $response = $request->send();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/payment.do')
        ->and($calls[0]['params']['transactionType'])->toBe('Auth')
        ->and($calls[0]['params']['settleType'])->toBe(0)
        ->and($response)->toBeInstanceOf(AuthorizeResponse::class);
});

it('declares the settle type explicitly rather than leaving it to Nuvei', function () {
    // 0 is falsy, so the base class's `!== null` check is the only thing keeping
    // the key on the request. A truthiness test there would drop it and the
    // acquirer would apply its account default — a capture on some accounts.
    $calls = [];

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'instrument' => nuveiThinOverrideToken(),
        'gateway' => nuveiThinOverrideCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => nuveiThinOverrideResolver('temp_token_auth'),
        'sessionToken' => 'sess_auth',
        'restClient' => nuveiThinOverrideClient($calls),
    ]);

    expect($request->getData())->toHaveKey('settleType', 0);
});

// ──────────────────────────────────────────────
//  CaptureRequest
// ──────────────────────────────────────────────

it('captures through settleTransaction, restating the type in the body', function () {
    // The endpoint alone does not tell Nuvei what this is; settleTransaction.do
    // takes a transactionType and the override adds 'Settle' to whatever getData()
    // produced. Both the endpoint and the field are pinned because either being
    // wrong leaves the authorization uncaptured until it expires.
    $calls = [];

    $request = new CaptureRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'transactionReference' => '1110000000123456',
        'clientUniqueId' => 'cuid-capture',
        'restClient' => nuveiThinOverrideClient($calls),
    ]);

    $response = $request->send();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/settleTransaction.do')
        ->and($calls[0]['params']['transactionType'])->toBe('Settle')
        ->and($calls[0]['params']['relatedTransactionId'])->toBe('1110000000123456')
        ->and($calls[0]['params']['amount'])->toBe('25.00')
        ->and($response)->toBeInstanceOf(CaptureResponse::class);
});

// ──────────────────────────────────────────────
//  RefundRequest
// ──────────────────────────────────────────────

it('refunds through refundTransaction, adding no transaction type of its own', function () {
    // Unlike capture, the refund override passes $data through untouched — the
    // endpoint is the whole declaration. Pinned in the negative too: a
    // transactionType borrowed from the capture override would be a field Nuvei
    // does not expect on this call.
    $calls = [];

    $request = new RefundRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1000, new Currency('EUR')),
        'transactionReference' => '1110000000654321',
        'clientUniqueId' => 'cuid-refund',
        'restClient' => nuveiThinOverrideClient($calls),
    ]);

    $response = $request->send();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/refundTransaction.do')
        ->and($calls[0]['params'])->not->toHaveKey('transactionType')
        ->and($calls[0]['params']['relatedTransactionId'])->toBe('1110000000654321')
        ->and($calls[0]['params']['currency'])->toBe('EUR')
        ->and($response)->toBeInstanceOf(RefundResponse::class);
});

// ──────────────────────────────────────────────
//  the shared failure shape
// ──────────────────────────────────────────────

it('still answers in its own response class when the call never leaves', function (string $class, string $responseClass) {
    // No restClient at all, so the base class's catch-all runs. Each subclass must
    // report the failure through ITS OWN response type, because the router reads
    // capture and refund outcomes differently — a shared or wrong class here would
    // make an unreachable acquirer indistinguishable from a rejected refund.
    $request = new $class(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1000, new Currency('USD')),
        'transactionReference' => '1110000000123456',
    ]);

    $response = $request->send();

    expect($response)->toBeInstanceOf($responseClass)
        ->and($response->isSuccessful())->toBeFalse();
})->with([
    'capture' => [CaptureRequest::class, CaptureResponse::class],
    'refund' => [RefundRequest::class, RefundResponse::class],
]);
