# Third-party assets

## qrcode.js

- **Source:** [`qrcode-generator`](https://github.com/kazuhikoarase/qrcode-generator) by Kazuhiko Arase, version 2.0.4, `dist/qrcode.js`
- **License:** MIT (license header preserved at the top of the vendored file)
- **Why vendored, not CDN-loaded:** `pay-server` is meant to be self-hostable (see `doc-elektron/guideline-standalone-payment-server.md`); a checkout page that depends on a third-party CDN to render its own payment QR code would fail closed the moment that CDN is unreachable, or expose buyer IP addresses and order pages visited to a party outside the merchant's and this project's control. The file is used unmodified.
- **Renders client-side:** the checkout page builds the `elek:` payment URI server-side and encodes it into a QR code entirely in the buyer's browser; no order or address data is sent to any third party.
