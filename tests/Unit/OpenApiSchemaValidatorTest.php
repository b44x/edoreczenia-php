<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit;

use B4x\Edoreczenia\Tests\Contract\OpenApiSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Guards the contract-test validator itself, so a broken validator cannot make contract tests pass vacuously.
 */
final class OpenApiSchemaValidatorTest extends TestCase
{
    private const SPEC = [
        'components' => ['schemas' => [
            'Item' => [
                // no "type": object is implied by "properties", as in some official schemas
                'required' => ['id', 'flag'],
                'properties' => [
                    'id' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 4],
                    'flag' => ['type' => 'boolean'],
                    'status' => ['$ref' => '#/components/schemas/Status'],
                    'tags' => ['type' => 'array', 'maxItems' => 1, 'items' => ['type' => 'string']],
                    'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                ],
            ],
            'Status' => ['type' => 'string', 'enum' => ['A', 'B']],
        ]],
    ];

    public function testAcceptsValidDocument(): void
    {
        $validator = new OpenApiSchemaValidator(self::SPEC);

        self::assertSame([], $validator->validate(['id' => 'ab', 'flag' => true, 'status' => 'A', 'tags' => ['x'], 'count' => 3, 'extra' => 1], 'Item'));
    }

    public function testReportsEveryViolation(): void
    {
        $validator = new OpenApiSchemaValidator(self::SPEC);

        $errors = $validator->validate(['id' => 'abcde', 'status' => 'C', 'tags' => ['x', 'y'], 'count' => 9], 'Item');

        self::assertSame([
            '$: missing required "flag"',
            '$.id: longer than maxLength',
            '$.status: value not in enum',
            '$.tags: more than 1 items',
            '$.count: above maximum',
        ], $errors);
        self::assertSame(['$: expected object'], $validator->validate(['a', 'b'], 'Item'));
        self::assertSame(['$.flag: expected boolean'], $validator->validate(['id' => 'ab', 'flag' => 'yes'], 'Item'));
    }
}
