# Naise payment integration

Naise is a merchant checkout application; it does not hold customer funds or become a payment network. Live QR payments must be collected by a bank or registered merchant acquirer. Do not route customer money through a personal QR when the provider requires a business merchant account.

## Current implementation

- The existing merchant QR flow is static DuitNow QR with customer receipt upload and staff verification.
- `POST /api/payments/webhook` is a normalized internal callback contract for testing and a future provider adapter. It is not connected to Touch 'n Go, PayNet, Public Bank, or another acquirer yet.
- A callback must be signed with HMAC-SHA256 over `<unix-timestamp>.<raw-json-body>` using `NAISE_PAYMENT_WEBHOOK_SECRET`, sent in `X-Naise-Timestamp` and `X-Naise-Signature`.
- The callback timestamp is limited by `NAISE_PAYMENT_WEBHOOK_TOLERANCE_SECONDS` (default five minutes). Each provider event ID is recorded once. Reused event IDs with a different body, duplicate payment references, wrong order amounts, and non-MYR payments are rejected.
- Accepted payload shape: `event_id`, `event_type` (`payment.succeeded`), `order_reference`, `payment_reference`, `amount`, and `currency` (`MYR`).

## What remains before live automatic confirmation

An acquirer must provide an approved merchant account and sandbox credentials, API documentation, webhook signature rules, and a production callback URL. Implement a provider-specific adapter that verifies the provider's signature on the original raw request, maps its verified fields to the normalized event contract, and handles provider retries. Never accept a browser redirect, uploaded receipt, QR scan, or client-provided “paid” flag as payment proof. Do not copy an acquirer's secret into frontend code or commit it to Git.

Before production, test successful, failed, expired, duplicated, delayed, wrong-amount, wrong-currency, and replayed callbacks in the provider sandbox. Only a verified success callback can set an order to paid and award loyalty points.
