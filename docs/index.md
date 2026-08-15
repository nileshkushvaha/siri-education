# Documentation Index

This is the canonical, current documentation catalog. If a document isn't listed here, it's either historical (see `docs/archive/`) or missing from this index by mistake — file paths are exact, follow them precisely (some are case-sensitive on Linux).

Root `README.md` is the short project entry point; this file is the detailed catalog it links to.

## Getting started / Development

| Topic | File |
|---|---|
| Local setup, daily commands, adding resources/settings pages | `development/guide.md` |
| Running tests safely, DB safety guard, test conventions | `development/testing.md` |
| Coding standards (PHP, Filament, DTO/Enum/Policy/Event patterns) | `architecture/code-standards.md` |
| Admin panel copy/wording conventions | `admin-copy-style-guide.md` |

## Architecture

| Topic | File |
|---|---|
| Module map, directory layout, service providers, data flow, DB schema | `architecture/overview.md` |
| Domain registry — existing models/services/tests, check before building new | `architecture/domain-registry.md` |
| Hard rules preventing duplicate concepts/tables/services | `architecture/duplicate-prevention-rules.md` |
| Top-level architectural blueprint (modular monolith, module boundaries) | `architecture/technical-architecture-blueprint-phase-1.md` |
| Admin forms shared presentation conventions (headings, breadcrumbs, colors) | `architecture/admin-forms-presentation-conventions.md` |
| Architectural decisions (ADR-style permanent rules) | `decisions.md` |
| Settings system, current settings-class inventory | `settings.md` |
| Feature flags and platform-settings groups — deeper detail | `architecture/platform-settings-feature-flags.md` |
| Subject / TeacherSubject reconciliation (academic taxonomy linkage) | `architecture/subject-teacher-subject-reconciliation.md` |
| Academic taxonomy (subjects/topics) | `architecture/phase-12.5-academic-taxonomy-subject-topics.md` |

## Domain reference

| Domain | File |
|---|---|
| CMS (Pages, Posts, Content Blocks) | `cms.md` |
| Pages | `pages.md` |
| Posts | `posts.md` |
| Navigation | `navigation.md` |
| Media | `media.md` |
| Public frontend (Blade/Livewire/Alpine/Tailwind) | `frontend.md` |
| Admin dashboard (`/admin` command centre, composition layer, drill-down) | `dashboard.md` |
| Users | `users.md` |
| Activity Log / Audit Trail | `activity-log.md` |
| Notifications (admin bell + transactional email) | `notifications.md` |
| Booking Engine | `booking.md` |
| Timezone architecture (resolution, storage, snapshots, input trust) | `architecture/timezone.md` |
| Teacher availability engine (DST/timezone detail) | `architecture/phase-6-instructor-availability-foundation.md` |
| Student pricing matrix (resolution priority) | `architecture/phase-10.2d-student-pricing-matrix.md` |
| Meeting creation (Manual, Google Meet, Zoom) | `architecture/meetings.md` |
| Lesson Lifecycle | `lessons.md` |
| Wallet Ledger | `architecture/wallet.md` |
| Financial Domain (Earnings, Compensation, Settlement, Withdrawals, Payout Execution) | `financial-domain-architecture.md` |
| Financial integrity / concurrency testing methodology | `architecture/phase-15.1-financial-integrity-closure.md` |
| Reviews (eligibility, submission, moderation) | `reviews.md` |
| Instructor-to-Student Lesson Feedback | `instructor-student-feedback.md` |
| Controlled Student-Instructor Messaging | `messaging.md` |
| Support & Dispute Case Management | `support-cases.md` |
| Promotional Wallet Credit Campaigns | `promotional-credits.md` |

## Security

| Topic | File |
|---|---|
| Authentication flow, key files | `security/authentication.md` |
| Security settings pages, services | `security/security.md` |
| Permission matrix — Gate/policy/Shield-permission model | `security/permission-matrix.md` |

## Operations

| Topic | File |
|---|---|
| Cache Manager | `cache-manager.md` |
| Scheduler Monitor | `scheduler.md` |
| Queue Monitor | `queue-monitor.md` |
| Pulse Monitoring | `pulse-monitoring.md` |
| Payment gateway go-live checklist (Razorpay/Stripe) | `architecture/payment-gateway-production-checklist.md` |
| Meeting-provider go-live checklist (Google Meet/Zoom) | `architecture/meeting-provider-production-checklist.md` |
| Real-money provider activation status (RazorpayX payout, Stripe collection) | `financial-provider-activation-handoff.md` |

## Integrations

| Topic | File |
|---|---|
| Resend transactional email | `resend.md` |
| Payment collection & payout provider routing architecture | `payment-collection-and-payout-provider-routing.md` |
| Generic Payable / payment attempts, package checkout, settlement, activation & lesson consumption | `generic-payable-payment-foundation.md` |
| Country-aware package academic context, booking funding & entitlement reservation | `package-academic-context-and-booking-funding.md` |
| Razorpay checkout & payment capture (detailed record) | `architecture/phase-10-razorpay-checkout-payment-capture.md` |
| Payout execution & reconciliation foundation (detailed record) | `phase-16a-payout-execution-reconciliation-foundation.md` |
| RazorpayX India/INR instructor payout adapter (detailed record) | `phase-16b-razorpayx-india-inr-payout-adapter.md` |

## Product / Requirements

| Topic | File |
|---|---|
| Software Requirements Specification | `SRS.md` |
| SRS compliance audit (dated snapshot — see the file's own header before trusting a specific finding) | `SRS_Compliance_Audit.md` |
| Roadmap — what's built vs. planned | `Roadmap.md` |

## Open backlogs

| Topic | File |
|---|---|
| Admin-forms functional/security remediation backlog (unresolved items) | `audits/admin-forms-remediation-backlog.md` |
| Main-dashboard content audit (dated snapshot; implemented — see its §0 corrections) | `audits/main-dashboard-content-audit.md` |

## Historical / archived

Not part of active documentation — retained for context only, see `docs/archive/README.md`. Superseded phase records, one-time foundation reports, completed audits, and merged-away duplicate documents live under `docs/archive/{phases,reports,audits,superseded}/`.
