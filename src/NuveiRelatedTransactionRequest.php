<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Override;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use Ramsey\Uuid\Uuid;
use Money\Money;
use Nuvei\Api\RestClient;
use Nuvei\Api\Service\PaymentService;
use Omnipay\Common\Message\AbstractRequest;
use Omnipay\Common\Message\AbstractResponse;
use Throwable;

/**
 * Base for Nuvei capture/refund requests that operate on existing transactions.
 */
abstract class NuveiRelatedTransactionRequest extends AbstractRequest
{
    use NuveiRequestParameters;

    abstract protected function executeService(PaymentService $service, array $data): array;

    abstract protected function wrapResponse(array $result): AbstractResponse;

    #[Override]
    public function getData(): array
    {
        $this->validate('money', 'transactionReference');

        /** @var Money $money */
        $money = $this->getParameter('money');

        return [
            'clientUniqueId' => $this->getParameter('clientUniqueId') ?? Uuid::uuid4()->toString(),
            'amount' => $this->formatMoney($money),
            'currency' => $money->getCurrency()->getCode(),
            'relatedTransactionId' => $this->getParameter('transactionReference'),
        ];
    }

    #[Override]
    public function sendData($data): AbstractResponse
    {
        try {
            /** @var RestClient $client */
            $client = $this->getParameter('restClient');
            $result = $this->executeService(new PaymentService($client), $data);

            return $this->wrapResponse($result);
        } catch (Throwable $e) {
            return $this->wrapResponse(['status' => 'ERROR', 'reason' => $e->getMessage()]);
        }
    }
}
