# EP-10 — Meta and WhatsApp Integrations

**Blocked by:** EP-09, customer-provided Meta/WhatsApp credentials, DEC-024.

---

## TE-044 — Meta Lead Ads webhook ingestion

**Goal:** Turn Meta lead-form submissions into routed CRM leads.
**Scope in:** Webhook verification (challenge/signature), event dedupe by event/lead ID, configurable form→field mapping, lead creation, assignment handoff, raw payload log (redacted), retry/failure handling, replay.
**Scope out:** Ad management, audience sync.
**Dependencies:** TE-042, TE-046.
**API contract changes:** `POST /webhooks/meta`, integration settings schema.
**Acceptance criteria:** AC-INT-001..003, AC-INT-006.
**Test cases:** invalid signature, duplicate event, malformed payload, unknown form mapping, Arabic field values, provider retry.
**Owned files:** `backend/app/Modules/Integrations/Meta`, tests; fixtures for payloads.
**Reviewer:** Architect + security.
**Status:** done — `/webhooks/meta`; `MetaLeadAdsTest`, `WebhookTest`.

---

## TE-045 — WhatsApp Business Cloud API

**Goal:** Capture WhatsApp conversations and send replies/templates.
**Scope in:** Inbound webhook (verify, dedupe, persist message, link lead/conversation), outbound text/template send, provider message ID, delivery status, retry with no duplicate send, conversation timeline integration, opt-out check.
**Scope out:** Marketing campaigns, other channels.
**Dependencies:** TE-042, TE-046.
**API contract changes:** `POST /webhooks/whatsapp`, `/whatsapp/messages`.
**Acceptance criteria:** AC-INT-004, AC-INT-005, AC-CRM-009.
**Test cases:** duplicate inbound, unknown number, media messages (store metadata), send failure/retry, template rejection, Arabic text.
**Owned files:** `backend/app/Modules/Integrations/WhatsApp`, tests.
**Reviewer:** Architect.
**Status:** partial — `/webhooks/whatsapp`, messages, consents; `WhatsAppIntegrationTest` runs on fixtures; live acceptance needs customer Meta/WhatsApp credentials.

---

## TE-046 — Integration settings and webhook event log

**Goal:** Secure, observable integration configuration.
**Scope in:** Credential settings (write-only, encrypted), connection test, enable/disable toggles, webhook event log (provider, external ID, status, attempts, redacted payload), replay action, dashboard filters.
**Scope out:** Secret rotation automation.
**Dependencies:** TE-005, TE-006.
**API contract changes:** `/integrations`, `/webhook-events`.
**Acceptance criteria:** AC-INT-006, AC-INT-007; secrets never returned/logged.
**Test cases:** save/redact credentials, failed connection test, replay success/failure, disabled integration response, log retention.
**Owned files:** `backend/app/Modules/Integrations/Settings`, `WebhookEvents`, tests.
**Reviewer:** Security + architect.
**Status:** partial — `/integrations/status` and `/integrations/settings` exist (`IntegrationSettingsApiTest`); `WebhookEvent` model exists but no event-log viewing route was found (UNVERIFIED).
