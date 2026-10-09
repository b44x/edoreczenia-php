<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Request;

use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\SearchEngine\V4\Enum\OfficialIdType;
use B4x\Edoreczenia\SearchEngine\V4\Enum\SearchCategory;

/**
 * Criteria for POST /search/bae_search, built immutably.
 *
 * Two documented modes:
 *  - {@see byDeliveryAddresses()} – look up known addresses only (recipientEdasOnly = true);
 *  - {@see inCategories()} – search by holder data within search categories (rule R.SEAPI.01).
 *
 * The SDK checks the contract-level constraints (lengths, counts, page size, mode exclusivity).
 * Category-specific rules – which attribute subsets are required or forbidden – are enforced by the
 * server and reported with SEAPI-00003…00008; they are not duplicated here because they change
 * independently of the contract.
 */
final class AdvancedSearch
{
    public const MAX_DELIVERY_ADDRESSES = 50;
    public const MAX_OFFICIAL_IDS = 4;
    public const MAX_LIMIT = 50;
    public const DEFAULT_LIMIT = 20;

    /** @var list<SearchCategory> */
    private array $categories = [];
    /** @var list<string> */
    private array $deliveryAddresses = [];
    private ?string $name = null;
    private ?string $surname = null;
    private ?string $entityName = null;
    /** @var list<array{OfficialIdType, string}> */
    private array $officialIds = [];
    /** @var list<PostalAddress> */
    private array $postalAddresses = [];
    private ?bool $searchByParentIds = null;
    private int $offset = 0;
    private int $limit = self::DEFAULT_LIMIT;

    private function __construct(private readonly bool $addressesOnly) {}

    /**
     * Look up the given addresses (ADE) without any other criteria.
     */
    public static function byDeliveryAddresses(string $address, string ...$moreAddresses): self
    {
        $search = new self(true);
        $search->deliveryAddresses = self::assertAddresses([$address, ...array_values($moreAddresses)]);

        return $search;
    }

    /**
     * Search by holder data. Only PUBLIC_INSTITUTION, COMPANY and ORGANISATION can be combined (server rule).
     */
    public static function inCategories(SearchCategory $category, SearchCategory ...$moreCategories): self
    {
        $search = new self(false);
        $search->categories = array_values(array_unique([$category, ...array_values($moreCategories)], \SORT_REGULAR));

        return $search;
    }

    public function withName(string $name): self
    {
        Length::assert('name', $name, 2, 240);

        return $this->with(static function (self $s) use ($name): void {
            $s->name = $name;
        });
    }

    public function withSurname(string $surname): self
    {
        Length::assert('surname', $surname, 2, 300);

        return $this->with(static function (self $s) use ($surname): void {
            $s->surname = $surname;
        });
    }

    public function withEntityName(string $entityName): self
    {
        Length::assert('entityName', $entityName, 2, 2000);

        return $this->with(static function (self $s) use ($entityName): void {
            $s->entityName = $entityName;
        });
    }

    public function withOfficialId(OfficialIdType $type, string $id): self
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Official identifier must not be empty.');
        }
        if (\count($this->officialIds) >= self::MAX_OFFICIAL_IDS) {
            throw new InvalidArgumentException(\sprintf('At most %d official identifiers are allowed.', self::MAX_OFFICIAL_IDS));
        }

        return $this->with(static function (self $s) use ($type, $id): void {
            $s->officialIds[] = [$type, $id];
        });
    }

    /**
     * Recipient address (ADE) as a criterion of data set no. 1 (e.g. subset 1.3 / 1.4).
     */
    public function withDeliveryAddress(string $address): self
    {
        $addresses = self::assertAddresses([...$this->deliveryAddresses, $address]);

        return $this->with(static function (self $s) use ($addresses): void {
            $s->deliveryAddresses = $addresses;
        });
    }

    public function withPostalAddress(PostalAddress $address): self
    {
        return $this->with(static function (self $s) use ($address): void {
            $s->postalAddresses[] = $address;
        });
    }

    /**
     * "searchByParentIds": when true, return only organisational units (e.g. embassies) registered
     * under the REGON/KRS/NIP of a public entity, not the main entity itself.
     */
    public function onlySubordinateUnits(bool $enabled = true): self
    {
        return $this->with(static function (self $s) use ($enabled): void {
            $s->searchByParentIds = $enabled;
        });
    }

    /**
     * @param int $offset number of results to skip (>= 0)
     * @param int $limit  page size, 1-50
     */
    public function withPagination(int $offset, int $limit = self::DEFAULT_LIMIT): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('offset must be >= 0.');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(\sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }

        $search = clone $this;
        $search->offset = $offset;
        $search->limit = $limit;

        return $search;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toPayload(string $senderAddress): array
    {
        $payload = [
            'recipientEdasOnly' => $this->addressesOnly,
            'senderEda' => $senderAddress,
        ];

        if ($this->categories !== []) {
            $payload['searchCategory'] = array_map(static fn(SearchCategory $c): string => $c->value, $this->categories);
        }
        if ($this->deliveryAddresses !== []) {
            $payload['recipientEdas'] = $this->deliveryAddresses;
        }

        foreach (['name' => $this->name, 'surname' => $this->surname, 'entityName' => $this->entityName] as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        if ($this->officialIds !== []) {
            $payload['officialIds'] = array_map(
                static fn(array $id): array => ['id' => $id[1], 'referenceRegistry' => $id[0]->value],
                $this->officialIds,
            );
        }
        if ($this->postalAddresses !== []) {
            $payload['address'] = array_map(static fn(PostalAddress $a): array => $a->toPayload(), $this->postalAddresses);
        }
        if ($this->searchByParentIds !== null) {
            $payload['searchByParentIds'] = $this->searchByParentIds;
        }

        $payload['offset'] = $this->offset;
        $payload['limit'] = $this->limit;

        return $payload;
    }

    /**
     * @param \Closure(self): void $mutation
     */
    private function with(\Closure $mutation): self
    {
        if ($this->addressesOnly) {
            // SEAPI-00023: no other criteria are allowed when searching by recipient addresses only.
            throw new InvalidArgumentException('A search created with byDeliveryAddresses() accepts no other criteria; use inCategories() instead.');
        }

        $search = clone $this;
        $mutation($search);

        return $search;
    }

    /**
     * @param list<string> $addresses
     *
     * @return list<string>
     */
    private static function assertAddresses(array $addresses): array
    {
        if (\count($addresses) > self::MAX_DELIVERY_ADDRESSES) {
            throw new InvalidArgumentException(\sprintf('At most %d delivery addresses are allowed.', self::MAX_DELIVERY_ADDRESSES));
        }
        foreach ($addresses as $address) {
            if ($address === '' || preg_match('/\s/u', $address) === 1) {
                throw new InvalidArgumentException('Delivery address must be a non-empty string without whitespace.');
            }
        }

        return $addresses;
    }
}
