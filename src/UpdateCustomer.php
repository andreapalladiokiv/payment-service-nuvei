<?php

declare(strict_types=1);

namespace Techork\PaymentService\Nuvei;

use Techork\PaymentService\Gateway\Contract\GatewayResult;

/**
 * A no-op update. Nuvei has no endpoint for changing a user's address, so a successful "update" is
 * the unchanged `userTokenId` coming back, and nothing leaves the process.
 *
 * It takes no {@see NuveiSettings}, unlike every other Nuvei operation, because there is no call to
 * make: no client, no session, nothing to configure. A future "let's actually push the address"
 * edit would have to add one — visibly — rather than reach for a collaborator that was already
 * there.
 *
 * The only thing this could ever get wrong was receiving the reference at all, and it did get it
 * wrong for as long as it read a parameter bag: Omnipay's initializer applies an option only where
 * a matching `set…()` exists, `setCustomerReference` was declared on three OTHER Nuvei requests and
 * missing from the one whose only parameter it was, so every update answered with an empty
 * reference and an unsuccessful response. A constructor argument cannot go missing that way — but
 * the answer for an empty reference is unchanged, because fixing how a value arrives is not licence
 * to change what the operation says when it does not.
 */
final readonly class UpdateCustomer
{
    public function __construct(private string $customerReference = '') {}

    /**
     * The whole request body, such as it is. A billing address is deliberately not carried: Nuvei
     * has nowhere to put one, and inventing a field for it would be worse than saying so.
     *
     * @return array{customerReference: string}
     */
    public function payload(): array
    {
        return ['customerReference' => $this->customerReference];
    }

    /**
     * The echo, and the empty case exactly as it was.
     *
     * `UpdateCustomerRequest::sendData()` wrapped `customerReference ?? ''` in a
     * `CreateCustomerResponse`, whose `isSuccessful()` was `! empty($data['reference'])`. So a
     * non-empty reference answered successful, and an EMPTY one answered UNSUCCESSFUL — with the
     * reference still `''` rather than null, and no message at all. That triple is the shape the
     * failure took, and it is reproduced here by constructing the result directly:
     * {@see GatewayResult::failed()} would demand a reason HEAD never gave and would null the
     * reference HEAD kept.
     *
     * Reading better is not on offer here. An empty reference reaching this operation is the
     * symptom of a customer that was never linked, and saying so in a message would be an
     * improvement — but it would also be a different answer than the one callers have had.
     */
    public function update(): GatewayResult
    {
        return $this->customerReference === ''
            ? new GatewayResult(false, '', null)
            : GatewayResult::succeeded($this->customerReference);
    }
}
