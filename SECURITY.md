# Security policy

## Supported versions

Only the latest `0.x` minor release receives fixes until 1.0.

## Reporting a vulnerability

Please **do not open a public issue**. Use GitHub's private vulnerability reporting
("Security" → "Report a vulnerability") on https://github.com/b44x/edoreczenia-php.

Include the affected version, a description, and a minimal reproduction **without** real keys, tokens or personal data.
You can expect an acknowledgement within 7 days.

## Scope

In scope: the SDK code (authentication, key/token handling, logging redaction, transport, parsing).
Out of scope: vulnerabilities in e-Doręczenia services themselves — report those to the operators
(COI service desk "ITSM Atmosfera", https://pomoc.coi.gov.pl, or the designated operator).

## Security model in short

The SDK guarantees that it:

- implements only the documented system authentication (signed JWT, RS256, RSA >= 2048 bits);
- never logs or puts into exception messages: tokens, assertions, keys, headers, query strings or bodies;
- accepts only HTTPS endpoints (plain HTTP only for loopback mock servers) and never touches TLS settings;
- never retries operations with side effects automatically;
- streams binary files and never trusts server-supplied file paths.

The integrating application is responsible for key storage and rotation, HTTP client TLS and timeouts, logger
configuration and retention, separation of INT and PROD configuration, and lawful processing of personal data.
