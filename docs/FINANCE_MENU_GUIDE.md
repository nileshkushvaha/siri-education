# Finance Menu Guide

This guide explains the Finance area from a client and operations perspective. The application keeps the detailed financial records required by the SRS, but the sidebar exposes only the pages needed to start the main workflows. Supporting records remain available from **Related Records** menus and **Finance Settings**.

## What the Client Actually Needs to Manage Regularly

| Sidebar page | When to use it | Main outcome |
|---|---|---|
| **Payments** | Review incoming student lesson payments and investigate a reported payment | Confirms what the student paid and links to invoices or reconciliation queues |
| **Student Lesson Prices** | Set or update the price students pay for a country, currency, subject, level, and duration | Controls the price resolved before a paid booking is created |
| **Wallets** | Review a student's balance, ledger, refunds, or approved adjustments | Shows the auditable source of every wallet balance change |
| **Compensation Agreements** | Create and maintain the agreed instructor pay terms | Determines the independent rate used to calculate instructor earnings |
| **Instructor Earnings** | Review earnings produced from eligible completed lessons | Shows amounts held, released, disputed, reversed, or ready for settlement |
| **Withdrawal Requests** | Review and process instructor requests to withdraw available earnings | Controls the operational payout workflow |
| **Finance Settings** | Configure collection, invoicing, earnings, and payout rules during setup or an approved change | Provides one controlled entry point for all finance configuration |

Student lesson prices and instructor compensation are deliberately independent. Changing a student price never recalculates an instructor's agreement or historical earning.

## End-to-End Finance Flow

1. Configure currencies, student lesson prices, payment gateways, and payment defaults.
2. A student creates a paid booking or purchases an eligible package.
3. The payment provider creates and verifies a payment record.
4. The system generates the applicable invoice and confirms the funded activity only after trusted payment evidence.
5. A completed eligible lesson is resolved against the instructor's effective compensation agreement.
6. The system creates an immutable instructor earning and applies the configured hold/release rules.
7. Released earnings are either placed in a settlement batch or reserved for a withdrawal; the same earning cannot be used by both.
8. An approved withdrawal uses a verified payout method and creates provider attempts.
9. Reconciliation queues surface uncertain or mismatched provider outcomes for an authorized operator to investigate.

## Sidebar and Related Pages

### Payments

- **Purpose:** Read-only record of student lesson payment attempts and verified outcomes.
- **Managed by:** System; Admin reviews it.
- **Prerequisites:** A booking and payment-provider attempt.
- **Affects:** Booking payment status and the evidence used by downstream invoice and booking flows.
- **Student impact:** Students see their own checkout/payment result, not this admin ledger.
- **Related Records:** Lesson Payment Reconciliation, Package Payment Discrepancies, and Invoices.

### Payment Reconciliation

- **Purpose:** Investigate uncertain or conflicting provider evidence for lesson payments.
- **Managed by:** System creates issues; an authorized Admin investigates and resolves the issue record.
- **When to use:** Only when an open issue exists or support reports a payment mismatch.
- **Safety:** Resolving an issue does not manually mark a booking as paid.
- **Location:** **Payments → Related Records → Lesson Payment Reconciliation**.

Package purchases use a separate generic payable architecture. Their queue is shown as **Package Payment Discrepancies** under the same Related Records menu. The queues look similar but protect different payment lifecycles and must not be merged.

### Invoices

- **Purpose:** Read-only invoices generated after successful lesson payments or wallet recharges.
- **Managed by:** System.
- **When to use:** Customer support, accounting review, or invoice lookup.
- **Prerequisites:** A successful supported payment event.
- **Location:** **Payments → Related Records → Invoices**.

### Student Lesson Prices

- **Purpose:** Define student-facing paid lesson prices.
- **Managed by:** Admin.
- **Prerequisites:** Paid booking type, subject, optional academic level, country, currency, and lesson duration.
- **Affects:** The price snapshotted onto a new booking. Existing booking prices are not rewritten.
- **Instructor impact:** None; instructor compensation is configured separately.

### Wallets

- **Purpose:** Review student balances and their immutable ledger entries.
- **Managed by:** System and service-backed Admin actions; balances are never edited as raw numbers.
- **Affects:** Wallet-funded bookings, credits, approved adjustments, and wallet-first refunds.
- **Student impact:** Students see their own available balance and transactions.

### Compensation Agreements

- **Purpose:** Record effective-dated instructor pay terms.
- **Managed by:** Admin.
- **Prerequisites:** Instructor, currency, pay basis, amount, timezone, effective date, and internal reason.
- **Affects:** Future eligible earnings resolved for the agreement period; historical values remain immutable.
- **Instructor impact:** Drives their earnings, while internal decision notes remain admin-only.
- **Related Records:** Compensation Exceptions and Instructor Earnings Rules.

### Compensation Exceptions

- **Purpose:** Recovery queue for eligible lessons whose compensation could not be resolved, such as a missing agreement.
- **Managed by:** System creates and retries issues; Admin fixes the underlying configuration and may retry.
- **Location:** **Compensation Agreements → Related Records** or **Instructor Earnings → Related Records**.

### Instructor Earnings

- **Purpose:** Immutable ledger of instructor earnings created from eligible completed lessons or approved periodic accruals.
- **Managed by:** System; Admin reviews authorized transitions.
- **Prerequisites:** Enabled earnings, eligible lesson, and a valid effective agreement.
- **Instructor impact:** Determines balances visible in the instructor earnings and withdrawal experience.
- **Related Records:** Settlement Batches and Compensation Exceptions.

### Settlement Batches

- **Purpose:** Group releasable earnings into an auditable manual settlement record.
- **Managed by:** Admin.
- **Safety:** Earnings reserved for a withdrawal cannot enter a settlement batch.
- **Location:** **Instructor Earnings → Related Records**.

### Withdrawal Requests

- **Purpose:** Review instructor requests to withdraw released earnings.
- **Managed by:** Instructor submits through the frontend; Admin reviews and processes according to permissions and maker-checker rules.
- **Prerequisites:** Available earnings, enabled withdrawals, limits satisfied, and an eligible payout method.
- **Related Records:** Payout Methods, Payout Attempts, and Payout Reconciliation.

### Payout Methods

- **Purpose:** Encrypted instructor payout destinations and verification state.
- **Managed by:** Instructor supplies details; authorized Admin/system processes verification and provider provisioning.
- **Location:** **Withdrawal Requests → Related Records**.

### Payout Attempts

- **Purpose:** Provider execution history for approved withdrawals, including retries and uncertain outcomes.
- **Managed by:** System.
- **When to use:** Operational investigation, not routine editing.
- **Location:** **Withdrawal Requests → Related Records**.

### Payout Reconciliation

- **Purpose:** Investigate unknown, reversed, or conflicting payout-provider outcomes.
- **Managed by:** System creates issues; authorized Admin investigates and records evidence.
- **Safety:** Closing an issue cannot directly mark a withdrawal paid.
- **Location:** **Withdrawal Requests → Related Records**.

## Finance Settings

Finance Settings is the single sidebar entry for configuration. Its sections remain separate because they control different trust boundaries:

| Setting | Use |
|---|---|
| **Bank Account** | Platform bank and invoice-facing account details |
| **Payment Gateways** | Student payment collection providers and credentials |
| **Payment Configuration** | Invoice numbering, tax, currency, and collection defaults |
| **Advanced Finance Settings** | Webhook retries, queue processing, and payment logging |
| **Instructor Earnings Rules** | Earning enablement, hold/release, settlement, withdrawal, and payout-execution policy |
| **RazorpayX Payout Settings** | India/INR instructor payout-provider credentials and provisioning controls |

Provider credentials and advanced switches should be changed only during a controlled configuration review. Enabling RazorpayX configuration does not independently authorize payout execution; payout policy has a separate guarded switch.

## Menus That Are Mostly System/Advanced Use

These pages are intentionally removed from the main sidebar but not deleted:

- Payment Reconciliation and Package Payment Discrepancies
- Invoices
- Compensation Exceptions
- Settlement Batches
- Payout Methods
- Payout Attempts
- Payout Reconciliation
- Bank Account, Payment Gateways, Payment Configuration, and Advanced Finance Settings
- Instructor Earnings Rules and RazorpayX Payout Settings

They remain permission-protected and reachable from their parent workflow. Hiding them changes navigation only; it does not alter routes, records, services, audit trails, or authorization.

## Practical Examples

### India student booking

Admin configures an INR lesson price for India + Mathematics + Grade 8 + 60 minutes. A matching student sees that resolved INR price during booking. After trusted gateway confirmation, the booking retains its price snapshot and the system creates the applicable invoice.

### US student booking

Admin configures a USD row for United States + Mathematics + Grade 8 + 60 minutes. This can have a different student price from India, while the instructor's compensation agreement remains unchanged.

### Instructor payout

An instructor completes an eligible paid lesson. The system resolves the agreement effective at the scheduled lesson time, creates an earning, and releases it after the configured hold. The instructor requests withdrawal to a verified payout method. Admin reviews the request; provider attempts and reconciliation records appear only if the payout workflow needs them.

