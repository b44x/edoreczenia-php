# Changelog

All notable changes are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project uses [Semantic Versioning](https://semver.org/) (during 0.x, a minor release may contain breaking changes).

## [Unreleased]

## [0.2.0] - 2026-10-10

Read-only User Agent API v3 completed. **Still not verified against the INT or PROD environments.**

### Added

- UA API v3 threads (read-only, not yet verified against INT/PROD): `userAgent()->listThreads(ThreadQuery)` and
  `userAgent()->getThread($threadId, ThreadMessageQuery)`; listing a thread does not open its messages (UC045).
- UA API v3 evidences (read-only, not yet verified against INT/PROD): `messageEvidences()`, `mailboxEvidences()`,
  `evidence()`, and streamed downloads `downloadEvidence()`, `downloadHybridAcknowledgement()`,
  `downloadTechnicalEvidences()` returning a `Download` (PSR-7 stream, sanitised file name, `saveTo()`).
- `EvidenceType` with normalisation of both contract spellings (`E.1`/`E1`); `EvidenceService`; `EvidenceQuery`.
- `examples/user-agent.php`: read-only mailbox tour (folders, unread messages, evidences).
- CI runs on `dev/**` branches, tags and on manual dispatch.

### Changed

- Package description mentions the read-only User Agent API v3.

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

[Unreleased]: https://github.com/b44x/edoreczenia-php/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/b44x/edoreczenia-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/b44x/edoreczenia-php/releases/tag/v0.1.0
