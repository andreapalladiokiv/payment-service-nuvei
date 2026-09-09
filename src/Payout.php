<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use DateTimeImmutable;
use Nuvei\Api\Service\PaymentService;
use Nuvei\Api\Utils;
use Override;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\AttachedPaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Nuvei\Concern\NuveiRequestParameters;
use Throwable;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;

/**
 * Retries a refund onto an alternative instrument via Nuvei's Payout endpoint (Visa OCT /
 * Mastercard MoneySend rails). This is what {@see NuveiGateway::retryRefund()} routes to: at Nuvei
 * "refund to a different card" is a payout, not a refund, and nothing else in the codebase makes
 * that mapping visible.
 *
 * Unlike {@see Refund}, a payout is independent of the original sale — funds move from the
 * merchant balance to whatever instrument the merchant supplies.
 *
 * Raw cards (PAN) are intentionally rejected: payout with raw card data requires the service to
 * hold PCI Level 1, which is out of scope. Use a Nuvei-side ccTempToken or a stored payment method
 * instead.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final readonly class Payout implements PaymentInstrumentVisitor
{
    use NuveiRequestParameters;

    /**
     * @param  string  $customerReference  Nuvei's `userTokenId`, resolved by the gateway from this
     *   command's retry instrument before the operation was built.
     */
    public function __construct(
        private NuveiSettings $settings,
        private GatewayInfrastructure $infrastructure,
        private RefundCommand $command,
        private string $customerReference = '',
    ) {}

    /**
     * The body, without the signed envelope {@see payout()} wraps it in. The checksum covers a
     * timestamp taken at call time, so folding it in here would make the payload a test cannot
     * compare against anything.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        // Unreachable from production, and deliberately not made to match what used to happen here.
        // {@see \Techork\PaymentService\Laravel\Port\RefundAdapter} is the only caller of
        // `retryRefund`, and it reaches for it only inside `$request->retryInstrument !== null` —
        // building the very command this reads. The old wording came from omnipay's
        // `validate('instrument')`, "The instrument parameter is required", which named a parameter
        // that no longer exists; a message nobody can reach is worth having say something true.
        $instrument = $this->command->retryInstrument
            ?? throw new RuntimeException(
                'Nuvei payout needs the alternative instrument to send the money to; the refund command carried none.',
            );

        $paymentOption = $instrument->accept($this);

        $money = $this->command->amount;

        // Two ids rather than the one a payment uses: `payout.do` signs clientRequestId and
        // clientUniqueId separately in its checksum, and the pre-bridge integration sent them
        // independent. Left as it was, because changing what a checksum covers is not a cleanup.
        $clientUniqueId = $this->command->clientUniqueId ?? Uuid::uuid4()->toString();
        $clientRequestId = Uuid::uuid4()->toString();

        return [
            'clientRequestId' => $clientRequestId,
            'clientUniqueId' => $clientUniqueId,
            'amount' => $this->formatMoney($money),
            'currency' => $money->getCurrency()->getCode(),
            'userTokenId' => $this->customerReference,
            'userPaymentOption' => $paymentOption,
            // As on a payment: nothing upstream carries the buyer's address, so the loopback
            // stands in for a field Nuvei requires.
            'deviceDetails' => ['ipAddress' => '127.0.0.1'],
        ];
    }

    #[Override]
    public function visitCreditCard(CreditCard $card): never
    {
        throw new RuntimeException(
            'Nuvei payout does not accept raw card data. Tokenize the alternative '
            .'instrument first (or use a stored PaymentMethod).',
        );
    }

    #[Override]
    public function visitCash(Cash $cash): never
    {
        throw new RuntimeException('Cash is not a valid retry refund instrument.');
    }

    /**
     * @return array{userPaymentOptionId: string}
     */
    #[Override]
    public function visitToken(Token $token): array
    {
        $reference = $this->infrastructure->instruments->find($this->infrastructure->credential->getId(), $token)
            ?? throw new RuntimeException("No Nuvei reference found for token {$token->id->toString()}.");

        return ['userPaymentOptionId' => $reference];
    }

    /**
     * Refused: a stored card is charged to somebody, and a bare payment method names nobody.
     *
     * What this used to do is now {@see visitAttachedPaymentMethod()}, unchanged apart from
     * reaching the instrument through the customer that holds it. The refusal is the change:
     * the payer used to come off the address the payment method carried, so a card was charged
     * to whoever it happened to be billed to.
     */
    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw UnsupportedInstrument::needsAttachedCustomer('nuvei', 'retryRefund', $paymentMethod);
    }

    /**
     * @return array{userPaymentOptionId: string}
     */
    #[Override]
    public function visitAttachedPaymentMethod(AttachedPaymentMethod $attached): array
    {
        $reference = $this->infrastructure->instruments->find($this->infrastructure->credential->getId(), $attached->paymentMethod)
            ?? throw new RuntimeException("No Nuvei reference found for payment method {$attached->paymentMethod->id->toString()}.");

        return ['userPaymentOptionId' => $reference];
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new RuntimeException('HostedPayment is not a valid retry refund instrument.');
    }

    public function payout(): GatewayResult
    {
        // Built outside the try, as omnipay's `send()` built it outside `sendData()`: a raw card or
        // a missing instrument raises here and has always propagated rather than reading as a
        // refused payout.
        $data = $this->payload();

        try {
            $client = $this->settings->restClient;
            $service = new PaymentService($client);

            $config = $client->getConfig()
                ?? throw new RuntimeException('The Nuvei client carries no configuration, so a payout cannot be addressed.');

            $data['merchantId'] = $config->getMerchantId();
            $data['merchantSiteId'] = $config->getMerchantSiteId();
            $data['timeStamp'] = new DateTimeImmutable()->format('YmdHis');

            $data['checksum'] = Utils::calculateChecksum(
                $data,
                [
                    'merchantId',
                    'merchantSiteId',
                    'clientRequestId',
                    'clientUniqueId',
                    'amount',
                    'currency',
                    'userTokenId',
                    'timeStamp',
                    'merchantSecretKey',
                ],
                $config->getMerchantSecretKey(),
                $config->getHashAlgorithm(),
            );

            $result = $service->requestJson($data, 'payout.do');
        } catch (Throwable $e) {
            return NuveiTransactionOutcome::fromThrowable($e)->toGatewayResult();
        }

        return new NuveiTransactionOutcome($result)->toGatewayResult();
    }
}
