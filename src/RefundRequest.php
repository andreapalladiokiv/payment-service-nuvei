<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\Service\PaymentService;
use Omnipay\Common\Message\AbstractResponse;
use Override;

final class RefundRequest extends NuveiRelatedTransactionRequest
{
    #[Override]
    protected function executeService(PaymentService $service, array $data): array
    {
        return $service->refundTransaction($data);
    }

    #[Override]
    protected function wrapResponse(array $result): AbstractResponse
    {
        return new RefundResponse($this, $result);
    }
}
