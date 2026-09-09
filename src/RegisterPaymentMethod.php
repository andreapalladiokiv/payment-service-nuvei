<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Nuvei\Api\RestClient;
use Nuvei\Api\Service\PaymentService;
use Nuvei\Api\Service\UserPaymentOptions;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\AttachedPaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use Throwable;
use ValueError;

/**
 * Creates a permanent Nuvei User Payment Option (UPO), by either of the two routes Nuvei offers.
 *
 * A ccTempToken is converted through `addUPOCreditCardByTempToken`, a vault operation. A card is
 * registered through a zero-amount `Auth` on `payment.do`, which returns a `userPaymentOptionId`
 * just the same and additionally comes back with the issuer's AVS and CVV verdicts —
 * `addUPOCreditCard.do` would also accept the card, but it takes no CVV and returns no checks, so
 * it cannot answer the question a registration is usually asked to answer.
 *
 * The zero-amount Auth is what the pre-bridge integration used for years. It does reach the issuer,
 * which is the point: registration happens lazily, while the cardholder is already paying, so a
 * verification against their account is both expected and useful.
 *
 * The two routes answer in different shapes and {@see map()} reads both. That reading used to be a
 * response class of its own, interrogated through `CardChecksProvider` by a shared assembler — but
 * only this operation ever produced those two shapes, so nothing was shared and the checks made a
 * round trip through an interface to reach the result they belong on.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final readonly class RegisterPaymentMethod implements PaymentInstrumentVisitor
{
    use NuveiRequestParameters;

    /**
     * Nuvei needs an amount and a currency even to verify. Zero is the
     * account-verification amount the schemes define for exactly this, and the
     * currency is immaterial to a zero-value message.
     */
    private const string VERIFICATION_CURRENCY = 'USD';

    /**
     * @param  string  $customerReference  Nuvei's `userTokenId` — the user the UPO is attached to,
     *   resolved by the gateway before this operation was built.
     */
    public function __construct(
        private NuveiSettings $settings,
        private GatewayInfrastructure $infrastructure,
        private VaultCommand $command,
        private string $customerReference = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            ...$this->command->instrument->accept($this),
            'userTokenId' => $this->customerReference,
        ];
    }

    /**
     * @return array{paymentOption: array{card: array<string, mixed>}}
     */
    #[Override]
    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->infrastructure->decrypter;

        return [
            'paymentOption' => [
                'card' => array_filter([
                    'cardNumber' => $card->number->getNumber($decrypter),
                    'cardHolderName' => (string) $card->holder,
                    'expirationMonth' => $card->expiration->format('m'),
                    'expirationYear' => $card->expiration->format('Y'),
                    'CVV' => $card->cvc->getCvc($decrypter) ?: null,
                ]),
            ],
        ];
    }

    #[Override]
    public function visitCash(Cash $cash): never
    {
        throw new ValueError('Nuvei does not support cash for payment method creation.');
    }

    /**
     * @return array{ccTempToken: string}
     */
    #[Override]
    public function visitToken(Token $token): array
    {
        return [
            'ccTempToken' => $this->infrastructure->instruments->find($this->infrastructure->credential->getId(), $token)
                ?? throw new RuntimeException('No Nuvei gateway reference found for token '.$token->id->toString()),
        ];
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('PaymentMethod cannot be converted to UPO via Nuvei.');
    }

    /**
     * An attached one is refused for the same reason as a bare one: this operation is what
     * PRODUCES a stored instrument, so being handed one is a caller's mistake either way, and
     * having a customer attached does not make a stored card re-storable.
     */
    #[Override]
    public function visitAttachedPaymentMethod(AttachedPaymentMethod $attached): never
    {
        throw new RuntimeException('PaymentMethod cannot be converted to UPO via Nuvei.');
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('nuvei', 'createPaymentMethod', $hosted);
    }

    public function register(): RegistrationResult
    {
        // Built outside the try, as omnipay's `send()` built it outside `sendData()`: an instrument
        // this cannot vault raises from the visitor and has always propagated rather than reading
        // as a declined registration.
        $data = $this->payload();

        try {
            $client = $this->settings->restClient;

            $result = isset($data['ccTempToken'])
                ? $this->convertTempToken($client, $data)
                : $this->verifyCard($client, $data);
        } catch (Throwable $e) {
            return RegistrationResult::failed($e->getMessage());
        }

        return $this->map($result);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function convertTempToken(RestClient $client, array $data): array
    {
        return new UserPaymentOptions($client)->addUPOCreditCardByTempToken([
            'sessionToken' => $this->settings->sessionToken,
            'userTokenId' => $data['userTokenId'],
            'clientRequestId' => uniqid('upo_', true),
            'ccTempToken' => $data['ccTempToken'],
        ]);
    }

    /**
     * Registers the card by asking the issuer to verify it for zero.
     *
     * LIVE DEFECT, carried over unchanged and not introduced here: this body sends no
     * `deviceDetails`, which `payment.do` marks mandatory. The SDK's `appendIpAddress()` fills it
     * from `$_SERVER['REMOTE_ADDR']`, which exists under a web request and NOT under CLI — so
     * registering a raw card from a queue worker never leaves the process and fails with
     * "Missing input parameters: deviceDetails". A payment does not have this problem;
     * {@see Concern\PaymentBody} sends the loopback address explicitly. Fixing it means adding a
     * field to the request, which is a behaviour change and belongs in its own commit.
     *
     * `storedCredentialsMode: '0'` belongs here and only here — this is the moment a
     * credential is stored, which is what the parameter describes: Nuvei's REST 1.0
     * reference defines it as showing "whether or not stored tokenized card data is
     * sent to execute the transaction", `'0'` being "the card data was entered for the
     * first time, and, upon completion of the transaction, is tokenized and stored".
     * Registration is reached only through `registerPaymentMethod`, an operation of its
     * own, so saying it here says it exactly once.
     *
     * It does NOT establish the MIT chain, and the note that used to stand here
     * claiming it did was wrong: what marks a chain is `isRebilling`, and that travels
     * on `authorizeRebilling`. Payments accordingly send no stored-credential marker at
     * all now — {@see Concern\PaymentBody::visitPaymentMethod} used to attach mode `'1'`
     * to every stored instrument, deriving a stored-credential claim from the
     * instrument's shape rather than from the payment.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function verifyCard(RestClient $client, array $data): array
    {
        $card = $data['paymentOption']['card'];
        $card['storedCredentials'] = ['storedCredentialsMode' => '0'];

        $clientRequestId = uniqid('upo_', true);

        return new PaymentService($client)->createPayment([
            'sessionToken' => $this->settings->sessionToken,
            'clientRequestId' => $clientRequestId,
            'clientUniqueId' => $this->command->clientUniqueId ?? $clientRequestId,
            'userTokenId' => $data['userTokenId'],
            'amount' => '0',
            'currency' => self::VERIFICATION_CURRENCY,
            'transactionType' => 'Auth',
            'paymentOption' => ['card' => $card],
            'billingAddress' => $this->formatBillingAddress($this->command->customer),
        ]);
    }

    /**
     * Reads either shape the operation can produce.
     *
     * `addUPOCreditCardByTempToken` answers flat, with `userPaymentOptionId` at the top level. The
     * zero-amount `Auth` answers like any other payment: the id is nested under `paymentOption`,
     * and the outcome is `transactionStatus` rather than `status` — the latter only reports that
     * the API accepted the request, so reading it alone would take a decline for a success.
     *
     * The checks are the reason a card takes the payment route at all, and they exist only on that
     * shape. The vault route reports none, which surfaces as null rather than as a check that
     * passed.
     *
     * @param  array<string, mixed>  $result
     */
    private function map(array $result): RegistrationResult
    {
        $reference = $result['userPaymentOptionId']
            ?? $result['paymentOption']['userPaymentOptionId']
            ?? null;
        $reference = $reference === null || $reference === '' ? null : (string) $reference;

        $transactionStatus = $result['transactionStatus'] ?? null;

        $successful = ($result['status'] ?? '') === 'SUCCESS'
            && ($transactionStatus === null || $transactionStatus === 'APPROVED')
            && $reference !== null;

        if (! $successful) {
            return RegistrationResult::failed($this->message($result) ?? 'Gateway returned an unsuccessful response.');
        }

        return RegistrationResult::succeeded($reference)->withChecks(
            $this->avs($result)[0],
            $this->avs($result)[1],
            $this->cvcCheck($result),
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function message(array $result): ?string
    {
        $message = $result['reason']
            ?? $result['gwErrorReason']
            ?? $result['errCode']
            ?? $result['transactionStatus']
            ?? null;

        return $message === null ? null : (string) $message;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{0: ?CheckResult, 1: ?CheckResult}
     */
    private function avs(array $result): array
    {
        $letter = $result['paymentOption']['card']['avsCode'] ?? null;

        return $letter === null || $letter === ''
            ? [null, null]
            : NuveiSchemeChecks::avsToLineAndPostal($letter);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function cvcCheck(array $result): ?CheckResult
    {
        $cvv = $result['paymentOption']['card']['cvv2Reply'] ?? null;

        return $cvv === null || $cvv === '' ? null : NuveiSchemeChecks::cvvToCheckResult($cvv);
    }
}
