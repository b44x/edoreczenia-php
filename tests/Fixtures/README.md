# Test fixtures

All files here are **synthetic**. They were written by hand from the `example:` values and
schemas of the official contracts:

- `search-engine-v4/` – SE API v4 (`se-api_v.4.0.5.yaml`, embedded in "Projekt techniczny SE API V4",
  v1.08) and the error table of that document (section 4.2);
- `user-agent-v3/` – UA API v3 (`uaapi_3.0.8.2.yaml`, contract version 3.0.8) and the status values of
  rule R9 of the UA API technical design 5.27.

They were **not captured from the real e-Doręczenia API** and contain no real personal data.
Identifiers (ADE, NIP) are the placeholder values used in the official examples.

`tests/Contract` validates every fixture against the official schema whenever the
specification has been fetched with `composer specs:fetch`.
