<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4;

use B4x\Edoreczenia\Exception\EdoreczeniaException;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\Internal\Payload;
use B4x\Edoreczenia\Internal\Transport;
use B4x\Edoreczenia\SearchEngine\V4\Model\AddressSearchResult;
use B4x\Edoreczenia\SearchEngine\V4\Model\OwnAddressDetails;
use B4x\Edoreczenia\SearchEngine\V4\Model\SearchResult;
use B4x\Edoreczenia\SearchEngine\V4\Request\AdvancedSearch;

/**
 * Search Engine API v4 (contract se-api 4.0.5): looks up electronic delivery addresses (ADE)
 * in the Electronic Address Database (BAE).
 *
 * Calls go to the designated operator's SE proxy, which checks that the "senderEda" sent in
 * the body matches the mailbox in the access token; the SDK therefore always sends the
 * mailbox address the client was configured with.
 *
 * All three operations are read-only queries, so they are retried when a {@see \B4x\Edoreczenia\Http\RetryPolicy}
 * with retries is configured.
 */
final class SearchEngineClient
{
    public const MAX_ADDRESS_LOOKUP = 1000;

    /**
     * @internal use {@see \B4x\Edoreczenia\EdoreczeniaClient::searchEngine()}
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $baseUri,
        private readonly string $senderAddress,
    ) {}

    /**
     * Scenario 1 – find recipient addresses by holder data or by known addresses (POST /search/bae_search).
     *
     * A search without matches fails with {@see \B4x\Edoreczenia\Exception\NotFoundException}
     * carrying {@see ErrorCode::NO_RESULTS}.
     *
     * @throws EdoreczeniaException
     */
    public function search(AdvancedSearch $search): SearchResult
    {
        $data = $this->transport->postJson(
            $this->baseUri . 'search/bae_search',
            $search->toPayload($this->senderAddress),
            'SE API v4 bae_search',
            true,
        );

        return SearchResult::fromPayload(Payload::root($data));
    }

    /**
     * Scenario 3 – look up many recipient addresses at once (POST /search/eda-search).
     *
     * Non-public senders may look up to 1000 addresses per call (SE API v4 announcement, 16.04.2025).
     *
     * @param list<string> $addresses recipient ADEs, 1-1000
     * @param int          $limit     page size, 1-1000
     *
     * @throws EdoreczeniaException
     */
    public function findByAddresses(array $addresses, int $offset = 0, int $limit = 20): AddressSearchResult
    {
        if ($addresses === [] || \count($addresses) > self::MAX_ADDRESS_LOOKUP) {
            throw new InvalidArgumentException(\sprintf('Provide between 1 and %d addresses.', self::MAX_ADDRESS_LOOKUP));
        }
        foreach ($addresses as $address) {
            if (!\is_string($address) || $address === '' || preg_match('/\s/u', $address) === 1) {
                throw new InvalidArgumentException('Every address must be a non-empty string without whitespace.');
            }
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('offset must be >= 0.');
        }
        if ($limit < 1 || $limit > self::MAX_ADDRESS_LOOKUP) {
            throw new InvalidArgumentException(\sprintf('limit must be between 1 and %d.', self::MAX_ADDRESS_LOOKUP));
        }

        $data = $this->transport->postJson(
            $this->baseUri . 'search/eda-search',
            [
                'recipientEdas' => array_values($addresses),
                'senderEda' => $this->senderAddress,
                'offset' => $offset,
                'limit' => $limit,
            ],
            'SE API v4 eda-search',
            true,
        );

        return AddressSearchResult::fromPayload(Payload::root($data));
    }

    /**
     * Scenario 2 – current data of the configured mailbox's own address (POST /search/eda-confirmation).
     *
     * @throws EdoreczeniaException
     */
    public function getOwnAddressDetails(): OwnAddressDetails
    {
        $data = $this->transport->postJson(
            $this->baseUri . 'search/eda-confirmation',
            ['recipientEda' => $this->senderAddress, 'senderEda' => $this->senderAddress],
            'SE API v4 eda-confirmation',
            true,
        );

        return OwnAddressDetails::fromPayload(Payload::root($data));
    }
}
