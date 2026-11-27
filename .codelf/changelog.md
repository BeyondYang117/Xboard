# Changelog

## 2026-11-27

- Added the BEpusdt payment plugin with configurable gateway URL, API token, fiat currency, and USDT network selection.
- Added signed order creation and signature-verified success callback handling.
- Added focused payment plugin tests for request signing, amount conversion, callback status, and callback signature validation.
- Fixed BEpusdt network and fiat select options to match the admin payment form's expected `{value, label}` list format.
- Fixed BEpusdt order amount serialization/signing for numeric JSON amounts and surfaced sanitized gateway rejection messages for diagnostics.
