<?php

declare(strict_types=1);

use Nuvei\Api\Environment;
use Nuvei\Api\Exception\ValidationException;
use Nuvei\Api\Interfaces\HttpClientInterface;
use Nuvei\Api\Interfaces\ServiceInterface;
use Nuvei\Api\RestClient;
use Nuvei\Api\Utils;
use Techork\PaymentService\Nuvei\NuveiPaymentService;

/**
 * {@see NuveiPaymentService::voidTransaction()} was unexecuted. The existing
 * NuveiPaymentServiceTest reads the method's source with reflection, which
 * proves the mandatory-field array does not name amount/currency but nothing
 * about what the method actually does with a request — including the checksum
 * it computes, which is the part Nuvei rejects the call over.
 *
 * These tests run the real method. The only thing replaced is the SDK's cURL
 * transport: `RestClient::getHttpClient()` lazily builds one and there is no
 * setter, so a test-local subclass overrides that single accessor. Nothing here
 * touches the network — constructing a RestClient performs no I/O, and the
 * request stops at the recorder.
 *
 * Helpers prefixed `nuveiVoidService…`.
 */
const NUVEI_VOID_SERVICE_SECRET = 'sek_void_1';

/**
 * A transport that records what the service handed it and answers with a canned
 * body, standing in for `Nuvei\Api\HttpClient`.
 */
function nuveiVoidServiceTransport(array &$calls, array $response = ['status' => 'SUCCESS']): HttpClientInterface
{
    return new class($calls, $response) implements HttpClientInterface
    {
        /** @param array<int, array{url: string, params: array}> $calls */
        public function __construct(private array &$calls, private array $response) {}

        public function requestJson(ServiceInterface $service, $requestUrl, $params)
        {
            $this->calls[] = ['url' => $requestUrl, 'params' => $params];

            return $this->response;
        }

        public function requestPost(ServiceInterface $service, $requestUrl, $params)
        {
            throw new LogicException('voidTransaction must go through requestJson, not requestPost.');
        }
    };
}

/**
 * A RestClient whose transport is the recorder. Subclassing is the only seam:
 * the SDK keeps `$httpClient` private and offers no setter.
 */
function nuveiVoidServiceClient(array &$calls, array $response = ['status' => 'SUCCESS']): RestClient
{
    return new class(nuveiVoidServiceTransport($calls, $response)) extends RestClient
    {
        public function __construct(private HttpClientInterface $transport)
        {
            parent::__construct([
                'environment' => Environment::TEST,
                'merchantId' => 'mid-void',
                'merchantSiteId' => 'site-void',
                'merchantSecretKey' => NUVEI_VOID_SERVICE_SECRET,
            ]);
        }

        public function getHttpClient()
        {
            return $this->transport;
        }
    };
}

/**
 * Same client, but reporting no configuration — the state the override's
 * RuntimeException guards against.
 */
function nuveiVoidServiceClientWithoutConfig(array &$calls): RestClient
{
    return new class(nuveiVoidServiceTransport($calls)) extends RestClient
    {
        public function __construct(private HttpClientInterface $transport)
        {
            parent::__construct([
                'environment' => Environment::TEST,
                'merchantId' => 'mid-void',
                'merchantSiteId' => 'site-void',
                'merchantSecretKey' => NUVEI_VOID_SERVICE_SECRET,
            ]);
        }

        public function getHttpClient()
        {
            return $this->transport;
        }

        public function getConfig()
        {
            return null;
        }
    };
}

it('sends a void carrying no amount and no currency', function () {
    // The reason this class exists. The SDK's own voidTransaction() lists both in
    // $mandatoryFields and throws ValidationException before anything leaves the
    // process, so with the SDK method a full void was impossible; sending the
    // values instead risks Nuvei's "Invalid Amount" when they differ from the
    // original auth by a cent. Reaching the transport at all is the assertion.
    $calls = [];

    $result = new NuveiPaymentService(nuveiVoidServiceClient($calls))->voidTransaction([
        'clientUniqueId' => 'cuid-1',
        'relatedTransactionId' => '1110000000123456',
    ]);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['params'])->not->toHaveKey('amount')
        ->and($calls[0]['params'])->not->toHaveKey('currency')
        ->and($result)->toBe(['status' => 'SUCCESS']);
});

it('posts to the voidTransaction endpoint under the configured environment', function () {
    // requestJson receives an absolute url assembled from the config's endpoint,
    // so a wrong environment silently voids nothing in the sandbox while the
    // caller sees a well-formed error.
    $calls = [];

    new NuveiPaymentService(nuveiVoidServiceClient($calls))->voidTransaction([
        'clientUniqueId' => 'cuid-1',
        'relatedTransactionId' => '1110000000123456',
    ]);

    expect($calls[0]['url'])->toBe('https://ppp-test.safecharge.com/ppp/api/v1/voidTransaction.do');
});

it('fills in the merchant pair and a timestamp the caller never supplies', function () {
    // VoidRequest::getData() sends three keys and nothing else; everything Nuvei
    // needs to identify the merchant is added here. A missing pair is a rejected
    // void, and the timestamp is part of the checksum below.
    $calls = [];

    new NuveiPaymentService(nuveiVoidServiceClient($calls))->voidTransaction([
        'clientUniqueId' => 'cuid-1',
        'relatedTransactionId' => '1110000000123456',
    ]);

    expect($calls[0]['params'])
        ->toHaveKey('merchantId', 'mid-void')
        ->toHaveKey('merchantSiteId', 'site-void')
        ->toHaveKey('timeStamp');
});

it('signs the void over the documented field order', function () {
    // The checksum is recomputed here from the sent params rather than compared
    // to a stored digest, because what has to hold is the ORDER: Nuvei
    // concatenates the fields in its own documented sequence, and a checksum
    // built over any other order is rejected as tampering. Utils skips fields
    // absent from the params, which is why omitting amount/currency is safe.
    $calls = [];

    new NuveiPaymentService(nuveiVoidServiceClient($calls))->voidTransaction([
        'clientUniqueId' => 'cuid-1',
        'relatedTransactionId' => '1110000000123456',
    ]);

    $sent = $calls[0]['params'];

    $expected = Utils::calculateChecksum(
        $sent,
        ['merchantId', 'merchantSiteId', 'clientRequestId', 'clientUniqueId', 'amount', 'currency', 'relatedTransactionId', 'authCode', 'comment', 'urlDetails', 'timeStamp'],
        NUVEI_VOID_SERVICE_SECRET,
        'sha256',
    );

    expect($sent['checksum'])->toBe($expected)
        // Not the digest of an empty string: a silently-empty checksum would also
        // "match" a naive recomputation, so the negative is worth stating.
        ->and($sent['checksum'])->not->toBe(hash('sha256', ''));
});

it('still refuses a void that names no transaction to void', function () {
    // Dropping amount/currency from the mandatory list must not have dropped the
    // check itself. Without relatedTransactionId Nuvei has nothing to act on, and
    // failing here costs no round trip.
    $calls = [];

    expect(fn () => new NuveiPaymentService(nuveiVoidServiceClient($calls))->voidTransaction([
        'clientUniqueId' => 'cuid-1',
    ]))->toThrow(ValidationException::class, 'relatedTransactionId');

    expect($calls)->toBeEmpty();
});

it('refuses to sign a void when the client carries no configuration', function () {
    // Guards a `getMerchantSecretKey()` on null. Reached only when the caller
    // already supplied the merchant pair and a timestamp — otherwise
    // appendMerchantIdMerchantSiteIdTimeStamp() dereferences the null config
    // first and dies with a bare Error instead of this named refusal.
    $calls = [];

    expect(fn () => new NuveiPaymentService(nuveiVoidServiceClientWithoutConfig($calls))->voidTransaction([
        'merchantId' => 'mid-void',
        'merchantSiteId' => 'site-void',
        'timeStamp' => '20260804120000',
        'clientUniqueId' => 'cuid-1',
        'relatedTransactionId' => '1110000000123456',
    ]))->toThrow(RuntimeException::class, 'carries no configuration');

    expect($calls)->toBeEmpty();
});

it('lets a caller-supplied merchant pair through unchanged', function () {
    // The SDK only fills in what is empty. Pinned because per-request overrides
    // are how one configured gateway serves more than one merchant site, and a
    // void that silently reverted to the gateway's own pair would be rejected as
    // referencing another merchant's transaction.
    $calls = [];

    new NuveiPaymentService(nuveiVoidServiceClient($calls))->voidTransaction([
        'merchantId' => 'mid-other',
        'merchantSiteId' => 'site-other',
        'clientUniqueId' => 'cuid-1',
        'relatedTransactionId' => '1110000000123456',
    ]);

    expect($calls[0]['params'])
        ->toHaveKey('merchantId', 'mid-other')
        ->toHaveKey('merchantSiteId', 'site-other');
});
