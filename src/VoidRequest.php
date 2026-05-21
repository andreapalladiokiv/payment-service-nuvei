<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\RestClient;
use Omnipay\Common\Message\AbstractRequest;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;

/**
 * Voids (cancels) a Nuvei transaction by relatedTransactionId.
 * Expects: transactionReference.
 *
 * Voids the full original amount. We deliberately do NOT send `amount` /
 * `currency` — they are optional per Nuvei docs and, if sent with anything
 * other than the exact original value, the gateway rejects with
 * "Invalid Amount". {@see NuveiPaymentService} overrides the SDK's broken
 * client-side mandatory-field check on these two fields.
 */
final class VoidRequest extends AbstractRequest
{
    use NuveiRequestParameters;

    public function getData(): array
    {
        $this->validate('transactionReference');

        $clientId = $this->getParameter('clientUniqueId') ?? Uuid::uuid4()->toString();

        return [
            'clientRequestId' => $clientId,
            'clientUniqueId' => $clientId,
            'relatedTransactionId' => $this->getParameter('transactionReference'),
        ];
    }

    public function sendData($data): VoidResponse
    {
        try {
            /** @var RestClient $client */
            $client = $this->getParameter('restClient');
            $result = (new NuveiPaymentService($client))->voidTransaction($data);

            return new VoidResponse($this, $result);
        } catch (\Throwable $e) {
            return new VoidResponse($this, ['status' => 'ERROR', 'reason' => $e->getMessage()]);
        }
    }
}
