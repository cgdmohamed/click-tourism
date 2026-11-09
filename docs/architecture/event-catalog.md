# Event Catalog — Tourism ERP

Status: Draft. Events are past-tense, carry IDs and immutable snapshot data, and are delivered at-least-once via the outbox (ADR-0009). Consumers must be idempotent.

## 1. Commercial events

| Event | Producer | Payload (minimum) | Consumers |
|---|---|---|---|
| `BookingConfirmed` | Bookings | `bookingId`, `branchId`, `customerId`, `currency`, `fxRate`, `totalsMinor`, `confirmedAt`, `actorId` | Accounting (revenue/cost per DEC-001/002), Commissions (accrual per DEC-004), CRM (conversion link) |
| `BookingCompleted` | Bookings | `bookingId`, `completedAt`, `items[]` | Accounting (revenue recognition), Commissions (trigger if configured) |
| `BookingCancelled` | Bookings | `bookingId`, `reason`, `feeMinor`, `refundableMinor`, `cancelledAt` | Accounting (reverse unearned/fee), Payments (refund eligibility), Commissions (void/adjust) |
| `BookingAdjusted` | Bookings | `bookingId`, `changes`, `actorId` | Accounting (adjustment document), Reporting |
| `PaymentRecorded` | Payments | `paymentId`, `bookingId`, `amountMinor`, `currency`, `fxRate`, `allocations[]` | Accounting (Dr cash / Cr AR or advance), Reporting, CRM (collection KPI) |
| `RefundIssued` | Payments | `refundId`, `paymentId`, `bookingId`, `amountMinor`, `currency` | Accounting (refund posting), Reporting, Commissions |
| `SupplierObligationCreated` | Payments | `obligationId`, `supplierId`, `bookingItemId`, `amountMinor`, `currency` | Accounting (Dr cost / Cr AP) |
| `SupplierPaid` | Payments | `paymentId`, `obligationId`, `amountMinor`, `currency` | Accounting, Reporting |
| `TransferCompleted` | Payments | `transferId`, `fromAccountId`, `toAccountId`, `amountMinor`, `fxGainLossMinor` | Accounting, Reporting |
| `CommissionAccrued` | Commissions | `accrualId`, `bookingId`, `payee`, `amountMinor` | Accounting (Dr expense / Cr payable), Reporting |
| `CommissionPaid` | Commissions | `accrualIds[]`, `amountMinor`, `cashAccountId` | Accounting, Reporting |

## 2. CRM / integration events

| Event | Producer | Payload (minimum) | Consumers |
|---|---|---|---|
| `LeadCreated` | CRM | `leadId`, `sourceId`, `branchId`, `externalId` | Assignment, Notifications |
| `LeadAssigned` | CRM | `leadId`, `ownerUserId`, `ruleId`, `assignedAt` | Notifications, Reporting |
| `LeadReassigned` | CRM | `leadId`, `fromUserId`, `toUserId`, `reason`, `inactivityHours` | Notifications, Audit |
| `LeadConverted` | CRM | `leadId`, `customerId`, `bookingId?` | Parties, Bookings, Reporting |
| `LeadIngested` | Integrations | `webhookEventId`, `provider`, `externalId`, `leadId` | CRM |
| `MessageReceived` | Integrations | `messageId`, `provider`, `from`, `body`, `leadId?` | CRM timeline, Notifications |
| `MessageSent` | Integrations | `messageId`, `providerMessageId`, `to`, `status` | CRM timeline |

## 3. Platform events

| Event | Producer | Consumers |
|---|---|---|
| `UserCreated` / `UserDeactivated` | Core | Audit, Notifications |
| `BranchUpdated` | Core | Audit, cache invalidation |
| `SettingChanged` | Core | Audit, feature flag cache |
| `PeriodClosed` / `PeriodReopened` | Accounting | Reporting, Audit |
| `JournalPosted` / `JournalReversed` | Accounting | Audit, Reporting |
| `ImportCompleted` | DataTransfer | Notifications, Audit |
| `ExportCompleted` | DataTransfer | Notifications |

## 4. Delivery rules

- Each event has `event_id` (ULID), `type`, `version`, `occurred_at`, `actor_id`, `payload`.
- Outbox row is written in the same transaction as the business change; a dispatcher publishes after commit.
- Consumer dedupe key: `(event_id, consumer)`.
- Retries: exponential backoff, max 5 attempts, then failed-job dashboard + alert.
- Payload changes require a version bump; consumers must ignore unknown fields.
- No secrets, credentials, or full PII in payloads; use IDs and redacted snapshots.
