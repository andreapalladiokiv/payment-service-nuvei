<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use DateTimeImmutable;
use Money\Money;
use Nuvei\Api\Environment;
use Omnipay\Common\Message\AbstractResponse;
use Override;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\HostedPayment;

final class PurchaseRequest extends NuveiPaymentRequest
{
    private const string CASHIER_URL_TEST = 'https://ppp-test.nuvei.com/ppp/purchase.do';

    private const string CASHIER_URL_PROD = 'https://secure.safecharge.com/ppp/purchase.do';

    private const string CASHIER_VERSION = '4.0.0';

    #[Override]
    protected function transactionType(): string
    {
        return 'Sale';
    }

    #[Override]
    protected function settleType(): ?int
    {
        return null;
    }

    #[Override]
    protected function wrapResponse(array $result): AbstractResponse
    {
        return new PurchaseResponse($this, $result);
    }

    #[Override]
    public function getData(): array
    {
        $this->validate('money', 'instrument', 'gateway');

        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        if ($instrument instanceof HostedPayment) {
            return $this->visitHostedPayment($instrument);
        }

        return parent::getData();
    }

    /**
     * Hosted-payment flow via Nuvei Cashier (Hosted Payment Page). The browser
     * POSTs the returned form to {@see purchase.do}; Nuvei collects card data
     * server-side and notifies us asynchronously via DMN. No REST call is
     * made here — all coordination happens through the form fields and the
     * subsequent {@see Webhook\Handler\SaleHandler} on the DMN side.
     */
    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): array
    {
        /** @var Money $money */
        $money = $this->getParameter('money');

        $merchantId = (string) ($this->getParameter('merchantId') ?? '');
        $merchantSiteId = (string) ($this->getParameter('merchantSiteId') ?? '');
        $secretKey = (string) ($this->getParameter('secretKey') ?? '');
        $environment = (string) ($this->getParameter('environment') ?? Environment::TEST);

        $totalAmount = $this->formatMoney($money);
        $currency = $money->getCurrency()->getCode();
        $timeStamp = (new DateTimeImmutable)->format('YmdHis');

        $clientUniqueId = (string) ($this->getParameter('clientUniqueId') ?? Uuid::uuid4()->toString());

        $checksum = hash('sha256', implode('', [
            $merchantId,
            $merchantSiteId,
            $totalAmount,
            $currency,
            $timeStamp,
            $secretKey,
        ]));

        $formFields = [
            'merchant_id' => $merchantId,
            'merchant_site_id' => $merchantSiteId,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'time_stamp' => $timeStamp,
            'version' => self::CASHIER_VERSION,
            'item_name_1' => 'Payment',
            'item_amount_1' => $totalAmount,
            'item_quantity_1' => '1',
            'success_url' => $hosted->successUrl,
            'error_url' => $hosted->cancelUrl,
            'pending_url' => $hosted->successUrl,
            'back_url' => $hosted->cancelUrl,
            'clientUniqueId' => $clientUniqueId,
            'checksum' => $checksum,
        ];

        $userTokenId = $this->getCustomerReference();
        if ($userTokenId !== '') {
            $formFields['user_token_id'] = $userTokenId;
        }

        return [
            '_hosted' => true,
            'cashier_url' => $environment === Environment::LIVE ? self::CASHIER_URL_PROD : self::CASHIER_URL_TEST,
            'form_fields' => $formFields,
            'reference' => $clientUniqueId,
        ];
    }

    #[Override]
    public function sendData($data): AbstractResponse
    {
        if (! empty($data['_hosted'])) {
            return $this->wrapResponse([
                'status' => 'PENDING',
                'transactionStatus' => 'REDIRECT',
                'transactionId' => $data['reference'],
                'challenge' => new RedirectChallenge(
                    transactionId: $data['reference'],
                    url: $data['cashier_url'],
                    formFields: $data['form_fields'],
                ),
            ]);
        }

        return parent::sendData($data);
    }
}
