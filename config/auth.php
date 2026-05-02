<?php

return [
    'passwords' => [
        'expire_minutes' => 60,
        'throttle_minutes' => 2,
        'min_length' => 12,
        'min_entropy' => 50,
        'min_categories' => 3,
        'history_limit' => 5,
        // Maximum password age in days before forced rotation. 0 disables.
        'max_age_days' => (int) env('PASSWORD_MAX_AGE_DAYS', 90),
        // How many days before expiry to start warning the user via banner.
        'warn_before_days' => (int) env('PASSWORD_WARN_BEFORE_DAYS', 14),
    ],
    'verification' => [
        'require_staff_verification' => false,
        'require_customer_verification' => true,
        'token_ttl_hours' => 48,
    ],
    'jwt' => [
        // Secret key for signing JWT tokens (min 32 characters)
        // In production, use a strong random key from environment variable
        'secret' => env('JWT_SECRET', 'your-256-bit-secret-key-change-in-production'),
        // Access token lifetime in seconds (default: 1 hour)
        'ttl' => (int) env('JWT_TTL', 3600),
        // Refresh token lifetime in seconds (default: 7 days)
        'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 604800),
    ],
    'roles' => [
        'admin' => [
            'label' => 'Admin',
            'description' => 'Full control across all modules',
            'requires_2fa' => true,
            'permissions' => ['*'],
        ],
        'dispatcher' => [
            'label' => 'Dispatcher',
            'description' => 'Coordinate dispatch operations and roadside workflows',
            'requires_2fa' => true,
            'permissions' => [
                'dispatch.*',
                'roadside.*',
                'messages.*',
                'truck_checklists.view',
                'driver_shifts.view',
                'branches.dashboard.view',
                'crm.companies.view', 'crm.sites.view', 'crm.contacts.view',
                // Phase 2.1: dispatchers see assets so they can route based on equipment.
                'assets.view', 'asset_types.view',
                // Phase 13 (M3): dispatchers see lease info so routing
                // accounts for end-of-lease equipment going off-fleet.
                'asset_leases.view',
                // Phase 13 (M4): dispatchers see acquisitions so they
                // can plan resource availability around incoming installs.
                'asset_acquisitions.view',
                // Phase 3.1 of docs/expansion-plan.md: dispatch sees tickets so
                // they can triage/route incoming requests.
                'tickets.view', 'tickets.manage', 'tickets.assign',
                'ticket_categories.view',
                // Phase 3.2: dispatchers read SLA policies (but don't edit them).
                'ticket_sla_policies.view',
                // Phase 3.3: dispatchers can see routing rules for transparency.
                'ticket_routing_rules.view',
                // Phase 3.4: dispatchers assign by queue + see escalation rules.
                'ticket_queues.view', 'ticket_escalation_rules.view',
                // Phase 3.6: dispatchers read close/resolution/failure catalogs.
                'ticket_close_reasons.view', 'ticket_resolution_codes.view',
                'ticket_failure_codes.view',
                // Phase 4.1: dispatchers read contracts so ticket routing
                // can consider coverage. Editing stays with managers.
                'contracts.view',
                // Phase 5.1 of docs/expansion-plan.md: dispatchers see PM
                // plans/schedules for routing awareness; edits stay with managers.
                'pm.view',
                // Phase 7.1: dispatchers see fleet units so they can route
                // based on who's currently assigned a truck/van.
                'fleet.view',
                // Phase 10.1: dispatchers read sub list (so they know who's
                // available) and create assignments on a WO; vendor master
                // edits stay with managers.
                'subcontractors.view', 'subcontractors.manage',
                // Phase 10.2: dispatchers read change orders for routing/
                // visibility and can compose drafts on the WO; approval is a
                // separate gate held by managers only (separation of duties).
                'workorder_change_orders.view', 'workorder_change_orders.manage',
                // Phase 10.3: dispatchers are the operational owner of who's
                // on which WO — they read all tech-help requests, approve/
                // decline, fulfil with a specific user, and can directly
                // add/remove secondary techs on a WO without a formal request.
                'workorder_tech_requests.view', 'workorder_tech_requests.manage',
                'workorder_tech_requests.create',
                // Phase 10.4: dispatch is the natural owner of the WO
                // reassignment workflow — read pending requests, approve or
                // decline, fulfil with a chosen replacement, and (in
                // emergencies) reassign directly without a formal request.
                'workorder_reassignment_requests.view', 'workorder_reassignment_requests.create',
                'workorder_reassignment_requests.manage',
                // Phase 10.5: dispatch owns the ticket triage queue —
                // they re-score tickets, accept/reject scorer suggestions,
                // and apply the recommended priority/category/assignee
                // back to the ticket via the accept path.
                'ticket_triage.view', 'ticket_triage.generate', 'ticket_triage.manage',
                // Phase 10.6: dispatch owns the geo-fence map and the
                // route plans they hand to techs each morning. Full
                // manage on both surfaces; record_event for backfilling
                // when a tech's mobile dropped a fix; execute so they
                // can stamp arrival/departure on a tech's behalf when
                // the field side is offline.
                'geofences.view', 'geofences.manage', 'geofences.record_event',
                'route_plans.view', 'route_plans.manage', 'route_plans.execute',
                // Phase 10.7: dispatch reads field-tech voice notes for the
                // WO timeline + can manually re-trigger transcription on a
                // failed note. Manage covers pin/unpin (operational notes
                // float to the top) and deletion (cleanup of accidental
                // recordings).
                'voice_notes.view', 'voice_notes.create',
                'voice_notes.transcribe', 'voice_notes.manage',
                // Phase 10.8 of docs/expansion-plan.md: workorder kit/bundle
                // installs. Dispatch sees planned installs across the shop
                // (the prep-parts queue), can install/cancel on the tech's
                // behalf, and manage covers hard-deletion of stale planned
                // installs.
                'workorder_kits.view', 'workorder_kits.install',
                'workorder_kits.cancel', 'workorder_kits.manage',
                // Cross-cutting: dispatchers triage security events
                // (failed logins, account lockouts) when an on-shift
                // user reports trouble. Read-only — manage stays with
                // managers/admins.
                'security_events.view',
            ],
        ],
        'manager' => [
            'label' => 'Manager',
            'description' => 'Manage shop operations, estimates, workorders, invoices, schedules, inventory',
            'requires_2fa' => false,
            'permissions' => [
                'users.view', 'users.create', 'users.update', 'users.delete', 'users.invite',
                'customers.*', 'vehicles.*', 'estimates.*', 'workorders.*', 'invoices.*', 'payments.*', 'appointments.*',
                'inventory.*', 'inspections.*', 'warranty.*', 'reminders.*', 'bundles.*', 'time.*',
                'credit.*', 'reports.view', 'documents.*', 'settings.view', 'notifications.view', 'service_types.*', 'messages.*',
                'roadside.*', 'dispatch.*',
                'truck_checklists.*', 'driver_shifts.*',
                'settings.divisions.manage',
                // Phase 0.5 of docs/expansion-plan.md: managers can define and
                // update custom fields for their division.
                'custom_fields.view', 'custom_fields.manage',
                // Phase 0.6 of docs/expansion-plan.md: per-division dashboards.
                'branches.dashboard.view',
                // Phase 1.1 of docs/expansion-plan.md: companies/sites/contacts.
                'crm.companies.view', 'crm.companies.manage',
                'crm.sites.view', 'crm.sites.manage',
                'crm.contacts.view', 'crm.contacts.manage',
                // Phase 1.2: alarm/gate codes — separate perm because sensitive.
                'crm.sites.codes.view', 'crm.sites.codes.manage',
                // Phase 2.1 of docs/expansion-plan.md: installed-asset model.
                'assets.view', 'assets.manage',
                'asset_types.view', 'asset_types.manage',
                // Phase 13 of docs/woms-expansion-plan.md (Must M3): asset
                // leases. Managers author lease records and capture end-of-
                // lease decisions (renew / buyout / return / replace).
                'asset_leases.view', 'asset_leases.manage',
                // Phase 13 (M4): managers drive the acquisition workflow
                // through quote → approve → PO → receive → install. The
                // final activation (CMDB linkage) is admin-only and lives
                // under .activate; managers don't get it here.
                'asset_acquisitions.view', 'asset_acquisitions.manage',
                // Phase 3.1 of docs/expansion-plan.md: support-desk tickets.
                'tickets.view', 'tickets.manage', 'tickets.assign',
                'ticket_categories.view', 'ticket_categories.manage',
                // Phase 3.2: managers define SLA policy catalog per division/priority.
                'ticket_sla_policies.view', 'ticket_sla_policies.manage',
                // Phase 3.3: managers define routing rules.
                'ticket_routing_rules.view', 'ticket_routing_rules.manage',
                // Phase 3.4: managers define queues + escalation rules.
                'ticket_queues.view', 'ticket_queues.manage',
                'ticket_escalation_rules.view', 'ticket_escalation_rules.manage',
                // Phase 3.6: managers define close/resolution/failure catalogs.
                'ticket_close_reasons.view', 'ticket_close_reasons.manage',
                'ticket_resolution_codes.view', 'ticket_resolution_codes.manage',
                'ticket_failure_codes.view', 'ticket_failure_codes.manage',
                // Phase 4.1: managers author & sign contracts.
                'contracts.view', 'contracts.manage', 'contracts.sign',
                // Phase 5.1 of docs/expansion-plan.md: preventative-maintenance
                // plans + schedules.
                'pm.view', 'pm.manage',
                // Phase 7.1 of docs/expansion-plan.md: fleet units + meter
                // history + assignment history.
                'fleet.view', 'fleet.manage',
                // Phase 9 of docs/expansion-plan.md: capital planner — managers
                // run portfolio-wide aging reports, build budget plans, author
                // recommendation PDFs.
                'capital_plans.view', 'capital_plans.manage',
                // Phase 10.1 of docs/expansion-plan.md: subcontractor vendor
                // management — managers maintain the master list, assign subs
                // to work orders, and rate performance.
                'subcontractors.view', 'subcontractors.manage',
                // Phase 10.2 of docs/expansion-plan.md: change-order workflow.
                // Managers compose, edit, submit, AND approve/reject change
                // orders. Approve is a separate gate so we can split duties
                // later (e.g., give techs manage but not approve).
                'workorder_change_orders.view', 'workorder_change_orders.manage',
                'workorder_change_orders.approve',
                // Phase 10.3 of docs/expansion-plan.md: additional-tech
                // requests. Managers can do everything — view, create on
                // behalf of techs, approve/decline/fulfil, and directly add
                // or remove secondary techs from a WO.
                'workorder_tech_requests.view', 'workorder_tech_requests.create',
                'workorder_tech_requests.manage',
                // Phase 10.4: managers cover dispatch on the reassignment
                // workflow when the dispatcher is unavailable + handle the
                // direct emergency-reassignment path themselves.
                'workorder_reassignment_requests.view', 'workorder_reassignment_requests.create',
                'workorder_reassignment_requests.manage',
                // Phase 10.5: managers cover dispatch on triage when the
                // dispatcher is unavailable; full read+generate+manage so
                // they can clear backlog overnight or for off-shift queues.
                'ticket_triage.view', 'ticket_triage.generate', 'ticket_triage.manage',
                // Phase 10.6: managers cover dispatch on the routing surface
                // when dispatch is off-shift — full perms on fences, events,
                // route plans, and per-stop execute.
                'geofences.view', 'geofences.manage', 'geofences.record_event',
                'route_plans.view', 'route_plans.manage', 'route_plans.execute',
                // Phase 10.7: managers cover dispatch on the voice-note
                // surface — full perms so they can clean up old notes,
                // re-trigger failed transcriptions, and manage tag taxonomy.
                'voice_notes.view', 'voice_notes.create',
                'voice_notes.transcribe', 'voice_notes.manage',
                // Phase 10.8 of docs/expansion-plan.md: managers cover
                // dispatch on the kit-install workflow when dispatch is
                // off-shift; full perms so they can purge stale planned
                // installs and reverse erroneous installed kits.
                'workorder_kits.view', 'workorder_kits.install',
                'workorder_kits.cancel', 'workorder_kits.manage',
                // Cross-cutting: managers own the SOC log + retention
                // policy surface for the shop. Full perms on both:
                // edit policies, run them ad-hoc (with dry-run), and
                // record manual SOC entries.
                'retention.view', 'retention.manage', 'retention.run',
                'security_events.view', 'security_events.manage',
                // Cross-cutting: SSO + MFA hardening. Managers can configure
                // OIDC providers for the shop and force-revoke trust tokens
                // when a tech reports a stolen laptop.
                'sso_providers.view', 'sso_providers.manage',
                'trusted_devices.manage',
                // Cross-cutting: reporting/BI catalog, saved reports, and
                // scheduled deliveries. Managers manage the shared catalog
                // and own the schedules that fan out to ops mailboxes.
                'reporting.view', 'reporting.manage',
                // Cross-cutting: third-party platform integrations
                // (QuickBooks/Xero/mapping/IoT/telecom/access control).
                // Managers can view + manage connections.
                'integrations.view', 'integrations.manage',
                // Full CMS access (matches admin for CMS operations)
                'cms.*'
            ],
        ],
        'technician' => [
            'label' => 'Technician',
            'description' => 'Work estimates, workorders, inspections, jobs, and time tracking',
            'requires_2fa' => false,
            'permissions' => [
                'customers.view', 'vehicles.view', 'estimates.view', 'estimates.create', 'estimates.update',
                'workorders.view', 'workorders.manage',
                'inspections.*', 'time.*', 'appointments.view', 'service_types.view',
                'custom_fields.view',
                'crm.companies.view', 'crm.sites.view', 'crm.contacts.view',
                // Phase 2.1: technicians read + manage installed assets, read the catalog.
                'assets.view', 'assets.manage',
                'asset_types.view',
                // Phase 13 (M3): technicians read lease info on assets they
                // service so the in-cab UI can warn before exceeding mileage
                // caps. Editing stays with managers.
                'asset_leases.view',
                // Phase 3.1 of docs/expansion-plan.md: technicians can read
                // and update their own tickets; reassignment gated on assign.
                'tickets.view', 'tickets.manage',
                'ticket_categories.view',
                // Phase 3.2: techs read SLA policies so UI can show targets.
                'ticket_sla_policies.view',
                // Phase 3.4: techs read queue list so they can pick from it.
                'ticket_queues.view',
                // Phase 3.6: techs read close/resolution/failure catalogs to
                // pick values when closing/resolving tickets.
                'ticket_close_reasons.view', 'ticket_resolution_codes.view',
                'ticket_failure_codes.view',
                // Phase 4.1: technicians read contracts so they can see
                // entitlement/scope info on work they're assigned.
                'contracts.view',
                // Phase 5.1: technicians execute PM work; they read plans
                // and can advance schedule state (status notes, etc).
                'pm.view', 'pm.manage',
                // Phase 7.1: techs read fleet units and record meter readings
                // (manage) when they service a unit. Retiring units + opening
                // assignments stays with managers.
                'fleet.view', 'fleet.manage',
                // Phase 10.1: techs see what subs are working their WO so they
                // can hand off / coordinate; vendor edits + assignments stay
                // with managers/dispatchers.
                'subcontractors.view',
                // Phase 10.2: techs compose change-order drafts when they
                // discover extra work in the bay; submit goes to a manager
                // for customer approval. Techs cannot approve their own.
                'workorder_change_orders.view', 'workorder_change_orders.manage',
                // Phase 10.3: primary techs file requests for additional help
                // on their WO (extra hands, specialty, training shadow). They
                // can view + create + cancel their own; only manager/dispatch
                // approve/decline/fulfil — the .manage gate gates the
                // authority moves separately.
                'workorder_tech_requests.view', 'workorder_tech_requests.create',
                // Phase 10.4: techs can ask to be swapped off a WO (or
                // ask that someone else be swapped off) but cannot approve
                // their own request — manager/dispatch holds .manage.
                'workorder_reassignment_requests.view', 'workorder_reassignment_requests.create',
                // Phase 10.5: techs can read triage suggestions on tickets
                // they're working — useful when reviewing why an
                // automation pinned the ticket as p1. Generation +
                // accept/reject stays with dispatch/manager.
                'ticket_triage.view',
                // Phase 10.6: techs read the active geo-fence list (to
                // render "you are inside customer-site" on mobile),
                // post position fixes that auto-emit fence events, and
                // execute their own route stops (en_route → arrived →
                // completed/skipped). Fence + plan management stays
                // with dispatch/manager.
                'geofences.view', 'geofences.record_event',
                'route_plans.view', 'route_plans.execute',
                // Phase 10.7: techs can record voice notes from the field,
                // re-transcribe their own failed notes, and read all
                // notes attached to WOs they're assigned to. Manage
                // (pin/edit/delete) stays with manager/dispatch — a tech
                // shouldn't be able to delete their own notes after
                // submission, since notes form part of the WO audit trail.
                'voice_notes.view', 'voice_notes.create',
                'voice_notes.transcribe',
                // Phase 10.8 of docs/expansion-plan.md: techs install kits
                // on their assigned WOs (the workshop-floor "apply this
                // bundle now" gesture) and can cancel installs they made
                // by mistake. Hard-delete (manage) stays with manager so
                // historical install records aren't erased by the tech.
                'workorder_kits.view', 'workorder_kits.install',
                'workorder_kits.cancel',
                // CMS content editing (no administrative settings)
                'cms.pages.view', 'cms.pages.create', 'cms.pages.update', 'cms.pages.delete',
                'cms.categories.view', 'cms.categories.create', 'cms.categories.update', 'cms.categories.delete',
                'cms.menus.view', 'cms.menus.create', 'cms.menus.update', 'cms.menus.delete',
                'cms.media.view', 'cms.media.create', 'cms.media.update', 'cms.media.delete',
                'cms.components.view', 'cms.components.create', 'cms.components.update', 'cms.components.delete',
                'cms.dashboard.view',
                'cms.templates.view', 'messages.*',
                'dispatch.offers.view', 'dispatch.offers.accept', 'dispatch.tokens.manage',
                'truck_checklists.view', 'truck_checklists.complete',
                'driver_shifts.view', 'driver_shifts.start', 'driver_shifts.end',
            ],
        ],
        'parts' => [
            'label' => 'Parts',
            'description' => 'Manage inventory alerts and pull/order requests',
            'requires_2fa' => false,
            'permissions' => [
                'inventory.view',
                'inventory.edit',
                'inventory.adjust',
                'inventory.*',
                // Phase 10.8 of docs/expansion-plan.md: parts staff watch
                // the planned-installs queue to pre-pick kit components
                // and can complete the install (decrement stock + create
                // WO line items) at the parts counter. No manage — they
                // shouldn't be able to nuke install history.
                'workorder_kits.view', 'workorder_kits.install',
            ],
        ],
        'roadside' => [
            'label' => 'Roadside',
            'description' => 'Roadside assistance access (placeholder)',
            'requires_2fa' => false,
            'permissions' => [
                'roadside.*',
                'dispatch.*',
                'truck_checklists.view',
                'driver_shifts.view',
            ],
        ],
        'cms' => [
            'label' => 'CMS',
            'description' => 'Manage CMS content, 404 logs, and redirects',
            'requires_2fa' => false,
            'permissions' => [
                'cms.*',
            ],
        ],
        'cms_editor' => [
            'label' => 'CMS Editor',
            'description' => 'Create and update CMS content without publishing changes live.',
            'requires_2fa' => false,
            'permissions' => [
                'cms.dashboard.view',
                'cms.pages.view', 'cms.pages.create', 'cms.pages.update', 'cms.pages.delete',
                'cms.categories.view', 'cms.categories.create', 'cms.categories.update', 'cms.categories.delete',
                'cms.menus.view', 'cms.menus.create', 'cms.menus.update', 'cms.menus.delete',
                'cms.media.view', 'cms.media.create', 'cms.media.update', 'cms.media.delete',
                'cms.components.view', 'cms.components.create', 'cms.components.update', 'cms.components.delete',
                'cms.templates.view',
            ],
        ],
        'cms_publisher' => [
            'label' => 'CMS Publisher',
            'description' => 'Edit CMS content and publish updates to live pages.',
            'requires_2fa' => false,
            'permissions' => [
                'cms.dashboard.view',
                'cms.pages.view', 'cms.pages.create', 'cms.pages.update', 'cms.pages.delete', 'cms.pages.publish',
                'cms.categories.view', 'cms.categories.create', 'cms.categories.update', 'cms.categories.delete', 'cms.categories.publish',
                'cms.menus.view', 'cms.menus.create', 'cms.menus.update', 'cms.menus.delete', 'cms.menus.publish',
                'cms.media.view', 'cms.media.create', 'cms.media.update', 'cms.media.delete', 'cms.media.publish',
                'cms.components.view', 'cms.components.create', 'cms.components.update', 'cms.components.delete',
                'cms.templates.view',
            ],
        ],
        'customer' => [
            'label' => 'Customer',
            'description' => 'Customer portal scoped to their profile and documents',
            'requires_2fa' => false,
            'permissions' => [
                'portal.profile', 'portal.vehicles', 'portal.estimates', 'portal.workorders', 'portal.invoices', 'portal.warranty', 'portal.reminders',
                'workorders.view'
            ],
        ],

        // Phase 0.4 of docs/expansion-plan.md: roles added to prepare for the
        // multi-division field-service expansion. See expansion.md role list.
        'accounting' => [
            'label' => 'Accounting',
            'description' => 'Invoicing, payments, credit, and financial reporting',
            'requires_2fa' => true,
            'permissions' => [
                'invoices.*', 'payments.*', 'credit.*',
                'customers.view', 'vehicles.view',
                'workorders.view', 'estimates.view',
                'reports.view', 'documents.view', 'documents.create',
                'notifications.view',
                'custom_fields.view',
                'branches.dashboard.view',
                // Accounting needs full billing-contact write, read-only for companies/sites.
                'crm.companies.view',
                'crm.sites.view',
                'crm.contacts.view', 'crm.contacts.manage',
                // Phase 2.1: accounting reads assets for billing/contract reconciliation.
                'assets.view', 'asset_types.view',
                // Phase 9 of docs/expansion-plan.md: accounting consumes capital
                // plans for capex forecasting; read-only — managers author them.
                'capital_plans.view',
                // Phase 10.1: accounting reads subcontractor master + assignments
                // for cost reconciliation against vendor invoices.
                'subcontractors.view',
                // Phase 10.2: accounting reads change orders for invoicing
                // reconciliation — approved CO totals roll into the WO bill.
                'workorder_change_orders.view',
                // Phase 10.3: accounting reads tech-help requests + secondary
                // tech assignments so labor allocation reports can attribute
                // hours to the right user(s) on multi-tech WOs.
                'workorder_tech_requests.view',
                // Phase 10.4: accounting reads reassignment history so labor
                // attribution reports can correctly split billable hours
                // across primary techs over the WO's lifetime.
                'workorder_reassignment_requests.view',
                // Phase 10.5: accounting reads triage suggestions so they
                // can audit how often the scorer's recommendations got
                // applied vs overridden — feeds model-quality reports.
                'ticket_triage.view',
                // Phase 10.6: accounting reads route plans + per-stop
                // actuals so labor attribution reports can correlate
                // billed time against where the tech actually was.
                'route_plans.view', 'geofences.view',
                // Phase 10.7: accounting reads voice notes to reconcile
                // labor narrative against billed time — a "spent 90 min on
                // brake bleed" voice note backs up the WO timecard entry.
                'voice_notes.view',
                // Phase 10.8: accounting reads kit installs so labor +
                // parts cost reconciliation can attribute kit components
                // back to their bundle for margin reports.
                'workorder_kits.view',
                // Cross-cutting: accounting reads retention policies +
                // SOC events for compliance audits (SOX/SOC2 trails
                // reference exactly which retention windows are in
                // force at audit time).
                'retention.view', 'security_events.view',
                // Cross-cutting: accounting consumes the unified report
                // catalog (revenue, aging, customer rankings) and saves
                // / shares their own. Manage stays with managers — they
                // own the cross-team distribution policy.
                'reporting.view',
                // Cross-cutting: accounting needs visibility into the
                // QuickBooks/Xero connection state and the sync log so
                // they can confirm a month-end push went through. They
                // do NOT get manage — credential rotation stays with
                // admins/managers.
                'integrations.view',
            ],
        ],
        'warehouse' => [
            'label' => 'Warehouse',
            'description' => 'Inventory, bundles, transfers, and receiving',
            'requires_2fa' => false,
            'permissions' => [
                'inventory.*', 'bundles.*',
                'customers.view', 'vehicles.view',
                'workorders.view',
                'notifications.view',
                'custom_fields.view',
                // Phase 10.8 of docs/expansion-plan.md: warehouse owns the
                // bundle catalog and watches the kit-install queue so
                // they can fulfil parts and reverse mistaken installs.
                'workorder_kits.view', 'workorder_kits.install',
                'workorder_kits.cancel',
            ],
        ],
        // portal_user is the NEW multi-customer portal role required by
        // expansion.md line 61 (accounts created by staff, restricted per
        // site/billing scope). Kept distinct from the legacy 'customer'
        // role so the existing signup/request flow remains isolated.
        'portal_user' => [
            'label' => 'Portal User',
            'description' => 'Customer-company portal user (multi-site, staff-provisioned)',
            'requires_2fa' => false,
            'permissions' => [
                'portal.profile',
                'portal.sites.view', 'portal.sites.assets.view',
                'portal.tickets.view', 'portal.tickets.create',
                'portal.workorders.view',
                'portal.estimates.view', 'portal.estimates.approve',
                'portal.invoices.view', 'portal.invoices.pay',
                'portal.contracts.view', 'portal.contracts.sign',
                'portal.documents.view', 'portal.documents.upload',
                'portal.messages.view', 'portal.messages.send',
            ],
        ],
    ],
    'customer_portal' => [
        'login_enabled' => true,
        'auto_link_on_import' => true,
        'allow_registration' => false,
    ],
];
