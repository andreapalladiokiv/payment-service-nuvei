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
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\RegistersCustomers;
use Techork\PaymentService\Gateway\Contract\ResolvesGatewayCustomers;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;

final class NuveiGateway extends AbstractGateway implements Gateway, RegistersCustomers, ResolvesGatewayCustomers
{
    private RestClient $restClient;

    private ?GatewayCustomerRepository $gatewayCustomerRepository = null;

    #[Override]
    public function getName(): string
    {
        return 'nuvei';
    }

    #[Override]
    public function setGatewayCustomerRepository(GatewayCustomerRepository $repository): void
    {
        $this->gatewayCustomerRepository = $repository;
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

    #[Override]
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
            $options['customerId'] ?? null,
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
            $options['customerId'] ?? null,
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
            $options['customerId'] ?? null,
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
            $options['customerId'] ?? null,
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
     * Which `userTokenId` Nuvei knows this customer under.
     *
     * Nuvei documents that field as the id which "uniquely identifies your consumer/user in
     * your system", and requires it to charge a stored `userPaymentOptionId` again. This package
     * put the **email** there. So a customer who changed their email became a different customer
     * and their saved cards were orphaned, two people sharing an address were one customer, and
     * a customer with no email — optional on our side — got an empty token, which Nuvei rejects
     * outright, so the field was omitted and the stored-card payment failed.
     *
     * Told who is paying, the token is the customer id and none of that is expressible.
     *
     * **Do not deploy this without the re-keying migration.** Every Nuvei customer that exists
     * today is keyed by email. Sending a UUID for one Nuvei knows by email creates a *second*
     * Nuvei customer, and the `userPaymentOptionId` values hang off the first — the saved cards
     * become unreachable. See A3 in `docs/customer-domain-plan`: either re-register and
     * re-tokenize, or keep the email token for pre-existing customers and use ids only for new
     * ones.
     */
    /**
     * The reference this gateway knows one of our customers under, and nothing more.
     *
     * **Lookup only, and there is no creating variant.** Bringing a customer into existence at a
     * provider is its own operation now — {@see \Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface::registerCustomer()},
     * driven by whoever holds the customer. It used to be a lookup-or-create hidden here, which
     * meant saving a card could mint a provider-side customer as a side effect, and taking a
     * payment could mint one that cannot possibly own the instrument being charged: an attached
     * instrument belongs to the customer it was attached to, so a customer created now is a stray
     * one and the charge fails anyway.
     *
     * A miss therefore means no customer on this request, which is the same shape as a caller
     * naming none — and on registration it surfaces as a refusal rather than as an invented person.
     */
    private function resolveCustomerReference(
        ?GatewayCredential $gateway,
        ?string $customerId = null,
    ): ?string {
        if ($gateway === null || $customerId === null || $this->gatewayCustomerRepository === null) {
            return null;
        }

        return $this->gatewayCustomerRepository->find($gateway->getId(), $customerId);
    }
}
