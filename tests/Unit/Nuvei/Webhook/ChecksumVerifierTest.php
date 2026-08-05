<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Nuvei\Webhook\ChecksumVerifier;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function checksumCredential(string $merchantId, string $siteId, string $secret): GatewayCredential
{
    return new readonly class($merchantId, $siteId, $secret) implements GatewayCredential
    {
        public function __construct(
            private string $merchantId,
            private string $siteId,
            private string $secret,
        ) {}

        public function getId(): GatewayId
        {
            return GatewayId::generate();
        }

        public function getGatewayName(): string
        {
            return 'Nuvei';
        }

        public function getCredentials(): array
        {
            return [
                'merchant_id' => $this->merchantId,
                'site_id' => $this->siteId,
                'secret_key' => $this->secret,
            ];
        }
    };
}

function dmnRequest(array $payload): ServerRequestInterface
{
    return (new Psr17Factory)->createServerRequest('POST', '/webhooks')->withParsedBody($payload);
}

it('accepts a DMN with a valid advanceResponseChecksum', function () {
    $secret = 'super-secret';
    $payload = [
        'merchantId' => '1',
        'merchantSiteId' => '2',
        'totalAmount' => '10.00',
        'currency' => 'USD',
        'responseTimeStamp' => '20260423000000',
        'PPP_TransactionID' => 'ppp_x',
        'Status' => 'APPROVED',
        'productId' => 'prod',
    ];
    $payload['advanceResponseChecksum'] = hash('sha256', implode('', [
        $secret, '10.00', 'USD', '20260423000000', 'ppp_x', 'APPROVED', 'prod',
    ]));

    expect((new ChecksumVerifier)->verify(dmnRequest($payload), checksumCredential('1', '2', $secret)))->toBeTrue();
});

it('rejects DMN when the merchant pair does not match the credential', function () {
    $payload = [
        'merchantId' => '1',
        'merchantSiteId' => '2',
        'advanceResponseChecksum' => 'irrelevant',
    ];

    expect((new ChecksumVerifier)->verify(dmnRequest($payload), checksumCredential('99', '99', 's')))->toBeFalse();
});

it('rejects DMN with a tampered checksum', function () {
    $payload = [
        'merchantId' => '1',
        'merchantSiteId' => '2',
        'totalAmount' => '10',
        'currency' => 'USD',
        'responseTimeStamp' => 'ts',
        'PPP_TransactionID' => 'ppp',
        'Status' => 'APPROVED',
        'productId' => 'p',
        'advanceResponseChecksum' => 'deadbeef',
    ];

    expect((new ChecksumVerifier)->verify(dmnRequest($payload), checksumCredential('1', '2', 'secret')))->toBeFalse();
});

it('accepts a Notification with a valid body-based checksum header', function () {
    $secret = 'super-secret';
    $body = '{"EventCorrelationId":"ec_1","merchantId":"1","merchantSiteId":"2"}';
    $expected = hash('sha256', $secret.$body);

    $request = (new Psr17Factory)->createServerRequest('POST', '/webhooks')
        ->withHeader('checksum', $expected)
        ->withParsedBody(json_decode($body, true))
        ->withBody((new Psr17Factory)->createStream($body));

    expect((new ChecksumVerifier)->verify($request, checksumCredential('1', '2', $secret)))->toBeTrue();
});
