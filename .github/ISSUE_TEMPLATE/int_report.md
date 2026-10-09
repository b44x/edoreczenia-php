---
name: INT verification report
about: You ran the SDK against the e-Doręczenia integration environment (INT) – thank you!
labels: int-verification
---

**Never paste tokens, private keys, assertions, mailbox addresses of natural persons, PESEL numbers or message contents.**
Error codes, HTTP statuses and server event ids are enough.

SDK version / commit:
PHP version and HTTP client:
Date and time of the test (UTC):

### Operations tried

| Operation | Result (OK / HTTP status + error code) | Notes |
|---|---|---|
| token request (`SignedJwtTokenProvider`) | | |
| `searchEngine()->getOwnAddressDetails()` | | |
| `searchEngine()->search(...)` | | |
| `searchEngine()->findByAddresses(...)` | | |
| `userAgent()->countries()` | | |
| `userAgent()->directories()` / `directoriesWithCounts()` | | |
| `userAgent()->settings()` | | |
| `userAgent()->listMessages()` (minimal / metadata) | | |

### Open questions you can answer

- [ ] does `/search/eda-search` work (or only `/search/eda_search`)?
- [ ] is `assignmentDegree` sent as a number or a string?
- [ ] was the token issued with `aud=https://…/auth/realms/EDOR` and `login_hint=ADE.{address}`?
- [ ] does the message list contain `total`, `resultSize` or both?
- [ ] are mailbox addresses accepted unencoded in the path (`/api/v3/AE:PL-…/messages`)?
- [ ] does the `minimal` list format include `messageMetadata.messageId`?

### Anything else that differed from the documentation
