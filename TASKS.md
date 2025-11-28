# Automotive Repair Shop Management System – Implementation Tasks

This document captures the actionable engineering tasks required to implement the management system outlined in the project brief. Tasks are grouped by module and ordered to build platform foundations first (auth, settings, data models) before user-facing workflows.

## 0. Foundation & Infrastructure
- [x] Project bootstrap: established Composer autoload/bootstrap with Env loader, PDO helper, and basic health endpoint; next step is Docker dev stack and CI lint/tests.
- [x] Core domain models & migrations: scaffolded PHP model classes and initial MySQL migration covering users/roles, CRM, vehicle master/customer vehicles, service types, inventory, estimates/invoices/payments, inspections, appointments, warranty claims, reminders, credit, time entries, and financial ledgers; remaining work includes schema refinement and seeding.
- [ ] Global settings storage: shop profile, tax/pricing defaults, integration keys (Stripe/Square/PayPal/Twilio/SMTP/recaptcha), terms & conditions blocks.
- [ ] File storage setup for uploads (logos, attachments, signatures, receipts) with access control.
- [ ] Notification infrastructure: mail/SMS drivers, templating with variables, logging/audit hooks.
- [ ] Audit logging framework for entity events and settings changes.

## 1. User & Access Management
- [ ] Roles/permissions seed data for Admin, Manager, Technician, Customer; guard routes/controllers accordingly.
- [ ] Authentication flows: registration/import for staff, customer portal login, password reset, optional email verification.
- [ ] Customer-user linkage (auto-link by email on import); profile management; MFA optional later.
- [ ] Access-control middleware & policy tests for each module.

## 2. Dashboard & Reporting
- [ ] Admin/Manager dashboard API aggregating KPIs: estimates status counts, invoice totals/avg, tax totals per range, warranty open/closed counts, SMS/email stats, appointment counts, low-stock inventory.
- [ ] Visual charts endpoints and frontend components (monthly trends for estimates/invoices; service-type breakdown optional).

## 3. Vehicle Data Management (Master Vehicle Table)
- [ ] CRUD UI + filters for Year/Make/Model/Engine/Transmission/Drive/Trim.
- [ ] CSV import with mapping/preview, duplicate detection, and summary of created/updated/failed rows.
- [ ] Progressive dropdown components for Year→Trim selection for estimate forms and customer vehicles; caching for performance.

## 4. Service Types
- [ ] CRUD UI with ordering and active/inactive flag.
- [ ] Integrate into estimate creation and reporting filters.

## 5. Customer & Vehicle Management
- [ ] Customer CRUD with search, filters (commercial/tax-exempt/open invoices), import/export CSV.
- [ ] Customer vehicles tab with link to master vehicle data or free-text, VIN/plate capture, notes.
- [ ] Commercial account toggles and tax-exempt handling.

## 6. Estimates Module
- [ ] Backend list with filters, status actions (approve/reject/expire), email/send link, convert to invoice.
- [ ] Estimate editor: header fields, customer search/create, vehicle chain selector, jobs with line-item grid (labor/parts/fee/discount), totals (tax, call-out, mileage, discounts).
- [ ] Per-job approval status; expiration; notes (internal/customer); audit logging.
- [ ] Customer-facing tokenized view with per-job approve/reject, signature capture, comments; status propagation rules; short-link generator.
- [ ] Email templates and send flow with secure links.

## 7. Inspections
- [ ] Inspection template builder (sections/items, types, status).
- [ ] Inspection completion UI linking to customer/vehicle/estimate/appointment; finalize to stored record and PDF; optional email to customer.
- [ ] Customer portal list/view of inspections.

## 8. Inventory Management
- [ ] Inventory CRUD with filters; low-stock computation and alerting.
- [ ] CSV import/export; markup calculation; location notes.
- [ ] Dashboard tile for low stock and dedicated low-stock page; optional email alerts.

## 9. Warranty Claims
- [ ] Customer portal/public submission with invoice verification; attachments upload; status display.
- [ ] Staff list/detail with timeline, internal notes, status transitions, and messaging (email/SMS) to customer.
- [ ] Dashboard counters for open/resolved claims.

## 10. Reminders (Email & SMS)
- [ ] Campaign model with targeting rules (service type, last visit/invoice, appointment window, mileage/time since service), schedule config, message templates, status lifecycle.
- [ ] Scheduler/cron runner to enqueue due campaigns, compute recipients per preferences, dispatch via mail/SMS, and log outcomes/unsubscribes.
- [ ] Preference UI for customers (opt-in/out, channel preference) and dashboard stats for counts/sends.

## 11. Preset Bundles
- [ ] Bundle CRUD with default job title and line items.
- [ ] "Add from bundle" action in estimate editor to inject job + items for editing.

## 12. Time Tracking & Technician Portal
- [ ] Technician portal showing assigned jobs; start/stop time tracking with geo capture (browser permission fallback handling).
- [ ] Time entry admin view with filters, manual add/edit, override flagging.
- [ ] Map display for geo points (optional); data retained in time entry records.

## 13. Credit Accounts
- [ ] Credit account model per customer: type, limit, balance, terms (net days/APR/late fees), status.
- [ ] Operations: link invoice balances, manual payments, online payments to credit account where enabled, late-fee application and summaries.
- [ ] Customer portal credit page: balance/limit/available credit, due dates, transactions, online payment option.

## 14. Invoices & Payments
- [ ] Invoice creation from estimates (all or selected approved jobs) and standalone invoices; status lifecycle including pending approval for scope changes.
- [ ] Customer-facing invoice view with PDF/print, pay-now (Stripe/Square/PayPal) and credit account interactions.
- [ ] Payment processing flows with webhooks for Stripe/Square/PayPal; transaction logging; return URL handling.

## 15. Financials & Reporting
- [ ] Income (non-invoice), expenses, purchases ledgers with attachments for receipts.
- [ ] Financial report endpoints/UI: date-range summary and monthly breakdown (income, expenses, purchases, gross/net); CSV export.

## 16. Appointments & Availability
- [ ] Availability configuration (hours, windows, holidays, slot length, buffers).
- [ ] Appointment entity CRUD with calendar/list views, technician assignment, status transitions.
- [ ] Customer booking flow showing computed availability, linked to estimates when applicable; notifications for confirmation/changes.

## 17. Settings & Integrations
- [ ] Shop/company profile UI; logo upload; currency.
- [ ] Terms & conditions editor blocks for frontend request, estimates, invoices.
- [ ] Pricing defaults (tax rules, labor rate, call-out/mileage defaults) applied across estimates/invoices.
- [ ] Integration settings pages for Stripe/Square/PayPal/Twilio/SMTP/recaptcha/map keys; secure storage and validation tests.

## 18. Notifications & Templates
- [ ] Email/SMS template manager with variables; test-send capability; per-entity logs.
- [ ] Event triggers wired to notifications (estimate sent/approved/rejected, invoice created/paid, appointment events, warranty updates, payment reminders).

## 19. Import/Export & Audit
- [ ] CSV export endpoints for key datasets; import flows for customers, vehicle master data, inventory with validation and reporting.
- [ ] Audit log viewer with filters; track entity actions and settings changes with metadata snapshots.

## 20. QA & Observability
- [ ] Automated tests per module (unit + feature + permissions); seed/factory data for fixtures.
- [ ] API request/response logging, error tracking setup, performance profiling hooks.
- [ ] Admin health/status page for queues, schedulers, and integration connectivity checks.
