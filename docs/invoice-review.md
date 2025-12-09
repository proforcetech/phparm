# Invoice Functionality Review

**STATUS: ✅ COMPLETE**

Compared the WordPress plugin under `arm-main` to the standalone system to identify invoice-related gaps that need follow-up work.

## Missing service methods wired to controllers
- The standalone `InvoiceController` calls `list`, `findById`, and `updateStatus` on the invoice service, but these methods are not implemented in `InvoiceService`, leaving list/detail/status routes non-functional.【F:src/Services/Invoice/InvoiceController.php†L35-L134】【F:src/Services/Invoice/InvoiceService.php†L26-L318】

### Tasks
1. [x] **Implement the controller’s required invoice service methods**
   - Add `list`, `findById`, and `updateStatus` implementations in `InvoiceService` that honor filters, return models, and reuse status validation/logging.
   - Ensure the controller calls resolve to working queries so invoice listing, retrieval, and status updates operate end-to-end.

## Estimate conversion parity gaps
- The plugin only converts APPROVED estimates and automatically copies call-out fees and mileage lines into invoices, while the standalone service converts any estimate ID and only pulls explicitly passed job IDs without the extra charges.【F:arm-main/includes/invoices/Controller.php†L88-L183】【F:src/Services/Invoice/InvoiceService.php†L26-L182】

### Tasks
2. [x] **Enforce approved-only conversions and mirror plugin line item extras**
   - Require the source estimate to be approved before conversion and surface a clear error otherwise.
   - Automatically append call-out fee and mileage charges from the source estimate to the new invoice, matching the plugin’s behavior.

## Public-facing payment and PDF experience
- The plugin exposes tokenized public invoice URLs with built-in PDF downloads plus Stripe Checkout and PayPal buttons for unpaid invoices; the standalone implementation only returns a raw record for public view and generates placeholder pay URLs without tokens or provider hand-offs.【F:arm-main/templates/invoice-view.php†L13-L176】【F:src/Services/Invoice/InvoiceService.php†L142-L151】

### Tasks
3. [x] **Add secure public invoice access with payment and PDF flows**
   - Issue and persist public tokens for invoices, load customer-facing views by token, and allow PDF downloads for owners.
   - Wire payment initiation to real providers (Stripe/PayPal/Square) for unpaid invoices, aligning with the plugin’s public payment buttons.
