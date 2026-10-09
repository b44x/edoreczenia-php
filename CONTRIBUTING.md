# Contributing

Thanks for helping! This SDK touches legally significant government infrastructure, so contributions are held to a few
strict rules.

## Ground rules

1. **No invented behaviour.** Every endpoint, field, header or rule must be traceable to an official document (contract YAML,
   technical design, integration instruction) or to an observation on INT/PROD. Name the source in the pull request.
2. **No real data.** Never commit real PESEL/NIP numbers of persons, real mailbox contents, tokens, keys, or captured
   responses from production. Fixtures are synthetic (see `tests/Fixtures/README.md`).
3. **No automatic retries of side effects.** Operations that send, read (UA API), delete or modify must pass `safeToRetry: false`.
4. **No secrets in logs or exceptions.** Add a test when you touch logging or error messages.
5. **Discuss architectural changes in an issue first.**

## Development

```bash
composer install
composer check          # composer validate, php-cs-fixer, phpstan (level max), unit tests
composer specs:fetch    # optional: official contracts into build/specs/
composer test:contract
```

Integration tests run only against INT and only with `EDORECZENIA_INT_*` variables set (see
`tests/Integration/ReadOnlyIntegrationTest.php`). Never point tests at production.

## Pull requests

- One topic per PR; update `CHANGELOG.md` under *Unreleased*.
- New public API needs PHPDoc, tests (success, HTTP error, malformed response) and a README mention when user-facing.
- CI must be green on PHP 8.3–8.5 with lowest and highest dependencies.

## When the publisher changes a specification

`composer specs:fetch` fails with "CHANGED". Then: download and diff the document, update models/tests, update the pinned hashes
in `tools/fetch-specs.php`, and add a CHANGELOG entry.

## Releases

Maintainers tag `vX.Y.Z` from `main` after moving *Unreleased* entries to a dated section. Packagist picks up tags automatically.
