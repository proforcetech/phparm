This for the expansion of the phparm system, from its current functionality to encompass additional services and feature expansions.

Once complete this should function as a service order / field service management platform for a multi-division company handling:
- Commercial Cleaning
- Commercial / Industrial Maintenance
- Equipment maintenance for commercial/retail
- Gas station/convivence store equipment
- IT Support
- Point of Sales
- Fueling/Gas station equipment
- Building and Property maintenance.
- Telecommunications
- Surveillance Systems
- Access Control
- Fleet Maintenance

Because work includes repairs, support, inspections, recurring service, and new installations, the system should support both:

-- Reactive work: incidents, breakdowns, urgent requests, support tickets
-- Planned work: contracts, preventative maintenance, scheduled service, inspections, install projects

1: Changes/expansion to core platform structure:

Multi-division support so each service line can have:
- its own categories
- task templates
- checklists
- billing rules
- technicians / crews

Customer records with:
- company name
- site/location list
- contacts
- billing contacts
- service preferences
- contract management and status

Service location management:
- one customer with a single site/location
- one customer with multiple buildings/sites
- site-specific notes, access instructions, hours, alarm info, gate codes, equipment lists


Role-based access:
- admin
- dispatcher
- accounting
- technician / field worker
- warehouse
- customer portal user

Audit trail: who created, assigned, changed, closed, invoiced, approved each record

- Business unit / department level dashboards
- Branch / region support if you operate from multiple hubs
- Custom fields engine by service line
- White-label or customer-branded portal views for major accounts

2. Customer-facing portal
- customer access for the portal is restricted to accounts created by our team (existing ports/signup/request not in the expanded service scopes/departments must remain separate)
- Submit service requests
- View status of current requests and work orders
- View service history by site
- View contracts and service agreements
- Accept contracts with e-signature
- View invoices and payment status
- Pay invoices online
- Add/update saved payment methods
- Messaging / support-desk style updates tied to tickets or work orders
-  Upload attachments: photos, PDFs, signed documents, site documents
-Contact management for customer users
- Permission controls so a customer can restrict users by location or billing access

Request type wizard: repair, routine, emergency service, recurring cleaning issue, IT support, install request, inspection request

Customer approval center for:
- estimates
- change orders
- proposed repairs
- recurring service renewals

Site asset view:
- equipment at their location
- warranty status
- maintenance due dates
- Portal notifications by email/SMS

- Live appointment tracking / technician ETA
- Customer satisfaction survey after completion
- Knowledge base / FAQs for common support issues
- Customer-side budget / capital forecast dashboard
- Subscription autopay enrollment workflow
- Customer mobile app or PWA

3. Service request / support desk system

- Ticket / incident number generation
- Work order number generation
- Request categories and subcategories by service line

Priority levels: critical, emergency, high, normal, low, routine, delayed/defered

Status workflow:
- new
- triaged
- scheduled
- in progress
- waiting on parts
- waiting on customer
- completed
- invoiced
- closed

Closed Reasons, used when closed, and not completed: Nuisance call, duplicate

SLA tracking: response due, arrival due, resolution due, Internal notes vs customer-visible notes, File/photo attachments, Reassign work orders, Add additional workers / crew members

- Link a support ticket to a field work order
-, Escalation rules for overdue requests

- Queue system by department

Auto-routing based on:

- service category
- location
- contract/SLA tier
- technician skill
- Parent/child ticket relationships
- Root cause, resolution code, and failure code tracking
- Convert ticket to estimate / project / recurring service plan

- AI-assisted triage suggestions
- Suggested troubleshooting scripts
- Customer sentiment / urgency detection from submitted text
- Voice note support

4. Work order management

- Work order and incident numbering
- Categories and subcategories
- Priority and severity
- Problem description and scope of work
- Site, contact, and asset/equipment association
- Assignment to one or more workers
- Dispatch date and scheduled time window

Labor tracking:
- travel time
- on-site time
- overtime
- Materials / parts billing
- Miscellaneous charges

- Checklists and completion tasks
- Before/after photo support
- Customer sign-off on completion
- Reassignment and crew updates
- Work order closure process with required fields

Ability to mark: repair completed, temporary fix, follow-up required, quote needed, waiting on part, closed (duplicate or nuisance, triggers notification management)

- Ability to split one request into multiple work orders

- Work order templates by service type
- Recurring work order generation
- Change order handling for installations/projects
- Quote-to-work-order conversion
- Dependency tracking for multi-step jobs
- Required safety / compliance forms before start
- Technician mobile workflow with offline capture
- Barcode / QR scan to identify site equipment
- Route optimization for field workers
- Geo-fenced arrival/departure logging
- Voice-to-text technician notes
- On-site parts reservation from van stock
- Digital punch list for installs
- Subcontractor assignment handling

5. Scheduling, dispatch, and calendar
Calendar views: day, week, month

- technician/crew
- department
- Dispatch board
- Drag-and-drop scheduling
- Appointment windows
- Technician availability
- Conflict detection
- Emergency insertion / rescheduling
- Recurring service scheduling
- Color coding by department, priority, or status
- Customer/site blackout dates or allowed service windows
- Skill-based assignment suggestions
- Travel buffer rules
- Multi-tech crew scheduling
- Holiday calendars
- On-call rotation scheduling
- Schedule approval workflows
- Route map view
- ETA prediction
- Self-scheduling for approved customer contract work
- Capacity forecasting by region/team

6. Preventative maintenance and scheduled services
- PM plans by customer, site, or asset
- Frequency options: daily, weekly, monthly, quarterly, semiannual, annual
- meter/runtime based if relevant
- Auto-generate PM work orders
- Checklist templates
- Service history retention
- Missed/overdue PM tracking
- Contract-linked service entitlements

- Seasonal service templates
- Bundled PM packages
- Reminder notifications before due date
- PM compliance reporting by customer/site
- PM-based parts forecasting
- Condition-based maintenance triggers
- Sensor/IoT integration for critical systems
- Warranty-aware PM recommendations

7. Inventory system

Warehouse, vehicle, and customer-location inventory. This is a major module and should behave like a true multi-location inventory system.

Inventory items with:
- SKU
- description
- category
- unit of measure
- serial/lot tracking where needed
- cost
- price / markup rules
- reorder levels
- Multiple inventory locations:
- warehouse
- service vehicle
- customer site
- staging area
- return/inspection area
- Quantity on hand / allocated / available
- Transfer between locations
- Return process
- Inventory issued to work orders
- Inventory consumed on work orders
- Inventory returned from work orders
- Purchase receiving
- Cycle counts / stock adjustments
- Serialized equipment tracking for installed devices
- Customer site equipment register
- RMA / defective part workflow
- Vendor management and purchasing
- Transfer approvals
- Pick lists for scheduled jobs
- Kit / bundle support for common installs
- Vehicle restock recommendations
- Mobile barcode scanning
- Warranty claim tracking for installed parts/equipment
- Demand forecasting
- Smart min/max by van or branch
- Cross-location transfer suggestions
- RFID support
- Vendor lead-time based replenishment

8. Asset and equipment tracking at customer locations

This matters a lot for surveillance, access control, telecom, maintenance, and some IT work.

- Installed asset register by site
- Asset type, model, serial number, install date
- Warranty status
- Maintenance history

Linked documents: manuals, diagrams, photos, configuration notes

- Ability to associate work orders and inspections to assets

- Parent-child asset relationships

panel  -> door controllers  -> readers

NVR  -> cameras

rack  -> switches  -> patch panels

- Asset condition ratings
- End-of-life estimates
- Replacement recommendation flags
- Config backup repository
- Network/IP tracking for connected devices
- Floorplan/location mapping

9. Contracts and service agreements

- Contract records by customer/site

Service terms: covered services, exclusions, response times, billing terms, renewal dates, recurring price

- Contract documents with e-signature

- Contract acceptance workflow

- Contract status tracking

- Site-level coverage rules

- Link contract entitlements to ticket/work order billing

-  Amendment / addendum tracking

- Auto-renew reminders

- Contract utilization reporting

- SLA enforcement by contract tier

- Contract-linked PM schedule generation
- Redlining / version comparison
- Customer negotiation workflow
- Margin analysis by contract

10. Invoicing, payments, and accounting

- Invoice generation from work orders, contracts, or recurring service
- Partial billing and progress billing for installs/projects
- Taxes, discounts, fees, credits
- Online payments
- Saved payment methods
- Payment application to invoices
- Customer statements
- Aging report
- Refund/credit memo support
- Export or sync to accounting system
- Labor, parts, trip, service fee, and contract billing lines
- Recurring invoicing for service contracts
- Deposits and prepayments

- Auto-billing for approved customers

- Multi-site consolidated billing

- PO number tracking

- AR follow-up workflows

- Revenue recognition support for long-term installs/projects

- Budget vs actual tracking

- Customer credit limits

- Financing options for larger projects

11. Fleet maintenance requests and fleet services

This can mean both our own fleet and customer fleet work. The system should support both.

- Fleet asset records:

- vehicle/unit
- VIN/serial
- odometer/hours
- assignment
- Maintenance request submission
- PM scheduling
- service history
- downtime tracking
- parts and labor tracking per unit
- inspection logs
- out-of-service status
- fuel/tire/repair cost history
- technician assignment by fleet skill
- external vendor repair logging
- accident/incident linkage
- telematics integration
- predictive maintenance alerts
- fleet cost per mile / hour reports

12. FIT inspections

- Inspection templates by service line
- Pass/fail/NA checklist items
- Required notes and photos
- Deficiency capture
- Corrective action generation
- Signature capture
- Inspection score/result
- Link inspection findings to work orders or quotes
- Compliance-based templates
- Repeat inspection schedules
- Failed item escalation rules
- Mobile inspection mode
- Risk scoring
- Trend analysis by site/asset
- QR-code launch for inspections at equipment/site level

13. Historical data and reporting

- Work order history
- Ticket history
- Service history by customer/site/asset
- Labor history
- parts/material usage history
- revenue by customer/service line
- overdue/open work order reporting
- technician productivity reporting
- PM completion reporting
- SLA compliance reporting
- profitability by work order
- first-time fix rate
- callback rate
- repeat incident trends
- inventory valuation and turnover
- contract performance reporting
- executive dashboards
- drill-down BI dashboards
- forecasting
- anomaly detection
- customer health scores

14. Capital planner

- Track aging assets at customer sites
- Condition and lifecycle scoring
- Replacement estimates
- Budget year planning
- Recommended replacement lists
- multi-year capital plan
- risk ranking
- estimated operating cost vs replacement cost
- customer-facing capital recommendation reports
- what-if planning scenarios
- financing / lease options
- ROI calculators for upgrades

15. Mobile / field technician features

- Mobile-friendly technician interface
- View assigned work orders
- Start/stop travel and labor
- update status in field
- add notes, photos, files
- capture signatures
- record parts used
- complete checklists
- create follow-up work order or quote request
- offline mode with sync later
- barcode/QR scanning
- GPS stamping
- voice dictation
- route launch/navigation integration
- mobile printing
- wearable notifications
- safety incident reporting

16. Notifications and communication

- Email notifications
- optional SMS notifications: alerts for: new ticket, assignment, schedule changes, overdue work, approval needed, invoice issued, payment received
- Internal comments and customer-visible updates
- rules-based notification engine
- reminder sequences
- escalation notifications
- customer chat
- technician-to-dispatch live chat
- mass outage / incident broadcast notices

17. Security and compliance

- role-based permissions
- audit log
- MFA for staff
- secure document storage
- e-signature audit records
- payment tokenization via gateway
- customer/user session controls
- data backup and restore plan
- IP/device logging
- approval workflows for sensitive changes
- configurable retention policies
- SSO
- SOC-oriented logs
- customer-specific data segregation controls
- advanced compliance reporting

18. Integrations

- payment gateway
- e-signature platform
- accounting export/sync
- email/SMS provider
- calendar sync
- QuickBooks / Xero / Zoho / similar accounting sync
- mapping/GPS integration
- document storage integration
- vendor/purchasing integration
- IoT/monitoring integrations
- telecom/network monitoring integrations
- access control / surveillance platform integrations
- customer ERP / procurement portal integrations


20. A few smart additions not in the original outline

- Quotes / estimates module for repairs, upgrades, and installations
- Change order workflow for install jobs
- Site access management for gate codes, alarm instructions, escort requirements
- Document library for manuals, contracts, compliance docs, floorplans
- Service checklist templates by department
- Warranty tracking on parts, labor, and installed equipment
- Subcontractor management/Thirdparty handoff
- Ability to have initial technician add an addition (assisting) technician to a work order
- Ability to request transfer/reassignment of work order to another technician
- Customer approval workflow for billable work beyond contract scope
- Time and expense tracking tied to work orders
- Service level dashboards for major clients


22. Suggested module list for the actual software

- CRM / Customers / Sites
- Customer Portal
- Contracts / E-Signature
- Support Desk / Tickets
- Work Orders / Dispatch
- Calendar / Scheduling
- Technician Mobile App
- Inventory / Warehousing / Vehicle Stock
- Installed Asset Management
- Preventative Maintenance / Scheduled Services
- FIT Inspections
- Invoicing / Payments / Accounting
- Fleet Maintenance / Fleet Services
- Reporting / Historical Data
- Capital Planner
- Notifications / Automation
- Security / Permissions / Audit Logs
