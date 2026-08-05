<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use RuntimeException;

use Nuvei\Api\Service\PaymentService;
use Nuvei\Api\Utils;
use Override;

/**
 * Patched {@see PaymentService} that fixes Nuvei's PHP SDK bug where
 * {@see PaymentService::voidTransaction()} marks `amount` and `currency` as
 * mandatory client-side and throws ValidationException before the request
 * leaves the process.
 *
 * Per the official Nuvei docs both fields are optional — when omitted the
 * gateway voids the original transaction amount in full. Sending them is
 * actively dangerous because the gateway rejects with "Invalid Amount" if
 * the value differs from the original auth even by a cent. The override
 * therefore drops them from `mandatoryFields` and goes straight to
 * `requestJson` (the same path the SDK uses for every other endpoint).
 *
 * @see https://docs.nuvei.com/api/main/indexMain_v1_0.html?json#voidTransaction
 */
final class NuveiPaymentService extends PaymentService
{
    private const array VOID_CHECKSUM_ORDER = [
        'merchantId',
        'merchantSiteId',
        'clientRequestId',
        'clientUniqueId',
        'amount',
        'currency',
        'relatedTransactionId',
        'authCode',
        'comment',
        'urlDetails',
        'timeStamp',
        'merchantSecretKey',
    ];

    /**
     * @inheritDoc
     */
    #[Override]
    public function voidTransaction(array $params)
    {
        $mandatoryFields = [
            'merchantId',
            'merchantSiteId',
            'clientUniqueId',
            'relatedTransactionId',
            'timeStamp',
            'checksum',
        ];

        $params = $this->appendMerchantIdMerchantSiteIdTimeStamp($params);

        $config = $this->client->getConfig()
            ?? throw new RuntimeException('The Nuvei client carries no configuration, so no checksum can be computed.');

        $params['checksum'] = Utils::calculateChecksum(
            $params,
            self::VOID_CHECKSUM_ORDER,
            $config->getMerchantSecretKey(),
            $config->getHashAlgorithm(),
        );

        $this->validate($params, $mandatoryFields);

        return $this->requestJson($params, 'voidTransaction.do');
    }
}
