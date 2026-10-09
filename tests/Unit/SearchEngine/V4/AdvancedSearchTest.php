<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\SearchEngine\V4;

use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\SearchEngine\V4\Enum\AddressType;
use B4x\Edoreczenia\SearchEngine\V4\Enum\OfficialIdType;
use B4x\Edoreczenia\SearchEngine\V4\Enum\SearchCategory;
use B4x\Edoreczenia\SearchEngine\V4\Request\AdvancedSearch;
use B4x\Edoreczenia\SearchEngine\V4\Request\PostalAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdvancedSearchTest extends TestCase
{
    public function testAddressOnlyModePayload(): void
    {
        $payload = AdvancedSearch::byDeliveryAddresses('AE:PL-1', 'AE:PL-2')->withPagination(10, 5)->toPayload('AE:PL-S');

        self::assertSame([
            'recipientEdasOnly' => true,
            'senderEda' => 'AE:PL-S',
            'recipientEdas' => ['AE:PL-1', 'AE:PL-2'],
            'offset' => 10,
            'limit' => 5,
        ], $payload);
    }

    public function testCriteriaModeWithDataSetTwo(): void
    {
        $payload = AdvancedSearch::inCategories(SearchCategory::Individual)
            ->withName('ADAM')
            ->withSurname('MALINOWSKI')
            ->withPostalAddress(new PostalAddress('PL', 'WARSZAWA', street: 'POLNA', buildingNumber: '12AA', flatNumber: '123A', postalCode: '05-569', types: [AddressType::Correspondence]))
            ->toPayload('AE:PL-S');

        self::assertSame([
            'recipientEdasOnly' => false,
            'senderEda' => 'AE:PL-S',
            'searchCategory' => ['INDIVIDUAL'],
            'name' => 'ADAM',
            'surname' => 'MALINOWSKI',
            'address' => [[
                'countryCode' => 'PL',
                'city' => 'WARSZAWA',
                'addressType' => ['correspondence'],
                'street' => 'POLNA',
                'postalCode' => '05-569',
                'buildingNumber' => '12AA',
                'flatNumber' => '123A',
            ]],
            'offset' => 0,
            'limit' => 20,
        ], $payload);
    }

    public function testCombinedCategoriesSubordinateUnitsAndDeduplication(): void
    {
        $payload = AdvancedSearch::inCategories(SearchCategory::PublicInstitution, SearchCategory::Company, SearchCategory::Company)
            ->withEntityName('MINISTERSTWO')
            ->withDeliveryAddress('AE:PL-1')
            ->onlySubordinateUnits()
            ->toPayload('AE:PL-S');

        self::assertSame(['PUBLIC_INSTITUTION', 'COMPANY'], $payload['searchCategory']);
        self::assertSame(['AE:PL-1'], $payload['recipientEdas']);
        self::assertTrue($payload['searchByParentIds']);
    }

    public function testBuilderIsImmutable(): void
    {
        $base = AdvancedSearch::inCategories(SearchCategory::Company);
        $base->withEntityName('ACME')->withPagination(5);

        self::assertArrayNotHasKey('entityName', $base->toPayload('S'));
        self::assertSame(0, $base->toPayload('S')['offset']);
    }

    public function testAddressOnlyModeRejectsOtherCriteria(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdvancedSearch::byDeliveryAddresses('AE:PL-1')->withName('ADAM');
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function invalidCriteria(): iterable
    {
        $base = static fn(): AdvancedSearch => AdvancedSearch::inCategories(SearchCategory::Company);

        yield 'name too short' => [static fn() => $base()->withName('A')];
        yield 'surname too long' => [static fn() => $base()->withSurname(str_repeat('Ż', 301))];
        yield 'entity name too short' => [static fn() => $base()->withEntityName(' X ')];
        yield 'empty official id' => [static fn() => $base()->withOfficialId(OfficialIdType::Nip, ' ')];
        yield 'too many official ids' => [static fn() => $base()->withOfficialId(OfficialIdType::Nip, '1')->withOfficialId(OfficialIdType::Regon, '2')->withOfficialId(OfficialIdType::Krs, '3')->withOfficialId(OfficialIdType::Pesel, '4')->withOfficialId(OfficialIdType::Eu, '5')];
        yield 'limit above 50' => [static fn() => $base()->withPagination(0, 51)];
        yield 'limit zero' => [static fn() => $base()->withPagination(0, 0)];
        yield 'negative offset' => [static fn() => $base()->withPagination(-1)];
        yield 'too many addresses' => [static fn() => AdvancedSearch::byDeliveryAddresses(...array_fill(0, 51, 'AE:PL-1'))];
        yield 'blank address' => [static fn() => AdvancedSearch::byDeliveryAddresses('')];
        yield 'lower-case country' => [static fn() => new PostalAddress('pl', 'WARSZAWA')];
        yield 'empty city' => [static fn() => new PostalAddress('PL', '  ')];
        yield 'street too long' => [static fn() => new PostalAddress('PL', 'WARSZAWA', street: str_repeat('a', 513))];
    }

    #[DataProvider('invalidCriteria')]
    public function testContractConstraintsAreValidatedLocally(\Closure $build): void
    {
        $this->expectException(InvalidArgumentException::class);

        $build();
    }

    public function testLengthsAreCountedInCharactersNotBytes(): void
    {
        $payload = AdvancedSearch::inCategories(SearchCategory::Individual)->withSurname(str_repeat('Ż', 300))->toPayload('S');

        self::assertIsString($payload['surname']);
        self::assertSame(300, mb_strlen($payload['surname']));
    }
}
