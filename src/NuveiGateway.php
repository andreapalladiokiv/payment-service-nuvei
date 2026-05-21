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
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;

final class NuveiGateway extends AbstractGateway implements Gateway
{
    private RestClient $restClient;

    private ?CustomerRepository $customerRepository = null;

    public function getName(): string
    {
        return 'nuvei';
    }

    public function setCustomerRepository(CustomerRepository $repository): void
    {
        $this->customerRepository = $repository;
    }

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

    public function createCard(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(CreateCardRequest::class, $parameters);
    }

    public function createPaymentMethod(array $parameters = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $parameters['gateway'] ?? null,
            $parameters['instrument'] ?? null,
            $parameters['billingAddress'] ?? null,
        );
        if ($customerReference !== null) {
            $parameters['customerReference'] = $customerReference;
        }

        return $this->createRequest(CreatePaymentMethodRequest::class, $parameters);
    }

    public function purchase(array $parameters = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $parameters['gateway'] ?? null,
            $parameters['instrument'] ?? null,
            $parameters['billingAddress'] ?? null,
        );
        if ($customerReference !== null) {
            $parameters['customerReference'] = $customerReference;
        }

        return $this->createRequest(PurchaseRequest::class, $parameters);
    }

    public function authorize(array $parameters = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $parameters['gateway'] ?? null,
            $parameters['instrument'] ?? null,
            $parameters['billingAddress'] ?? null,
        );
        if ($customerReference !== null) {
            $parameters['customerReference'] = $customerReference;
        }

        return $this->createRequest(AuthorizeRequest::class, $parameters);
    }

    public function capture(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(CaptureRequest::class, $parameters);
    }

    public function refund(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(RefundRequest::class, $parameters);
    }

    public function retryRefund(array $parameters = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $parameters['gateway'] ?? null,
            $parameters['instrument'] ?? null,
            $parameters['billingAddress'] ?? null,
        );
        if ($customerReference !== null) {
            $parameters['customerReference'] = $customerReference;
        }

        return $this->createRequest(PayoutRequest::class, $parameters);
    }

    public function void(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(VoidRequest::class, $parameters);
    }

    public function issueVirtualCard(array $parameters = []): AbstractRequest
    {
        throw new RuntimeException('Nuvei does not support virtual card issuance.');
    }

    public function updateVirtualCard(array $parameters = []): AbstractRequest
    {
        throw new RuntimeException('Nuvei does not support virtual card update.');
    }

    public function terminateVirtualCard(array $parameters = []): AbstractRequest
    {
        throw new RuntimeException('Nuvei does not support virtual card termination.');
    }

    protected function createRequest($class, array $parameters): AbstractRequest
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

        return parent::createRequest($class, $parameters + $extra);
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

        $existing = $this->customerRepository->findByInstrument($gatewayId, $instrument);
        if ($existing !== null) {
            return $existing;
        }

        if ($billingAddress === null || $billingAddress->email === null) {
            return null;
        }

        $response = $this->createCustomer([
            'email' => (string) $billingAddress->email,
            'firstName' => $billingAddress->firstName,
            'lastName' => $billingAddress->lastName,
            'country' => (string) $billingAddress->country,
            'address' => $billingAddress->line,
            'city' => $billingAddress->city,
            'postal_code' => $billingAddress->postalCode,
            'state' => $billingAddress->state ? (string) $billingAddress->state : null,
        ])->send();

        if (! $response->isSuccessful()) {
            throw new RuntimeException("Nuvei createCustomer failed: {$response->getMessage()}");
        }

        $customerReference = $response->getTransactionReference()
            ?? throw new RuntimeException('Nuvei createCustomer returned no reference.');

        $this->customerRepository->saveAndAttach($gatewayId, $instrument, $customerReference);

        return $customerReference;
    }
}
