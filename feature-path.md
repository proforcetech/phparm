## 1. Workflow & Process Automation (COMPLETED)
The current system successfully bridges the gap between Estimates and Invoices through the WorkorderService. To improve efficiency:
[x] Inspection-to-Estimate Bridge: Implement a feature that automatically identifies "failed" items from the TechnicianInspections and offers to add them as line items to a draft estimate or the active workorder.
[x] Status-Driven Notifications: Link WorkorderStatusHistory to the NotificationEventService. For example, when a Workorder transitions to "Parts Pending," automatically notify the Parts Manager; when "Ready for Pickup," notify the customer via SMS.
[x] Automatic Quality Control (QC) Checklist: Require a QC checklist to be completed by a second user (Manager/Shop Lead) before a Workorder can be transitioned from "Repair Complete" to "Invoicing."

## 2. Parts & Inventory Management (COMPLETED)

[x] Integrated PartsTech Procurement: Enable technicians to initiate a "Parts Cart" directly from the WorkorderDetail view. This cart should sync with PartsTech, allowing the manager to approve and order without re-keying data.
[x] SKU Vehicle Compatibility: Leverage the InventoryVehicleCompatibility model to highlight parts in the inventory list that match the vehicle currently on the workorder, reducing "wrong part" errors.
[x] Core Return Tracking: Add a dedicated status/ledger for "Cores." Shop management often loses revenue by failing to track and return parts (like alternators or batteries) for core credits.
[x] Create a methodology to the scanning of bacodes; expand the current SKU/Part Number storage by adding UPC/Barcode support

## 3. Technician Productivity Tools (COMPLETED)

[x] Granular Labor Clocking: Instead of clocking into a Workorder generally, allow technicians to clock into specific tasks (e.g., "Brake Job" vs "Oil Change"). This enables "Efficiency Reporting" (Actual Time vs. Flat-rate Time).
[x] Mobile Evidence Capture: Enhance TechnicianInspections.jsx to support direct camera access for uploading high-resolution video evidence of mechanical failures. This "Visual Proof" significantly increases estimate approval rates.
[x] Offline Technician Sync: Ensure offlineSync.js is fully utilized for inspections. Technicians often work in "dead zones" (deep inside a shop or under a car) and need their progress saved locally until a connection is restored.

## 4. Inventory Enhancements

[x] Stock Order Lifecycle: The InventoryStockOrderRepository should be linked to the financial ledger. When a stock order is marked as "Received," it should automatically update the InventoryItem quantities and create a FinancialEntry for the accounts payable.
[x] Automated Low-Stock Alerts: Utilize the inventory-low-stock.php cron job to send daily summaries to the manager, rather than just waiting for a manual check.

## 5. Advanced Reporting & KPIs

[x] Work-in-Progress (WIP) Aging: A dashboard widget showing how long workorders have been sitting in "Parts Pending" or "Authorized" status to identify bottlenecks.
[x] Technician Efficiency Dashboard: A view in Reports.jsx that shows labor profit margins per technician.
[x] Customer Retention Tracking: Link the CustomerRepository to Workorder history to identify "Lost" customers (e.g., those who haven't had a workorder in 6+ months) for automated re-marketing campaigns.

## 2. Security and User Managment Improvements

[x] Mandatory 2FA for High-Privilege Roles: While a `TwoFactorSetupWizard` exists, user management should allow administrators to enforce **Two-Factor Authentication (2FA)** for specific roles, such as Admins and Dispatchers, to protect sensitive customer data.
[x] Secure Invitation Flow: Currently, user creation likely involves an administrator setting a temporary password. It is safer to implement an **email-based invitation system** using `EmailVerificationToken` logic. This ensures users set their own secure passwords and validates their email access immediately.
[x] Account Deactivation Logic: Ensure that the `UserRepository` supports a "Soft Delete" or "Active" flag. Users involved in roadside assistance (Technicians/Dispatchers) should be **deactivated rather than deleted** to maintain the integrity of historical audit logs and financial entries associated with their ID.
[ ] Password Complexity & History: Implement strict validation in `UserForm.jsx` and the backend to ensure password strength and prevent the reuse of recently used passwords.
[x] "Last Active" Tracking: Add a `last_login_at` or `last_activity_at` column to the `users` table and display it in the list view. This is critical for auditing technician availability and identifying stale accounts.
[x] Enhanced User List Filtering: The `UsersList.jsx` should include advanced filters for **Role**, **Status (Active/Inactive)**, and **2FA Status**. This allows administrators to quickly identify users who haven't completed security setups.
[x] Integrated Audit Links:** Each user row in the management panel should have a direct link to their filtered **Audit Log**. This allows an admin to instantly see a specific user's change history (e.g., which jobs they assigned or edited).
[x] Bulk Actions: To improve efficiency, implement multi-select checkboxes in the `UsersList` to allow for **bulk deactivation** or **bulk role updates**.
[x] Session Management: Provide a view within `Profile.jsx` or the Admin panel to see **Active Sessions** (IP address, device, login time) and allow for remote logout if a technician's mobile device is lost.
[x] User Impersonation:** For support and troubleshooting, allow top-level Administrators to "Impersonate" a technician or dispatcher. This helps verify that complex role-based permissions in `AccessGate` are functioning correctly for a specific user's context.
[x] Custom Role Builder: While `RoleSeeder` provides defaults, a UI to create custom roles with specific toggles from `RolePermissions.php` would allow the system to scale as the dispatch team grows.
[x] Export to CSV: Leverage the existing `CsvExportService` to allow administrators to export the current filtered user list for external reporting or payroll auditing.


## 3. Performance Enhancements and Changes For CMS

[ ] Proactive Pre-caching: Currently, the system uses a request-based caching mechanism (CMSCacheService). Implementing "Stale-While-Revalidate" or pre-rendering pages upon save would ensure the first visitor to a new page doesn't experience a "cache miss" delay.
[ ] Automated Image Pipeline: Extend the MediaController to automatically generate responsive images (Srcset) and WebP versions of uploaded assets to improve Core Web Vitals.
[x] Component-Level Caching: For complex pages with many dynamic components, implement partial caching. If one component is dynamic (e.g., a "Current Availability" block) and others are static, the static portions should be cached independently to minimize database lookups.
[ ] Asset Bundling for CMS Components: If components require specific JS/CSS, implement a dependency manager that bundles only the required assets for a given page, reducing the overall payload size.
[ ] Component-Level Caching: For complex pages with many dynamic components, implement partial caching. If one component is dynamic (e.g., a "Current Availability" block) and others are static, the static portions should be cached independently to minimize database lookups.
[x] Asset Bundling for CMS Components: If components require specific JS/CSS, implement a dependency manager that bundles only the required assets for a given page, reducing the overall payload size.
[ ] Staging and Preview Workflow: Implement a status column (Draft, Pending Review, Published) for pages. Add a "Preview" button in CMSPageForm.jsx that renders the page with a temporary token without making it public.
[ ] Asset Bundling for CMS Components: If components require specific JS/CSS, implement a dependency manager that bundles only the required assets for a given page, reducing the overall payload size.
[x] Staging and Preview Workflow: Implement a status column (Draft, Pending Review, Published) for pages. Add a "Preview" button in CMSPageForm.jsx that renders the page with a temporary token without making it public.
[ ] Integrated SEO Toolkit: Add a dedicated SEO section to the page editor for managing meta_title, meta_description, canonical_url, and Open Graph tags. The CMSRenderingService should automatically inject these into the head template.
[ ] Staging and Preview Workflow: Implement a status column (Draft, Pending Review, Published) for pages. Add a "Preview" button in CMSPageForm.jsx that renders the page with a temporary token without making it public.
[x] Integrated SEO Toolkit: Add a dedicated SEO section to the page editor for managing meta_title, meta_description, canonical_url, and Open Graph tags. The CMSRenderingService should automatically inject these into the head template.
[ ] Version Control & Revisions: Store snapshots of cms_pages and cms_components in a cms_revisions table. This allows administrators to audit changes and roll back to a known-good state if an error is published.
[ ] Integrated SEO Toolkit: Add a dedicated SEO section to the page editor for managing meta_title, meta_description, canonical_url, and Open Graph tags. The CMSRenderingService should automatically inject these into the head template.
[x] Version Control & Revisions: Store snapshots of cms_pages and cms_components in a cms_revisions table. This allows administrators to audit changes and roll back to a known-good state if an error is published.
[ ] Granular Permissions: Use the existing AccessGate to define specific roles for the CMS, such as CMS_EDITOR (can edit content) vs. CMS_PUBLISHER (can push changes to live).
[ ] Version Control & Revisions: Store snapshots of cms_pages and cms_components in a cms_revisions table. This allows administrators to audit changes and roll back to a known-good state if an error is published.
[x] Granular Permissions: Use the existing AccessGate to define specific roles for the CMS, such as CMS_EDITOR (can edit content) vs. CMS_PUBLISHER (can push changes to live).
[ ] Visual Component Reordering: Transform the component list in CMSPageForm.jsx into a drag-and-drop interface. This allows dispatchers or marketers to easily reorder sections (e.g., moving "Towing Services" above "Battery Jumpstart") without deleting and re-adding.
[ ] Granular Permissions: Use the existing AccessGate to define specific roles for the CMS, such as CMS_EDITOR (can edit content) vs. CMS_PUBLISHER (can push changes to live).
[x] Visual Component Reordering: Transform the component list in CMSPageForm.jsx into a drag-and-drop interface. This allows dispatchers or marketers to easily reorder sections (e.g., moving "Towing Services" above "Battery Jumpstart") without deleting and re-adding.
[ ] Dynamic Component Blocks: Create specialized components for roadside assistance, such as a "Live Coverage Map" or "Estimated Wait Time" block that pulls data from the dispatch service.
[ ] Visual Component Reordering: Transform the component list in CMSPageForm.jsx into a drag-and-drop interface. This allows dispatchers or marketers to easily reorder sections (e.g., moving "Towing Services" above "Battery Jumpstart") without deleting and re-adding.
[x] Dynamic Component Blocks: Create specialized components for roadside assistance, such as a "Live Coverage Map" or "Estimated Wait Time" block that pulls data from the dispatch service.
[ ] Media Library Folders: As the media library grows, implement a folder/tagging system in MediaController to prevent a flat, unmanageable list of images.
[ ] Dynamic Component Blocks: Create specialized components for roadside assistance, such as a "Live Coverage Map" or "Estimated Wait Time" block that pulls data from the dispatch service.
[x] Media Library Folders: As the media library grows, implement a folder/tagging system in MediaController to prevent a flat, unmanageable list of images.
[ ] Internal Linking Tool: Add a search-as-you-type tool in the Rich Text Editor that allows editors to easily find and link to other internal CMS pages by title rather than manually entering URLs.
[ ] Media Library Folders: As the media library grows, implement a folder/tagging system in MediaController to prevent a flat, unmanageable list of images.
[x] Internal Linking Tool: Add a search-as-you-type tool in the Rich Text Editor that allows editors to easily find and link to other internal CMS pages by title rather than manually entering URLs.
[ ] Idempotent Cache Invalidation: Ensure that updating a component automatically triggers a cache purge for all pages where that component is used.
[ ] Internal Linking Tool: Add a search-as-you-type tool in the Rich Text Editor that allows editors to easily find and link to other internal CMS pages by title rather than manually entering URLs.
[x] Idempotent Cache Invalidation: Ensure that updating a component automatically triggers a cache purge for all pages where that component is used.
[ ] Schema Validation for Components: Since components likely use JSON or flexible fields, implement backend validation to ensure that required data (like image URLs or call-to-action text) is present before saving.
[ ] Search Index Integration: Implement a hook that updates an internal search index (like Meilisearch or a simple full-text DB index) whenever CMS content changes, powering a site-wide search for customers.
[ ] Idempotent Cache Invalidation: Ensure that updating a component automatically triggers a cache purge for all pages where that component is used.
[x] Schema Validation for Components: Since components likely use JSON or flexible fields, implement backend validation to ensure that required data (like image URLs or call-to-action text) is present before saving.
[x] Search Index Integration: Implement a hook that updates an internal search index (like Meilisearch or a simple full-text DB index) whenever CMS content changes, powering a site-wide search for customers.



## 4. Dispatch & Recommendation Engine (/src/Services/Dispatch)

[ ] Capacity-Based Dispatching: The recommendation engine should check not just distance, but Workload Velocity. If a driver is 1 mile away but is currently mid-hookup on a complex recovery, they shouldn't be the top recommendation.
[ ] Equipment-to-Job Matching: Implement hard-stop filters. If a job is flagged as "All-Wheel Drive" or "Low Clearance Garage," the system must exclusively recommend Flatbeds or Low-Profile trucks.
[ ] Geofence-Triggered Status Changes: Use the tracking data in TrackingService.php to automatically transition a job to "Arrived" when the driver’s GPS is within 500 feet of the pickup coordinates.
[x] Predictive ETA (Traffic-Aware): Integrate the Google Distance Matrix API into DispatchRecommendationService. Standard straight-line distance is often inaccurate in urban towing scenarios.
[ ] "Deadhead" Minimization: The algorithm should favor drivers whose current "drop-off" location for an active job is near the "pick-up" location of the new job.
[x] Equipment-to-Job Matching: Implement hard-stop filters. If a job is flagged as "All-Wheel Drive" or "Low Clearance Garage," the system must exclusively recommend Flatbeds or Low-Profile trucks.
[x] Capacity-Based Dispatching: The recommendation engine should check not just distance, but Workload Velocity. If a driver is 1 mile away but is currently mid-hookup on a complex recovery, they shouldn't be the top recommendation.
[x] Geofence-Triggered Status Changes: Use the tracking data in TrackingService.php to automatically transition a job to "Arrived" when the driver’s GPS is within 500 feet of the pickup coordinates.
[ ] Predictive ETA (Traffic-Aware): Integrate the Google Distance Matrix API into DispatchRecommendationService. Standard straight-line distance is often inaccurate in urban towing scenarios.
[x] "Deadhead" Minimization: The algorithm should favor drivers whose current "drop-off" location for an active job is near the "pick-up" location of the new job.

## 5. Driver Experience (/src/react/views/driver & /src/Services/drive)

[x] Compulsory Photo Evidence: To compete with Towbook, the DriverJobIntake.jsx must require "Pre-Tow" photos (4 corners of the vehicle + VIN/Odometer) before the "Hooked" status can be selected. This is the #1 defense against damage claims.

[x] Digital Damage Appraisal: A simple UI where drivers tap a car diagram to mark existing dents/scratches.
[x] Offline Idempotency: Since drivers lose signal frequently, the offlineQueue.js utility must be the primary driver for all status updates, ensuring signatures and photos sync once signal is restored without duplicating ledger entries.

## 6. Additional features for Towing/Roadside

[x] In-App Chat with Dispatch: Move away from phone calls. The ChatWidget.jsx should be integrated directly into the job view so dispatchers can send gate codes or updated drop-off instructions silently.
[x] Turn-by-Turn Deep Linking: A "Navigate" button that passes coordinates directly to Waze, Google Maps, or Apple Maps.

## 7. Storage & Impound Management (/src/react/views/storage)

[x] Automated Fee Ledger: The StorageFeeLedger.jsx should automatically calculate daily storage, gate fees, and after-hours release fees based on the intake_at timestamp.
[x] VIN-to-Vehicle Lookup: Integrate a VIN decoder (like your NhtsaVinDecoder.php) that automatically populates Year/Make/Model/Weight Class during intake to prevent data entry errors.
[x] Lien Notice Automation: A workflow that flags vehicles that have been in storage for X days and generates a state-compliant PDF Lien Notice using LienNoticePdfGenerator.php.
[x] Auction Management: A status workflow for "Abandoned" vehicles, tracking the move from storage to the auction lot.
[x] Inventory Spot-Checks: A mobile view for yard managers to scan license plates and confirm the physical vehicle matches the digital record.

## 8. Partner & Motor Club Integration (/src/Services/Integrations)

[x] Email Parser Enhancements: Ensure your PartnerEmailParser.php handles "Job Cancelled" emails to automatically remove offered jobs from your dispatch queue.
[x] Split Billing: Support for "Primary" and "Secondary" payers. (e.g., Agero pays the first $50, the customer pays the remaining $120 for over-mileage).
[x] Bi-Directional Digital Dispatch: Your AgeroPartnerDispatchAdapter and AaaPartnerDispatchAdapter should support SWIFT and Digital Dispatch protocols. This allows you to "Accept" a job in your software and have it automatically update the motor club's portal.

## 9. Customer Experience (Tracking & Public Views)

[x] "Uber-Style" Tracking Link: When a driver is dispatched, the NotificationEventService should SMS a unique job_tracking_link to the customer. This link should show the driver's real-time position on a map (from TrackingService) and an updated ETA.

[x] Public Payment Portal: Allow customers to pay their over-mileage or storage fees via Stripe/Square directly from their phone before the driver releases the vehicle.

## 10. Technical & Performance Improvements

[x] Unified Audit Log: Ensure every status change (e.g., "En Route" -> "Arrived") logs the GPS coordinates of the action, not just the timestamp. This is critical for resolving disputes with motor clubs.
[x] Database Partitioning: Roadside tracking data (GPS pings) grows exponentially. Partition the driver_locations table by month to ensure the dispatch map remains fast as your history grows.
[x] WebSocket Integration: The DispatchView.jsx map should not poll the server. Use a real-time provider (like Pusher or a self-hosted Soketi instance) to push driver_location_updated events.

## Summary of "Must-Have" Feature Set

[x] Digital Dispatch Adapters: Full handshake with Agero/AAA/Geico portals.
[x] Integrated VIN Decoding: Reduce manual entry for technicians.
[x] Customer Tracking Link: Real-time map view for the stranded motorist.
[x] Automated Storage Ledger: Daily recurring fees with grace periods.
[x] Mobile Damage App: 4-photo minimum + car diagram marking.


## 11. Inventory Performance Enhancements

The current implementation appears to handle basic CRUD operations but may struggle as the inventory database grows into the thousands of items (common in auto shops).

[x] Optimize Search Queries (Backend):

 Issue: The InventoryItemRepository likely uses LIKE %...% wildcard searches for SKUs and names. This prevents the database from efficiently using indexes, leading to full table scans.

 Suggestion: Implement Full-Text Search (e.g., MySQL FULLTEXT indexes) for the name and description fields. For SKUs, use a prefix search (LIKE 'term%') where possible to utilize standard B-Tree indexes.

 Ref: src/Services/Inventory/InventoryItemRepository.php

[x] Frontend Debouncing & Pagination:

 Issue: InventoryList.jsx may trigger API calls on every keystroke or filter change.

 Suggestion: Implement a debounce hook (e.g., 300-500ms delay) on the search input to reduce server load. Ensure the backend supports cursor-based pagination (or optimized offset pagination) so retrieving item 10,000 doesn't require scanning the previous 9,999.

 Ref: src/react/views/inventory/InventoryList.jsx

[x] Eager Loading for Alerts:

 Issue: The InventoryAlerts view likely polls for low stock.

 Suggestion: Instead of calculating low stock on the fly (which is expensive), add a cached low_stock_count or a database view/flag is_low_stock updated via triggers or model events. This makes the "Alerts" endpoint instant.

 Ref: src/react/views/inventory/InventoryAlerts.jsx

## 12. Inventory Security Enhancements

[x] Granular Permission Checks (RBAC):

 Issue: InventoryItemController.php might only check if a user is logged in.

 Suggestion: Implement strict role-based checks.

 can('inventory.view') for the list.

 can('inventory.edit') for updating quantities.

 can('inventory.adjust') specifically for manual stock corrections (often a source of internal theft).

 Ref: src/Services/Inventory/InventoryItemController.php

[x] Input Sanitization & Validation:

 Issue: Importing inventory via CSV or bulk updates can be a vector for CSV Injection or malformed data.

 Suggestion: Strictly validate sku formats (alphanumeric only) and ensure quantity and price are cast to appropriate numeric types before hitting the database. Strip HTML tags from description fields to prevent Stored XSS.

## 13. Must-Have Inventory Features (Currently Missing)

[x] Core Tracking System:

 Gap: Auto parts often have a "Core Charge" (a deposit on the old part).

 Feature: Add core_price, core_cost, and core_eligible flags to InventoryItem.

 Workflow: When an item with a core is sold, the system should automatically track that a "Core Return" is expected from the customer, and subsequently from the shop to the vendor.

[x] Transaction Audit Log (Ledger):

 Gap: Changing quantity from 10 to 5 without context is dangerous.

 Feature: Create an inventory_transactions table. Every stock change must reference a source:

 Source: JOB_WORKORDER (Ref: Workorder #123)

 Source: PURCHASE_ORDER (Ref: Stock Order #456)

 Source: MANUAL_ADJUSTMENT (Reason: "Damaged", "Found", "Theft")

 Ref: src/Services/Inventory/InventoryItemRepository.php (Update methods should write to this log).

[x] Bin Locations / Aisle Mapping:

 Gap: Knowing you have 5 filters is useless if you don't know where they are.

 Feature: Add bin_location (e.g., "Aisle 1, Shelf B") to the InventoryItem model and display it prominently in InventoryList.jsx.

## 14. Additional Inventory Features

[x] Barcode / QR Code Integration:

 Feature: Allow the frontend (InventoryList.jsx) to accept input from a USB barcode scanner or use the device camera to scan a VIN or UPC to instantly find the part.

[x] Vendor Warranty Claims Workflow:

 Feature: A dedicated view to manage "Defective" inventory.

 Status flow: Defective -> RMA Requested -> Shipped -> Credit Received.

 Ref: Link this to the cp/inventory/alerts page so unpaid warranty claims are highlighted.

[x] Stock Forecasting:

 Feature: Use historical usage data (from Workorders) to suggest "Reorder Points" dynamically. If you sell 10 oil filters a week, the system should suggest a min-stock of 15, rather than a static number set by a human.

## 15. In-Shop & Mobile Repairs Additional Features:

[x] Service Menu / Canned Jobs: While ServiceTypes exist, a "Canned Job" feature (bundling Parts + Labor + Fees into one quick-add item) is a must-have for speed (e.g., "Standard 5qt Oil Change"). Ref: Bundle files exist, so verify this UI is optimized for quick selection.

[x] Customer Communication Hub: MessagingService exists, but a unified "Timeline" view for the customer (seeing photos, approving estimates, chatting) is a "Nice to Have" that boosts trust.

[x] Digital Vehicle Inspections (DVI): InspectionReport exists. Ensure this supports video uploads, not just photos, as video has higher conversion rates for upsells.

## 16. Additional Roadside & Towing Features

[x] GOA (Gone On Arrival) Logic: Roadside jobs often cancel while en route. A specific workflow to bill a "GOA Fee" to the motor club or customer is essential.
[x] Map-Based Dispatch: Geofencing exists, but a visual "Drag and Drop" map board for dispatchers to assign calls to the nearest truck is a critical efficiency feature.
[x] Truck Checklists: Pre-trip/Post-trip inspection forms for the tow trucks themselves (DOT compliance).

# Expansion of Features set into shop ERP

## 1. HR / Employee Management

 Concept: Move beyond simple User accounts.

 Recommendations:

[x] New Model: Create an Employee model linked 1:1 to the User model.
[ ] Fields: Store hire_date, emergency_contact, pay_structure (Hourly, Flat Rate, Commission, Salary), and skills (e.g., "Level 3 Tech", "Heavy Duty Towing").

[x] Document Vault: storage for contracts, certifications (ASE, WreckMaster), and expiration dates for driver's licenses.

## 2. Employee Self-Services (ESS) Portal

[x] Employee Self-Services (ESS) Portal

 Concept: Reduce administrative overhead by letting staff help themselves.

 Recommendations:

 Dashboard: A simplified view separate from the main admin panel.

 Features:

[x] Time Clock: Clock In/Out (with geolocation for mobile techs). Expanding upon current time keeping functionality.
[x] Schedule View: "When am I working next?"
[x] Pay History: View/Download PDF pay stubs.
[x] Profile Update: Change address/phone number.

## 3. Cash Register (POS) & Direct Sales

 Concept: Quick counter sales without creating a full Workorder/Vehicle record.

 Recommendations:

[x] "Quick Sale" Interface: A high-contrast, touch-friendly UI.
[ ] Workflow: Bypasses "Vehicle" requirement. Uses a generic "Walk-in Customer" if no name is provided.
[x] Cash Drawer Management:

 Opening/Closing: Track starting cash float and ending count.

 Shift Report: A FinancialEntry that records the "Overage/Shortage" at the end of the day.

## 4. Return Processing (Direct Sales)

 Concept: Handling customer returns distinct from Vendor Warranty.

 Recommendations:

[ ] Credit Memos: Do not just "delete" the invoice. Create a negative invoice (Credit Memo).
[x] Restock Logic: Ask the user: "Is this item sellable?"

 Yes: Increment Inventory count.

 No: Move to "Defective/Warranty" bin (triggering the Vendor Warranty flow).

[x] Refunds: Limit refunds to the original payment method (e.g., prevent cash refunds for credit card purchases).

## 5. Banking (Payment, Reconcile, Deposits)

 Concept: True accounting features.

 Recommendations:

[x] Chart of Accounts: Ensure FinancialCategory covers Asset, Liability, Income, Expense, and Equity accounts.
[ ] Bank Feeds: This is complex to build. Strongly recommend integrating a provider like Plaid or Yodlee to fetch transactions automatically.
[ ] Reconciliation UI: A split-screen view: "Bank Statement Transactions" (left) vs "System Ledger" (right). Users "match" them.
[ ] Undeposited Funds: When cash/checks are received, they go to a temporary asset account ("Undeposited Funds"). The "Cash Deposit" feature groups them and moves the total to the "Checking Account" to match the single bank slip.

## 6. Payroll

 Concept: Calculating pay for complex automotive compensation plans.

 Recommendations:

 The "Engine": Build the Gross Pay calculator, not the Net Pay (Tax) calculator. Taxes are incredibly risky and change constantly.

 Commission Logic: Calculate based on Billed Hours (Flat Rate) or Gross Profit (Service Writers).

 Workflow:

[ ] Timesheet Approval: Manager approves hours/flagged hours.
[x] Payroll Run: System calculates Gross Pay.
[x] Timesheet Approval: Manager approves hours/flagged hours.
[ ] Payroll Run: System calculates Gross Pay.
[x] Export/Sync: Send this data to a dedicated provider (Gusto, ADP, QuickBooks) to handle taxes and direct deposit. Do not attempt to build a tax engine unless you have a dedicated legal/compliance team.

## 7. Leave / Vacation Requests

 Concept: Managing availability.

 Recommendations:

[x] New Model: LeaveRequest (Start Date, End Date, Type: Vacation/Sick/Unpaid, Reason, Status).

 Integration:

[x] Calendar: Approved leave must block the employee out on the Dispatch and Appointment boards automatically.
[x] Payroll: Approved paid leave adds "PTO Hours" to the payroll run.
[x] Calendar/Payroll Integration: Dispatch and appointment scheduling respects approved leave while payroll reporting includes PTO hours.

## 8. Branches / Multi-Location

 Concept: Managing multiple physical shops under one company.

 Recommendations:

[ ] Data Structure: Add branch_id to almost every major table (Users, Inventory, Invoices, Workorders).
[ ] Inventory Transfer: A workflow to move parts from "Main St Branch" to "Downtown Branch" without buying/selling.
[ ] Reporting: Ability to filter Dashboards by "Current Branch" vs "All Locations".
[ ] Tenant Scoping: Ensure a user at Branch A cannot accidentally see or edit Branch B's workorders (unless they are a Regional Manager).
