# Roadmap

## Complete

- Authentication (login, register, 2FA, password reset, email verification, account lock)
- Users (management, roles, approval workflow, force password change)
- Roles & Permissions (Spatie Permission + Filament Shield)
- Settings (see `settings.md` for the current full list of settings groups)
- Security (6 settings pages, account protection, session management)
- CMS (23 block types, polymorphic content blocks — see `cms.md`)
- Pages (lifecycle, SEO, scheduling, preview, soft deletes)
- Posts (lifecycle, categories, tags, auto-publishing, related posts)
- Navigation (NestedSet, 11 link types, role/permission visibility, publish windows)
- Notifications (Filament database bell, transactional email, pipeline from Activity Log — see `notifications.md`)
- Activity Log / Audit Trail (User, Guest, System actor types, AuditTrailService)
- Login History (session tracking, browser/device/IP, filters)
- Cache Manager, Scheduler Monitor, Queue Monitor, Pulse Monitoring
- Booking Engine (availability, teacher assignment, concurrency-safe scheduling, recurring bookings — see `booking.md`)
- Payment collection (Razorpay/Stripe checkout) and instructor payout (RazorpayX) — **code-complete, gated behind account verification before real traffic**, see `docs/architecture/financial-provider-activation-handoff.md`
- Wallet ledger (see `docs/architecture/wallet.md`)
- Meeting creation (Manual, Google Meet, Zoom — see `docs/architecture/meetings.md`)
- Lesson lifecycle and attendance (see `lessons.md`)
- Reviews (eligibility, submission, moderation — see `reviews.md`)
- Instructor-to-student lesson feedback (see `instructor-student-feedback.md`)
- Controlled student-instructor messaging (see `messaging.md`)
- Support & dispute case management (see `support-cases.md`)
- Promotional wallet credit campaigns (see `promotional-credits.md`)
- Referral program
- Instructor earnings, compensation agreements, settlement, and withdrawals (see `financial-domain-architecture.md`)

## Planned / known gaps

- Real payment-provider activation: RazorpayX payout and Stripe collection are code-complete but not yet enabled for real traffic — see `docs/architecture/financial-provider-activation-handoff.md` for the exact external prerequisites (account verification, credentials) blocking each.
- Media Library admin UI (Filament resource for managing uploaded media directly — currently media is only manageable through the model it's attached to).
- Dashboard improvements (charts, richer widgets).
- Password history enforcement (table exists; enforcement not yet wired — see `docs/security/permission-matrix.md`/`docs/development/testing.md` area docs for related security settings).
- Instructor-facing "my bookings" surface with meeting-link visibility (documented gap — see `docs/architecture/meetings.md`'s Visibility section).
- Open functional/security items tracked in `docs/audits/admin-forms-remediation-backlog.md` (admin-panel-specific — confirmation/permission/validation inconsistencies, not full features).

This list reflects what's currently built vs. not; it is not a delivery schedule and doesn't imply committed dates.
