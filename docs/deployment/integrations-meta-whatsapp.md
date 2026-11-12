# Meta Lead Ads and WhatsApp Cloud API — per-customer setup

Optional integrations. **Both are disabled by default** and the ERP works fully without them
(manual lead entry in the Leads screen). Each customer installation is configured separately;
nothing here is customer-specific code.

> Status of API details: this guide and the adapter code were written **without internet access**.
> Every Meta API shape, permission name, limit and policy below is marked **UNVERIFIED** until
> someone confirms it against the current official documentation (see "Verification checklist").
> Do not treat an integration as production-ready until that checklist is signed off and a live
> end-to-end test with real credentials has passed.

## External dependencies (not controlled by this product)

- **Meta platform review.** Reading Lead Ads data and using the WhatsApp Cloud API in production
  typically needs a Meta developer app, a verified Meta Business portfolio and App Review for the
  permissions below (UNVERIFIED which permissions need Advanced Access). Review time and outcome
  are Meta's.
- **Messaging fees.** WhatsApp conversation/message pricing, free tiers and template categories
  are set by Meta and change over time (UNVERIFIED current model). The customer pays Meta directly.
- **Rate limits and quality limits.** Graph API call limits, WhatsApp messaging tiers and quality
  ratings are enforced by Meta (UNVERIFIED values). The ERP retries rate-limit errors with
  backoff but cannot raise limits.
- **Template approval.** Business-initiated WhatsApp messages need pre-approved templates.
- **Public HTTPS URL.** Meta can only call a publicly reachable HTTPS endpoint with a valid certificate.

## What is and is not provided

| Provided | Not provided / not claimed |
| --- | --- |
| Meta Lead Ads webhook -> Graph API fetch -> CRM lead (source `facebook`, deduplicated, routed by branch and assignment rules) | A WhatsApp chat inbox |
| WhatsApp inbound message log linked to lead/customer by phone | Automatic replies, bots or bulk campaigns |
| Staff-triggered outbound text/template messages with opt-in check, queue, retry/backoff, delivery status | Sending without a recorded opt-in |
| Admin screen (Settings > Integrations) for flags, non-secret ids, webhook URLs, verification status | Entering or viewing secrets in the UI |

If an integration is unavailable, create leads manually in the Leads screen. (A CSV lead import is
a separate, currently unimplemented feature.)

## Configuration model

- **Secrets: environment variables only** (never stored in the database, never returned by the API,
  never committed). Set in the server `.env` / Coolify secrets:

  | Variable | Purpose |
  | --- | --- |
  | `META_APP_SECRET` | HMAC key to verify `X-Hub-Signature-256` on Lead Ads webhooks |
  | `META_VERIFY_TOKEN` | Token you choose; echoed during webhook verification |
  | `META_ACCESS_TOKEN` | Page/system-user token used to fetch lead details |
  | `WHATSAPP_APP_SECRET` | HMAC key for WhatsApp webhooks (same Meta app secret if one app is used) |
  | `WHATSAPP_VERIFY_TOKEN` | Token you choose for the WhatsApp webhook |
  | `WHATSAPP_ACCESS_TOKEN` | System-user token used to send messages |
  | `META_GRAPH_VERSION` (optional) | Default Graph API version, e.g. `v21.0` (UNVERIFIED: confirm current) |

  `META_APP_ID` and `WHATSAPP_PHONE_NUMBER_ID` remain supported as env fallbacks.
  Use long random values for the verify tokens (`openssl rand -hex 24`). Prefer a long-lived
  **system user** token over a personal token (UNVERIFIED token options).
- **Non-secret settings** (Settings > Integrations, permission `settings.update`, audited):
  enable flags, Meta page id, default branch, form-to-branch map, WhatsApp phone number id,
  business account id, Graph version, opt-out keywords.
- In production (`APP_ENV` other than `local`/`testing`) a webhook with a missing app secret is
  refused with 503; with a secret set, an invalid or missing signature is refused with 403.
- A disabled integration answers its webhook URLs with 404 and stores nothing.

## Setup steps (per customer)

1. **Prepare the server.** Public HTTPS domain, queue worker running (`php artisan queue:work`, already
   in the compose files) and the scheduler running (the `integrations:dispatch-outbox` command is
   scheduled every minute). Jobs use the default queue.
2. **Create the Meta app** in the customer's Meta Business account (or the agency's, per contract).
   Add the Webhooks product; add WhatsApp for the Cloud API (UNVERIFIED product names).
3. **Generate secrets** and put them in the server environment (table above). Redeploy/restart so
   `php artisan config:cache` picks them up. Never paste them into chat, tickets or the UI.
4. **Enable in the ERP.** Settings > Integrations: set the ids, choose the default branch and
   switch the integration on. Save. (Verification returns 404 while disabled, so enable first.)
5. **Register the webhooks in the Meta app** using the URLs shown on the screen:
   - Meta Lead Ads: `https://<host>/api/v1/webhooks/meta`, verify token = `META_VERIFY_TOKEN`,
     subscribe the Page to the `leadgen` field.
   - WhatsApp: `https://<host>/api/v1/webhooks/whatsapp`, verify token = `WHATSAPP_VERIFY_TOKEN`,
     subscribe to `messages`.
   The screen shows "Webhook verified" once Meta's GET handshake succeeded.
6. **Assignment rules.** Create assignment rules (CRM > rules) for source `facebook` / branch so
   new leads are routed to owners. The branch comes from the form map or the default branch;
   the owner from the existing rules (ADR-0012).
7. **WhatsApp opt-in.** Record consent per contact (`POST /api/v1/integrations/whatsapp/consents`)
   from a documented source (lead form wording, written, in person...). Without a recorded
   opt-in, or after an opt-out (including an inbound keyword such as STOP), sending is refused.
   `leads.consent` alone is **not** a WhatsApp opt-in.
8. **Smoke test** with the test tools in Meta's dashboard: Lead Ads testing tool for a lead, and a
   test number for WhatsApp. Check Settings > Integrations counters and the lead's activity log.

## Behaviour reference

- **Idempotency.** Webhook events are unique per `(provider, event id)`: Meta leads per `leadgen_id`,
  WhatsApp per payload hash and per `wamid`. Leads are unique per `(source, external id)`. A new
  submission with an existing phone/email adds a note to the existing lead instead of a duplicate.
  Phone matching compares the last 9 digits and ignores formatting.
- **Retry.** Graph calls run in queued jobs (5 tries, backoff 30s, 2m, 10m, 30m). Timeouts, HTTP
  429/5xx and listed rate-limit codes are transient; other 4xx are permanent and mark the event or
  message `failed` (visible on the Integrations screen). A provider re-delivery of a failed Meta
  event re-queues it. A scheduled sweep re-queues lost jobs.
- **Outbox.** An outbound message and its `outbox_events` row are written in one transaction; a
  dispatcher publishes it to the queue (at-least-once). Sending claims the row first
  (`queued -> sending`), so duplicate jobs do not send twice. A message stuck in `sending`
  after a worker crash is **not** resent automatically (it could double-send); review it manually.
- **24-hour window.** Free-form text is only accepted within 24h of the contact's last inbound
  message (UNVERIFIED rule); otherwise use an approved template.
- **Status updates.** `sent`, `delivered`, `read`, `failed` webhooks update the message log and
  never move a message backwards.
- **PII.** Stored webhook payloads are redacted (phones, names, emails, message text); application
  logs never include phone numbers, message bodies or tokens. Message text is kept in
  `whatsapp_messages` and lead notes; include them in your data-retention policy.
- **Operations.** `GET /api/v1/integrations/status` (Settings screen) shows enable flags, configured
  booleans, last event, failed counts. Failed jobs also land in `failed_jobs`.

## Verification checklist (UNVERIFIED items to confirm against current official Meta docs)

Mark each with date and reviewer before go-live.

- [ ] Webhook verification handshake (`hub.mode`, `hub.verify_token`, `hub.challenge`) and signature header `X-Hub-Signature-256` = `sha256=` + HMAC-SHA256(raw body, app secret).
- [ ] Lead Ads `leadgen` webhook payload shape (`entry[].changes[].value.leadgen_id|page_id|form_id|ad_id`).
- [ ] Lead retrieval request (`GET /{leadgen_id}?fields=id,created_time,ad_id,form_id,field_data`), response `field_data[].name/values`, and bearer-header authentication.
- [ ] Required permissions/scopes and App Review / access level for Lead Ads retrieval and page subscription.
- [ ] WhatsApp send endpoint (`POST /{phone-number-id}/messages`), text and template body shapes, response `messages[0].id`.
- [ ] WhatsApp webhook shapes for inbound `messages[]` and `statuses[]` including `errors[]`.
- [ ] 24-hour customer service window rule, template categories, opt-in requirements and policy wording.
- [ ] Transient error codes (code list in `GraphApiClient::TRANSIENT_CODES`) and rate-limit / `Retry-After` behaviour.
- [ ] Current supported Graph API version and deprecation schedule.
- [ ] Pricing model, messaging tiers and quality rating limits for the customer's region.
- [ ] Data-protection obligations (consent records, retention, deletion requests) with the customer's legal adviser.

## Rollback and disabling

Switch the integration off in Settings (or remove the webhook subscription in Meta). Webhooks then
answer 404 and no new data is ingested; existing leads, messages and consent records are kept.
The migration `2026_11_22_000100_create_whatsapp_integration_tables` is additive and reversible
(`php artisan migrate:rollback --step=1` drops `whatsapp_messages`, `messaging_consents` and the two
diagnostic columns on `webhook_events`; rolling back deletes the message log and consent records,
so export them first).
