<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\Environment;
use Nuvei\Api\Exception\ConfigurationException;
use Nuvei\Api\Exception\ConnectionException;
use Nuvei\Api\Exception\ResponseException;
use Nuvei\Api\Exception\ValidationException;
use Nuvei\Api\RestClient;
use Nuvei\Api\Service\Payments\CreditCard as NuveiCreditCardService;
use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\AbstractRequest;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;

final class NuveiGateway extends AbstractGateway implements Gateway
{
    private RestClient $restClient;

    private ?CustomerRepository $customerRepository = null;

    #[Override]
    public function getName(): string
    {
        return 'nuvei';
    }

    #[Override]
    public function setCustomerRepository(CustomerRepository $repository): void
    {
        $this->customerRepository = $repository;
    }

    #[Override]
    public function getDefaultParameters(): array
    {
        return [
            'merchantId' => '',
            'merchantSiteId' => '',
            'secretKey' => '',
            'sessionToken' => null,
            'environment' => Environment::TEST,
        ];
    }

    public function getMerchantId(): string
    {
        return $this->getParameter('merchantId') ?? '';
    }

    public function setMerchantId(string $v): static
    {
        return $this->setParameter('merchantId', $v);
    }

    public function getMerchantSiteId(): string
    {
        return $this->getParameter('merchantSiteId') ?? '';
    }

    public function setMerchantSiteId(string $v): static
    {
        return $this->setParameter('merchantSiteId', $v);
    }

    public function getSecretKey(): string
    {
        return $this->getParameter('secretKey') ?? '';
    }

    public function setSecretKey(string $v): static
    {
        return $this->setParameter('secretKey', $v);
    }

    public function getEnvironment(): string
    {
        return $this->getParameter('environment') ?? Environment::TEST;
    }

    public function setEnvironment(string $v): static
    {
        return $this->setParameter('environment', $v);
    }

    public function getSessionToken(): ?string
    {
        return $this->getParameter('sessionToken');
    }

    public function setSessionToken(?string $v): static
    {
        return $this->setParameter('sessionToken', $v);
    }

    public function setSiteId(string $v): static
    {
        return $this->setMerchantSiteId($v);
    }

    /**
     * @throws ConnectionException
     * @throws ResponseException
     * @throws ConfigurationException
     * @throws ValidationException
     */
    #[Override]
    public function initialize(array $parameters = []): static
    {
        parent::initialize($parameters);

        $this->restClient = new RestClient([
            'environment' => $this->getEnvironment(),
            'merchantId' => $this->getMerchantId(),
            'merchantSiteId' => $this->getMerchantSiteId(),
            'merchantSecretKey' => $this->getSecretKey(),
        ]);

        // AbstractGateway::__construct() calls initialize() with no args, so
        // we'd otherwise hit Nuvei's session-token endpoint with empty creds
        // on every `new NuveiGateway`. The session token only matters once
        // the factory has injected real credentials.
        if ($this->getMerchantId() !== '' && $this->getSessionToken() === null) {
            $this->setSessionToken(new NuveiCreditCardService($this->restClient)->getSessionToken());
        }

        return $this;
    }

    public function createCustomer(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(CreateCustomerRequest::class, $parameters);
    }

    public function updateCustomer(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(UpdateCustomerRequest::class, $parameters);
    }

    public function createCard(array $options = []): AbstractRequest
    {
        return $this->createRequest(CreateCardRequest::class, $options);
    }

    #[Override]
    public function createPaymentMethod(array $options = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $options['gateway'] ?? null,
            $options['instrument'] ?? null,
            $options['billingAddress'] ?? null,
        );
        if ($customerReference !== null) {
            $options['customerReference'] = $customerReference;
        }

        return $this->createRequest(CreatePaymentMethodRequest::class, $options);
    }

    public function purchase(array $options = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $options['gateway'] ?? null,
            $options['instrument'] ?? null,
            $options['billingAddress'] ?? null,
        );
        if ($customerReference !== null) {
            $options['customerReference'] = $customerReference;
        }

        return $this->createRequest(PurchaseRequest::class, $options);
    }

    public function authorize(array $options = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $options['gateway'] ?? null,
            $options['instrument'] ?? null,
            $options['billingAddress'] ?? null,
        );
        if ($customerReference !== null) {
            $options['customerReference'] = $customerReference;
        }

        return $this->createRequest(AuthorizeRequest::class, $options);
    }

    public function capture(array $options = []): AbstractRequest
    {
        return $this->createRequest(CaptureRequest::class, $options);
    }

    public function refund(array $options = []): AbstractRequest
    {
        return $this->createRequest(RefundRequest::class, $options);
    }

    #[Override]
    public function retryRefund(array $options = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $options['gateway'] ?? null,
            $options['instrument'] ?? null,
            $options['billingAddress'] ?? null,
        );
        if ($customerReference !== null) {
            $options['customerReference'] = $customerReference;
        }

        return $this->createRequest(PayoutRequest::class, $options);
    }

    #[Override]
    public function void(array $options = []): AbstractRequest
    {
        return $this->createRequest(VoidRequest::class, $options);
    }

    #[Override]
    public function issueVirtualCard(array $options = []): AbstractRequest
    {
        throw UnsupportedOperation::forGateway(
            'nuvei',
            'issueVirtualCard',
            'Nuvei acquires payments and issues no cards; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function updateVirtualCard(array $options = []): AbstractRequest
    {
        throw UnsupportedOperation::forGateway(
            'nuvei',
            'updateVirtualCard',
            'Nuvei acquires payments and issues no cards; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function terminateVirtualCard(array $options = []): AbstractRequest
    {
        throw UnsupportedOperation::forGateway(
            'nuvei',
            'terminateVirtualCard',
            'Nuvei acquires payments and issues no cards; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    protected function createRequest($class, array $options): AbstractRequest
    {
        $extra = [
            'restClient' => $this->restClient,
            'environment' => $this->getEnvironment(),
            'merchantId' => $this->getMerchantId(),
            'merchantSiteId' => $this->getMerchantSiteId(),
            'secretKey' => $this->getSecretKey(),
        ];

        $sessionToken = $this->getSessionToken();
        if ($sessionToken !== null) {
            $extra['sessionToken'] = $sessionToken;
        }

        return parent::createRequest($class, $options + $extra);
    }

    /**
     * For Nuvei, the customer reference is the email (userTokenId).
     * Finds existing or creates a new Nuvei user via createUser API.
     */
    private function resolveCustomerReference(
        ?GatewayCredential $gateway,
        ?PaymentInstrument $instrument,
        ?BillingAddress $billingAddress,
    ): ?string {
        if ($this->customerRepository === null || $gateway === null || $instrument === null) {
            return null;
        }

        $gatewayId = $gateway->getId();

        // An empty-string link counts as missing: legacy rows exist where
        // `customer_reference` was written as '', and an empty `userTokenId`
        // makes Nuvei reject any payment that references a stored
        // userPaymentOptionId.
        $existing = $this->customerRepository->findByInstrument($gatewayId, $instrument);
        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        if ($billingAddress === null || $billingAddress->email === null) {
            return null;
        }

        // One key, the way Stripe already did it. Spreading the address over seven required a
        // setter for each, and six were missing — so the request received none of them.
        $response = $this->createCustomer(['billingAddress' => $billingAddress])->send();

        if (! $response->isSuccessful()) {
            throw new RuntimeException("Nuvei createCustomer failed: {$response->getMessage()}");
        }

        $customerReference = $response->getTransactionReference()
            ?? throw new RuntimeException('Nuvei createCustomer returned no reference.');

        $this->customerRepository->saveAndAttach($gatewayId, $instrument, $customerReference);

        return $customerReference;
    }
}
