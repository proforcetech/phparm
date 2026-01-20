    ✅ Estimate Creation (/cp/estimate/create)

        UI Enhancement: Add "Part Number/SKU" and "List Price" fields to the part line-item editor.

        Inventory Integration: Implement an autocomplete lookup on the "Part Number/SKU" field querying the Inventory database.

        Behavior:

            Match Found: Auto-populate Description, Cost, and List Price from the inventory record.

            No Match: Allow manual entry for all fields (treat as a non-inventory/outside-purchase part).

    ✅ Workflow Automation (Estimate to Workorder)

        Trigger: Execute logic immediately upon converting an Accepted Estimate into a Workorder.

        Logic: Iterate through workorder part items:

            In Stock: Generate an Inventory Pull Request to reserve the item.

            Out of Stock / Non-Inventory: Generate a Purchase Request (or Draft Stock Order) for the item.

    ✅ Bulk Data Management

        CSV Import Support: Enable bulk creation via CSV upload for the following entities:

            Inventory Products (including stock levels and pricing).

            Categories (Inventory and Service categories).

            Vendors (Supplier lists).

            Locations (Storage/Bin locations).

            Customers (Client profiles).

    Smart Bundle Application

        Logic: When applying a Bundle (Canned Job) to an Estimate:

            Labor/Fees: Copy 1-to-1 from the bundle.

            Parts: Perform a dynamic lookup using the Bundle Part Description + Estimate Vehicle ID.

                Search Strategy: Query the InventoryVehicleCompatibility table (or string match description).

                Match Found: Swap the generic bundle part for the specific Inventory Item (updating Price, Cost, and SKU).

                No Match: Insert the generic bundle part and flag for manual completion.

Suggestions for Improvement

    Inventory Compatibility: Leverage the existing InventoryVehicleCompatibility model for the "Smart Bundle" logic. String matching generic names (e.g., "Brake Pads") is error-prone. It is better to link Bundles to "Part Types" and search Inventory by "Part Type + Vehicle".

    Import Templates: Provide downloadable CSV templates for each import type in the UI to reduce user errors during bulk uploads.

    Notification Hooks: When a "Purchase Request" is auto-generated during the workflow, trigger a notification to the Parts Manager.

    Validation: For CSV imports, implement a "Dry Run" feature that validates the file (checking for duplicate SKUs or invalid emails) before actually persisting data.

Development Plan

This plan is ready to be added to WORKFLOW_IMPLEMENTATION_PLAN.md or a similar documentation file.
Phase 1: Backend Service Expansion

    Inventory & Data Import (src/Services/ImportExport/)

        Implement InventoryCsvService, CustomerCsvService, VendorCsvService, and LocationCsvService extending the base CsvImportService.

        Endpoint: Create a unified POST /api/import/{entity} endpoint or specific routes for each.

        Validation: Ensure SKU uniqueness for inventory and Email/Phone uniqueness for customers.

    Estimate & Bundle Logic (src/Services/Estimate/)

        Update BundleService::applyToEstimate:

            Inject InventoryVehicleCompatibility repository.

            Add logic to fetch the Estimate's vehicle_id.

            Replace direct item cloning with a "Lookup and Replace" routine for parts.

        Update EstimateService: Ensure save methods accept and persist sku and list_price fields for line items.

    Workorder Automation (src/Services/Workorder/)

        Update WorkorderService::createFromEstimate:

            Inject InventoryPullRequestService and InventoryStockOrderService.

            Add a post-creation loop to check stock levels of all PART items.

            Create the respective Pull Requests or Stock Orders based on the quantity_on_hand vs quantity_required.

Phase 2: Frontend Implementation

    Estimate Form (src/react/views/estimates/EstimateForm.jsx)

        Update the line-item table to include SKU and List Price columns.

        Replace the simple text input for SKU with the Autocomplete component.

        Wire the onSelect event of the Autocomplete to call inventory.service.js (search) and update the row's state with the result.

    Bulk Import UI

        Create a reusable CsvUploadModal component.

        Add "Import CSV" buttons to:

            src/react/views/inventory/InventoryList.jsx

            src/react/views/customers/CustomerList.jsx

            src/react/views/financial/VendorList.jsx

            src/react/views/settings/ModuleSettings.jsx (for Categories/Locations).

Phase 3: Testing & Verification

    Unit Tests:

        Test BundleService with a mock Vehicle and Inventory match to ensure generic parts are swapped correctly.

        Test WorkorderService to ensure Pull Requests are created only when stock is sufficient.

    Integration Tests:

        Verify CSV import with large datasets (100+ rows).

        Verify Estimate creation flow with both manual and auto-selected parts.
