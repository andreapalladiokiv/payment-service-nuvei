<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\Environment;
use Nuvei\Api\RestClient;
use Nuvei\Api\Service\Payments\CreditCard as NuveiCreditCardService;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Concern\HoldsInfrastructure;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;

/**
 * Nuvei — an acquiring gateway. It takes payments, vaults instruments and refunds; it issues no
 * cards, and the three card-issuing operations refuse rather than pretend.
 *
 * Each role hands its command to the operation that serves it and returns what that operation
 * answered. There is no request object built here for something else to send, and no response
 * folded by a shared assembler afterwards: an operation knows which endpoint it called and what
 * the reply means, so it maps it once, where the answer is still in hand.
 *
 * Each role builds its operation inline and invokes it. There is no per-operation accessor handing
 * one back unsent: those existed only so a test could reach `payload()` without making the call,
 * and a test does not need them — {@see NuveiSettings} is a public value object, so a test builds
 * `new Refund(new NuveiSettings(...), $command)` itself. A seam does not belong in the gateway's
 * public API when the thing it reaches is already reachable.
 */
final class NuveiGateway implements Gateway
{
    use HoldsInfrastructure;

    private string $merchantId = '';

    private string $merchantSiteId = '';

    private string $secretKey = '';

    private string $environment = Environment::TEST;

    private ?string $sessionToken = null;

    private RestClient $restClient;

    private ?CustomerRepository $customerRepository = null;

    #[Override]
    public function getName(): string
    {
        return 'nuvei';
    }

    public function setCustomerRepository(CustomerRepository $repository): void
    {
        $this->customerRepository = $repository;
    }

    public function getMerchantId(): string
    {
        return $this->merchantId;
    }

    public function getMerchantSiteId(): string
    {
        return $this->merchantSiteId;
    }

    public function getSecretKey(): string
    {
        return $this->secretKey;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function getSessionToken(): ?string
    {
        return $this->sessionToken;
    }

    /**
     * One pass: settings in, client out. The session token is fetched here and only when there
     * are credentials to fetch it with — `AbstractGateway::__construct()` used to call
     * `initialize()` with nothing, so every `new NuveiGateway` reached for a token with empty
     * creds until a guard was added. A gateway that is configured once, with real settings, has
     * no such moment.
     */
    #[Override]
    public function configure(GatewayInfrastructure $infrastructure): void
    {
        $this->infrastructure = $infrastructure;
        $this->customerRepository = $infrastructure->customers;
        $this->merchantId = $infrastructure->stringSetting('merchantId');
        $this->merchantSiteId = $infrastructure->stringSetting('merchantSiteId');
        $this->secretKey = $infrastructure->stringSetting('secretKey');
        $this->environment = $infrastructure->stringSetting('environment', Environment::TEST);

        $sessionToken = $infrastructure->stringSetting('sessionToken');
        $this->sessionToken = $sessionToken === '' ? null : $sessionToken;

        $this->restClient = new RestClient([
            'environment' => $this->environment,
            'merchantId' => $this->merchantId,
            'merchantSiteId' => $this->merchantSiteId,
            'merchantSecretKey' => $this->secretKey,
        ]);

        if ($this->merchantId !== '' && $this->sessionToken === null) {
            $this->sessionToken = new NuveiCreditCardService($this->restClient)->getSessionToken();
        }
    }

    /**
     * What the deployment contributes to a request, as opposed to what the caller asked for. The
     * {@see RestClient} travels inside it, so every operation of a configured gateway talks over
     * the same client and the same session.
     *
     * Public, unlike the operation builders that were here: this is the gateway's own configuration
     * rather than a way to reach an operation, and the identity of that shared client is not
     * observable through the credential getters.
     */
    public function settings(): NuveiSettings
    {
        return new NuveiSettings(
            restClient: $this->restClient,
            merchantId: $this->merchantId,
            merchantSiteId: $this->merchantSiteId,
            secretKey: $this->secretKey,
            environment: $this->environment,
            sessionToken: $this->sessionToken,
        );
    }

    /**
     * Registers the Nuvei user an instrument will hang off. Not on the {@see Gateway} contract —
     * the customer operations are Nuvei's own, reached by the application and by
     * {@see resolveCustomerReference()} — but shaped like every other role here: it performs the
     * call and answers with the result, rather than handing back something for the caller to send.
     */
    public function createCustomer(?BillingAddress $billingAddress = null, string $email = ''): GatewayResult
    {
        return new CreateCustomer($this->settings(), $billingAddress, $email)->create();
    }

    public function updateCustomer(string $customerReference = ''): GatewayResult
    {
        return new UpdateCustomer($customerReference)->update();
    }

    /**
     * No customer is resolved for tokenization: a ccTempToken links an instrument to nobody, and
     * resolution can cost a `createUser` round trip.
     */
    #[Override]
    public function tokenize(VaultCommand $command): RegistrationResult
    {
        return new Tokenize($this->settings(), $this->infrastructure(), $command)->tokenize();
    }

    #[Override]
    public function registerPaymentMethod(VaultCommand $command): RegistrationResult
    {
        return new RegisterPaymentMethod(
            $this->settings(),
            $this->infrastructure(),
            $command,
            $this->customerFor($command->instrument, $command->billingAddress),
        )->register();
    }

    #[Override]
    public function charge(PlacementCommand $command): AuthorizationResult
    {
        return new Purchase(
            $this->settings(),
            $this->infrastructure(),
            $command,
            $this->customerFor($command->instrument, $command->billingAddress),
        )->charge();
    }

    #[Override]
    public function authorize(PlacementCommand $command): AuthorizationResult
    {
        return $this->hold($command);
    }

    /**
     * The same provider call as {@see authorize()}, with the series position added — which is why
     * both go through one {@see Authorize} and differ only in the command they carry. They stay
     * separate roles, because whether a payment belongs to a series is the caller's to state and no
     * field of an ordinary authorization implies it.
     */
    #[Override]
    public function authorizeRebilling(RebillingCommand $command): AuthorizationResult
    {
        return $this->hold($command);
    }

    private function hold(PlacementCommand|RebillingCommand $command): AuthorizationResult
    {
        return new Authorize(
            $this->settings(),
            $this->infrastructure(),
            $command,
            $this->customerFor($command->instrument, $command->billingAddress),
        )->authorize();
    }

    #[Override]
    public function capture(CaptureCommand $command): GatewayResult
    {
        return new Capture($this->settings(), $command)->capture();
    }

    #[Override]
    public function refund(RefundCommand $command): GatewayResult
    {
        return new Refund($this->settings(), $command)->refund();
    }

    /**
     * "Refund to a different card" is a payout at Nuvei, not a refund — different rails, different
     * endpoint, independent of the original sale. Nothing else in the codebase makes that mapping
     * visible.
     */
    #[Override]
    public function retryRefund(RefundCommand $command): GatewayResult
    {
        return new Payout(
            $this->settings(),
            $this->infrastructure(),
            $command,
            $this->customerFor($command->retryInstrument, null),
        )->payout();
    }

    /**
     * Cancelling an intent is voiding its authorization; Nuvei has no other verb for it.
     */
    #[Override]
    public function cancel(CancelCommand $command): GatewayResult
    {
        return new VoidTransaction($this->settings(), $command)->void();
    }

    #[Override]
    public function issueVirtualCard(IssueCardCommand $command): VirtualCardResult
    {
        throw UnsupportedOperation::forGateway(
            'nuvei',
            'issueVirtualCard',
            'Nuvei acquires payments and issues no cards; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function updateVirtualCard(UpdateCardCommand $command): VirtualCardResult
    {
        throw UnsupportedOperation::forGateway(
            'nuvei',
            'updateVirtualCard',
            'Nuvei acquires payments and issues no cards; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function terminateVirtualCard(TerminateCardCommand $command): GatewayResult
    {
        throw UnsupportedOperation::forGateway(
            'nuvei',
            'terminateVirtualCard',
            'Nuvei acquires payments and issues no cards; route card issuing to an issuing gateway.',
        );
    }

    /**
     * Every operation that carries an instrument gets the customer that instrument belongs to,
     * resolved once here rather than per operation, and handed to the operation as a constructor
     * argument. Only the four that need a `userTokenId` on the wire ask for it: resolution can cost
     * a createUser round trip, so widening it to capture, refund or void would add a network call
     * to operations that reference an existing transaction and need no user at all.
     *
     * Empty, not null, because that is what the wire wants: Nuvei rejects an empty `userTokenId`
     * outright, so an operation omits the field rather than sending one.
     */
    private function customerFor(?PaymentInstrument $instrument, ?BillingAddress $billingAddress): string
    {
        return $this->resolveCustomerReference(
            $this->infrastructure()->credential,
            $instrument,
            $billingAddress,
        ) ?? '';
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

        // One value, the way Stripe already did it. Spreading the address over seven parameters
        // required a setter for each, and six were missing — so the request received none of them.
        $result = $this->createCustomer($billingAddress);

        if (! $result->success) {
            throw new RuntimeException("Nuvei createCustomer failed: {$result->message}");
        }

        $customerReference = $result->reference
            ?? throw new RuntimeException('Nuvei createCustomer returned no reference.');

        $this->customerRepository->saveAndAttach($gatewayId, $instrument, $customerReference);

        return $customerReference;
    }
}
