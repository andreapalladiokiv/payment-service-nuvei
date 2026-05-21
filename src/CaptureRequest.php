<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\Service\PaymentService;
use Omnipay\Common\Message\AbstractResponse;

final class CaptureRequest extends NuveiRelatedTransactionRequest
{
    protected function executeService(PaymentService $service, array $data): array
    {
        return $service->settleTransaction([...$data, 'transactionType' => 'Settle']);
    }

    protected function wrapResponse(array $result): AbstractResponse
    {
        return new CaptureResponse($this, $result);
    }
}
