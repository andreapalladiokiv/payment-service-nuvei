<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\Service\PaymentService;
use Omnipay\Common\Message\AbstractResponse;
use Override;

final class CaptureRequest extends NuveiRelatedTransactionRequest
{
    #[Override]
    protected function executeService(PaymentService $service, array $data): array
    {
        return $service->settleTransaction([...$data, 'transactionType' => 'Settle']);
    }

    #[Override]
    protected function wrapResponse(array $result): AbstractResponse
    {
        return new CaptureResponse($this, $result);
    }
}
