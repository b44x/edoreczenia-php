<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Contract;

/**
 * Validates decoded JSON against the subset of OpenAPI 3.0 schema keywords used by the
 * e-Doręczenia contracts: $ref, type, required, properties, items, enum, min/maxLength,
 * min/maxItems, minimum/maximum. Unknown keywords are ignored.
 */
final class OpenApiSchemaValidator
{
    /**
     * @param array<mixed> $spec parsed OpenAPI document
     */
    public function __construct(private readonly array $spec) {}

    /**
     * @return list<string> violations; empty when valid
     */
    public function validate(mixed $value, string $schemaName): array
    {
        $errors = [];
        $this->check($value, $this->resolve(['$ref' => '#/components/schemas/' . $schemaName]), '$', $errors);

        return $errors;
    }

    /**
     * @param array<mixed> $schema
     * @param list<string> $errors
     */
    private function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        $schema = $this->resolve($schema);
        // Some contract schemas (e.g. EdaSearchResponse) declare "properties" without "type: object".
        $type = $schema['type'] ?? (isset($schema['properties']) || isset($schema['required']) ? 'object' : null);

        if (isset($schema['enum']) && \is_array($schema['enum'])) {
            $allowed = array_map(static fn(mixed $v): string => \is_scalar($v) ? (string) $v : '', $schema['enum']);
            if (!\is_scalar($value) || !\in_array((string) $value, $allowed, true)) {
                $errors[] = \sprintf('%s: value not in enum', $path);
            }
        }

        switch ($type) {
            case 'object':
                if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
                    $errors[] = \sprintf('%s: expected object', $path);

                    return;
                }
                foreach ((array) ($schema['required'] ?? []) as $required) {
                    if (\is_string($required) && !\array_key_exists($required, $value)) {
                        $errors[] = \sprintf('%s: missing required "%s"', $path, $required);
                    }
                }
                foreach ((array) ($schema['properties'] ?? []) as $name => $propertySchema) {
                    if (\array_key_exists($name, $value) && \is_array($propertySchema)) {
                        $this->check($value[$name], $propertySchema, $path . '.' . $name, $errors);
                    }
                }

                return;

            case 'array':
                if (!\is_array($value) || !array_is_list($value)) {
                    $errors[] = \sprintf('%s: expected array', $path);

                    return;
                }
                $minItems = $schema['minItems'] ?? null;
                $maxItems = $schema['maxItems'] ?? null;
                if (\is_int($minItems) && \count($value) < $minItems) {
                    $errors[] = \sprintf('%s: fewer than %d items', $path, $minItems);
                }
                if (\is_int($maxItems) && \count($value) > $maxItems) {
                    $errors[] = \sprintf('%s: more than %d items', $path, $maxItems);
                }
                foreach ($value as $i => $item) {
                    if (\is_array($schema['items'] ?? null)) {
                        $this->check($item, $schema['items'], \sprintf('%s[%d]', $path, $i), $errors);
                    }
                }

                return;

            case 'string':
                if (!\is_string($value)) {
                    // The contracts declare some numeric enums and codes as strings; their examples use numbers.
                    if (!(isset($schema['enum']) || \is_int($value))) {
                        $errors[] = \sprintf('%s: expected string', $path);
                    }

                    return;
                }
                $length = mb_strlen($value);
                if (isset($schema['minLength']) && $length < $schema['minLength']) {
                    $errors[] = \sprintf('%s: shorter than minLength', $path);
                }
                if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                    $errors[] = \sprintf('%s: longer than maxLength', $path);
                }

                return;

            case 'integer':
                if (!\is_int($value)) {
                    $errors[] = \sprintf('%s: expected integer', $path);

                    return;
                }
                if (isset($schema['minimum']) && $value < $schema['minimum']) {
                    $errors[] = \sprintf('%s: below minimum', $path);
                }
                if (isset($schema['maximum']) && $value > $schema['maximum']) {
                    $errors[] = \sprintf('%s: above maximum', $path);
                }

                return;

            case 'number':
                if (!\is_int($value) && !\is_float($value)) {
                    $errors[] = \sprintf('%s: expected number', $path);
                }

                return;

            case 'boolean':
                if (!\is_bool($value)) {
                    $errors[] = \sprintf('%s: expected boolean', $path);
                }

                return;
        }
    }

    /**
     * @param array<mixed> $schema
     *
     * @return array<mixed>
     */
    private function resolve(array $schema): array
    {
        while (isset($schema['$ref']) && \is_string($schema['$ref'])) {
            $node = $this->spec;
            foreach (explode('/', substr($schema['$ref'], 2)) as $segment) {
                $next = $node[$segment] ?? null;
                if (!\is_array($next)) {
                    throw new \RuntimeException('Unresolvable $ref ' . $schema['$ref']);
                }
                $node = $next;
            }
            $schema = $node;
        }

        return $schema;
    }
}
