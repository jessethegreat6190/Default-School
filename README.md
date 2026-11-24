# SchoolGate / Default School Management System

A lean PHP/MySQL school management system built for XAMPP, designed for pre-primary/primary schools and white-label customization per school.

> **Tagline:** One system. Every child, accounted for.

## What’s included (current progress)

- **Foundation**: front controller routing, session auth, CSRF protection, RBAC, audit logging, DB bootstrap/migrations runner.
- **White-label branding**: school identity in config/DB, core vs per-school CSS/JS/assets separation (`resources/css|js/core`, `resources/css|js/themes/{school_code}`, `public/schools/{school_code}`).
- **Academic setup**: years, terms, classes, streams, subjects.
- **Admissions & enrollment**: applications, approval, enrollment creates student + fee account + guardian links.
- **Student registry**: student profiles, guardians, authorized pickups, search/list.
- **Attendance & timetable**: class register, present/absent/late/excused, timetable slots.
- **Safe Pickup**: authorized collector checkout, unknown-person block with OTP override, pickup logs/audit, guardian notifications.
- **Fees & Finance**: fee structures, invoices/items, receipts/payments, expenses, dashboard fee metrics.
- **Reconciliation**: SchoolPay/mobile-money/bank CSV statement import, auto-match, duplicate detection, unmatched review queue.
- **Academics**: assessments, marks entry, grading/divisions, marklist/report preview, printable report card.
- **Communication**: announcements, notification queue, auto hooks for pickup/absence/fees.
- **Notification providers**: log, webhook (SMS/WhatsApp gateway), email via PHP `mail()`; configurable in Integrations.
- **Payment webhooks**: token-protected inbound webhook endpoint for posting payments and updating invoices/fee accounts.
- **Parent portal**: linked parent accounts view children attendance, fees, marks, pickups, announcements.
- **Reports**: admissions funnel, attendance/finance/pickup summaries, parent account linking.
- **Transport**: routes, vehicles, stops, student assignments.

## Tech stack

- PHP 8+ (tested on XAMPP/PHP 8.2)
- MySQL/MariaDB
- Plain PHP + PDO, no Composer required for current MVP

## Local setup (XAMPP)

1. Place project at `C:\xampp\htdocs\schoolgate` (or your XAMPP htdocs).
2. Start **Apache** and **MySQL** in XAMPP.
3. Ensure DB config in `config/app.php` matches your local MySQL (default: host `127.0.0.1`, db `schoolgate`, user `root`, empty password).
4. Open setup/migration runner: `http://localhost/schoolgate/public/setup`
5. Login: `http://localhost/schoolgate/public/login`

## Default local config

See `config/app.php` for app/school/database settings. For production, move secrets to env/config outside web root and use HTTPS.

## Customization per school

- **Identity/config**: `config/app.php` and `schools` table.
- **Logos/assets**: `public/schools/{school_code}/`.
- **Core CSS/JS**: `resources/css/core/`, `resources/js/core/` (served via `public/assets/...`).
- **School theme overrides**: `resources/css/themes/{school_code}/theme.css`, `resources/js/themes/{school_code}/theme.js`.
- Load order: **core -> school theme**. Keep business rules server-side.

## Integrations

### Notification providers
Configure at `/settings/integrations`:
- `log` - writes to `storage/logs/notifications.log`
- `webhook` - POSTs to your SMS/WhatsApp/email gateway
- `mail` - PHP `mail()` for email

Outbound webhook payload:
```json
{
  "channel": "sms|whatsapp|email",
  "to": "+256...",
  "message": "...",
  "meta": {"category": "...", "title": "..."},
  "ts": 1710000000
}
```
Optional header: `X-SchoolGate-Signature: hmac_sha256(body,secret)`.

### Payment gateway webhook (inbound)
`POST /api/webhooks/payments?provider=schoolpay&token=YOUR_TOKEN`

Payload example:
```json
{
  "provider_reference": "TX123",
  "student_no": "STU000001",
  "invoice_no": "INV-...",
  "amount": 50000,
  "currency": "UGX",
  "paid_at": "2026-11-24 10:00:00"
}
```
Behavior: token auth, dedupe by provider reference, auto-match student/invoice, post payment, update invoice/fee account, log to `webhook_events`.

## Security / production notes

This is an MVP foundation. Before production:
- Use HTTPS, strong secrets, secure session config, and proper host hardening.
- Move DB credentials/secrets out of committed config.
- Add rate limiting, webhook signature/IP controls, retries/queues for notifications.
- Add full validation, pagination, edit/detail flows, permission management UI.
- Add backups, audit review UI, tests, and monitoring.

## Roadmap / remaining work

- Real provider adapters (Africa's Talking/Twilio/Meta WhatsApp) + retry queue.
- Webhook hardening: signatures, replay protection, refunds/reversals, admin review.
- Printable report card refinements, CBC/grading config UI, approval workflows.
- Edit/detail pages, pagination, stronger validation.
- Bank books, instalments, previous balance carry-forward.
- Transport operations (trip logs, boarding), emergency broadcast delivery.
- Full permission/admin UI, tests, deployment hardening.
