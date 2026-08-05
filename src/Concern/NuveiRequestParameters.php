<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei\Concern;

use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Nuvei\Api\RestClient;
use Techork\PaymentService\Common\ValueObject\BillingAddress;

trait NuveiRequestParameters
{
    public function setRestClient(RestClient $client): self
    {
        return $this->setParameter('restClient', $client);
    }

    public function setSessionToken(?string $value): self
    {
        return $this->setParameter('sessionToken', $value);
    }

    /**
     * `static`, not `self`: this overrides {@see \Omnipay\Common\Message\AbstractRequest::setMoney},
     * which is annotated `@return $this`. Naming the using class instead would promise a
     * fixed type where the parent promises the called one.
     */
    public function setMoney(Money $value): static
    {
        return $this->setParameter('money', $value);
    }

    public function setClientId(string $value): self
    {
        return $this->setParameter('clientId', $value);
    }

    public function setClientUniqueId(?string $value): self
    {
        return $this->setParameter('clientUniqueId', $value);
    }

    public function setBillingAddress(?BillingAddress $v): self
    {
        return $this->setParameter('billingAddress', $v);
    }

    public function getBillingAddress(): ?BillingAddress
    {
        $address = $this->getParameter('billingAddress');

        return $address instanceof BillingAddress ? $address : null;
    }

    public function setEnvironment(string $v): self
    {
        return $this->setParameter('environment', $v);
    }

    public function setMerchantId(string $v): self
    {
        return $this->setParameter('merchantId', $v);
    }

    public function setMerchantSiteId(string $v): self
    {
        return $this->setParameter('merchantSiteId', $v);
    }

    public function setSecretKey(string $v): self
    {
        return $this->setParameter('secretKey', $v);
    }

    public function setStatementDescription(?string $v): self
    {
        return $this->setParameter('statementDescription', $v);
    }

    public function getStatementDescription(): ?string
    {
        return $this->getParameter('statementDescription');
    }

    protected function formatMoney(Money $money): string
    {
        return new DecimalMoneyFormatter(new ISOCurrencies)->format($money);
    }

    protected function formatBillingAddress(?BillingAddress $address): array
    {
        if ($address === null) {
            return [];
        }

        return array_filter([
            'firstName' => $address->firstName,
            'lastName' => $address->lastName,
            'email' => $address->email ? (string) $address->email : null,
            'phone' => $address->phone ? (string) $address->phone : null,
            'address' => $address->line,
            'addressLine2' => $address->lineExtra !== '' ? $address->lineExtra : null,
            'city' => $address->city,
            'country' => (string) $address->country,
            'zip' => $address->postalCode,
            'state' => $address->state ? (string) $address->state : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
