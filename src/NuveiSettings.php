<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\Environment;
use Nuvei\Api\RestClient;

/**
 * What the gateway's configuration contributes to a Nuvei request, as opposed to what the caller
 * asked for.
 *
 * The split is the same one {@see \Techork\PaymentService\Revolut\CardSettings} draws: these are
 * deployment facts — which merchant, which site, which environment, which session the SDK client
 * was opened with — and none of them belong in a command. They used to reach a request as five
 * more keys in the same parameter array as the amount, each needing a `set…()` of its own purely
 * so Omnipay's initializer would not drop it silently on the way in.
 *
 * The {@see RestClient} travels with them rather than being rebuilt per operation: it carries the
 * session token the gateway paid a round trip for, and a second client would spend another.
 */
final readonly class NuveiSettings
{
    public function __construct(
        public RestClient $restClient,
        public string $merchantId = '',
        public string $merchantSiteId = '',
        public string $secretKey = '',
        public string $environment = Environment::TEST,
        public ?string $sessionToken = null,
    ) {}
}
