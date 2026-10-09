# Changelog

All notable changes are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project uses [Semantic Versioning](https://semver.org/) (during 0.x, a minor release may contain breaking changes).

## [0.1.0] - 2026-10-09

First public pre-release. **Not yet verified against the INT or PROD environments.**

### Added

- User Agent API v3 (contract 3.0.8), read-only and **not yet verified against INT/PROD**:
  `userAgent()->countries()`, `directories()`, `directoriesWithCounts()`, `settings()`,
  `listMessages(MessageQuery)` in `minimal`/`metadata` format.
- `Transport` GET support; mailbox addresses in URL paths are masked as `{masked}` in logs.
- Issue template "INT verification report".
- System-to-system authentication: OIDC `client_credentials` with RFC 7523 signed JWT assertion (RS256),
  token reuse with safety margin, optional PSR-16 token sharing, `StaticTokenProvider`.
- `Environment` with official production and integration endpoints; HTTPS enforced.
- PSR-18 transport with typed error model, opt-in retries for read-only operations, redacted PSR-3 logging.
- Search Engine API v4 (contract 4.0.5): `search()` (`bae_search`), `findByAddresses()` (`eda-search`),
  `getOwnAddressDetails()` (`eda-confirmation`), request builders, response models, enums and error-code catalogue.
- `composer specs:fetch`: hash-verified download of the official contracts; contract test suite.
- README in English and Polish, contribution guide and security policy.
