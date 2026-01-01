# Vue-to-React Migration Checklist

## Routes (from `src/router/index.js`)
- `/login` → `Login` → `src/views/auth/Login.vue`
- `/customer-login` → `CustomerLogin` → `src/views/auth/CustomerLogin.vue`
- `/forgot-password` → `ForgotPassword` → `src/views/auth/ForgotPassword.vue`
- `/reset-password/:token` → `ResetPassword` → `src/views/auth/ResetPassword.vue`
- `/cp/register` → `Register` → `src/views/auth/Register.vue`
- `/cp/dashboard` → `Dashboard` → `src/views/dashboard/AdminDashboard.vue`
- `/cp/profile` → `StaffProfile` → `src/views/users/Profile.vue`
- `/cp/invoices` → `InvoiceList` → `src/views/invoices/InvoiceList.vue`
- `/cp/invoices/:id` → `InvoiceDetail` → `src/views/invoices/InvoiceDetail.vue`
- `/cp/invoices/create` → `InvoiceCreate` → `src/views/invoices/InvoiceCreate.vue`
- `/cp/estimates` → `EstimateList` → `src/views/estimates/EstimateList.vue`
- `/cp/estimates/create` → `EstimateCreate` → `src/views/estimates/EstimateCreate.vue`
- `/cp/estimates/:id` → `EstimateDetail` → `src/views/estimates/EstimateDetail.vue`
- `/cp/estimates/:id/edit` → `EstimateEdit` → `src/views/estimates/EstimateCreate.vue`
- `/cp/workorders` → `WorkorderList` → `src/views/workorders/WorkorderList.vue`
- `/cp/workorders/:id` → `WorkorderDetail` → `src/views/workorders/WorkorderDetail.vue`
- `/cp/bundles` → `BundleList` → `src/views/bundles/BundleList.vue`
- `/cp/bundles/create` → `BundleCreate` → `src/views/bundles/BundleForm.vue`
- `/cp/bundles/:id/edit` → `BundleEdit` → `src/views/bundles/BundleForm.vue`
- `/cp/appointments` → `AppointmentList` → `src/views/appointments/AppointmentList.vue`
- `/cp/appointments/calendar` → `AppointmentCalendar` → `src/views/appointments/AppointmentCalendar.vue`
- `/cp/time-logs` → `TimeLogs` → `src/views/time/TimeLogs.vue`
- `/cp/my-time` → `TechnicianTime` → `src/views/time/TechnicianPortal.vue`
- `/cp/appointments/create` → `AppointmentBook` → `src/views/appointments/AppointmentBook.vue`
- `/cp/appointments/availability-settings` → `AvailabilitySettings` → `src/views/appointments/AvailabilitySettings.vue`
- `/cp/customers` → `CustomerList` → `src/views/customers/CustomerList.vue`
- `/cp/customers/create` → `CustomerCreate` → `src/views/customers/CustomerForm.vue`
- `/cp/customers/:id` → `CustomerDetail` → `src/views/customers/CustomerDetail.vue`
- `/customers/:id` → `CustomerPublicDetail` → `src/views/customers/CustomerDetail.vue`
- `/cp/vehicle-master` → `VehicleMasterList` → `src/views/vehicle-master/VehicleMasterList.vue`
- `/cp/vehicle-master/create` → `VehicleMasterCreate` → `src/views/vehicle-master/VehicleMasterForm.vue`
- `/cp/vehicle-master/:id/edit` → `VehicleMasterEdit` → `src/views/vehicle-master/VehicleMasterForm.vue`
- `/cp/vehicles` → `VehicleList` → `src/views/vehicles/VehicleList.vue`
- `/cp/vehicles/create` → `VehicleCreate` → `src/views/vehicles/VehicleForm.vue`
- `/cp/vehicles/:id/edit` → `VehicleEdit` → `src/views/vehicles/VehicleForm.vue`
- `/cp/vehicles/:id` → `VehicleDetail` → `src/views/vehicles/VehicleDetail.vue`
- `/vehicles/:id` → `VehiclePublicDetail` → `src/views/vehicles/VehicleDetail.vue`
- `/vehicles/:id/edit` → `VehiclePublicEdit` → `src/views/vehicles/VehicleForm.vue`
- `/cp/inventory` → `InventoryList` → `src/views/inventory/InventoryList.vue`
- `/cp/inventory/categories` → `InventoryCategories` → `src/views/inventory/InventoryLookupManager.vue`
- `/cp/inventory/vendors` → `InventoryVendors` → `src/views/inventory/InventoryLookupManager.vue`
- `/cp/inventory/locations` → `InventoryLocations` → `src/views/inventory/InventoryLookupManager.vue`
- `/cp/inventory/create` → `InventoryCreate` → `src/views/inventory/InventoryForm.vue`
- `/cp/inventory/:id/edit` → `InventoryEdit` → `src/views/inventory/InventoryForm.vue`
- `/cp/inventory/alerts` → `InventoryAlerts` → `src/views/inventory/InventoryAlerts.vue`
- `/cp/inventory/pull-requests` → `InventoryPullRequests` → `src/views/inventory/PullRequestList.vue`
- `/cp/financial/entries` → `FinancialEntries` → `src/views/financial/FinancialEntries.vue`
- `/cp/financial/vendors` → `FinancialVendors` → `src/views/financial/VendorList.vue`
- `/cp/financial/vendors/create` → `FinancialVendorCreate` → `src/views/financial/VendorForm.vue`
- `/cp/financial/vendors/:id/edit` → `FinancialVendorEdit` → `src/views/financial/VendorForm.vue`
- `/cp/reports` → `FinancialReports` → `src/views/financial/Reports.vue`
- `/cp/settings` (layout) → `src/views/settings/SettingsLayout.vue`
  - `/cp/settings` → `Settings` → `src/views/settings/SettingsShopProfile.vue`
  - `/cp/settings/terms` → `SettingsTerms` → `src/views/settings/SettingsTerms.vue`
  - `/cp/settings/templates` → `SettingsTemplates` → `src/views/settings/SettingsTemplates.vue`
  - `/cp/settings/rejection-reasons` → `SettingsRejectionReasons` → `src/views/settings/SettingsRejectionReasons.vue`
  - `/cp/settings/pricing` → `SettingsPricing` → `src/views/settings/SettingsPricing.vue`
  - `/cp/settings/notifications` → `SettingsNotifications` → `src/views/settings/SettingsNotifications.vue`
  - `/cp/settings/payments` → `SettingsPayments` → `src/views/settings/SettingsPayments.vue`
  - `/cp/settings/integrations` → `SettingsIntegrations` → `src/views/settings/SettingsIntegrations.vue`
  - `/cp/settings/services` → `ServiceTypes` → `src/views/settings/ServiceTypes.vue`
- `/cp/users` → `UsersList` → `src/views/users/UsersList.vue`
- `/cp/users/create` → `UserCreate` → `src/views/users/UserForm.vue`
- `/cp/users/:id` → `UserEdit` → `src/views/users/UserForm.vue`
- `/cp/inspections/templates` → `InspectionTemplates` → `src/views/inspections/TemplateManager.vue`
- `/cp/inspections/work` → `TechnicianInspections` → `src/views/inspections/TechnicianInspections.vue`
- `/cp/cms` → `CMSDashboard` → `src/views/cms/CMSDashboard.vue`
- `/cp/cms/pages` → `CMSPageList` → `src/views/cms/CMSPageList.vue`
- `/cp/cms/pages/create` → `CMSPageCreate` → `src/views/cms/CMSPageForm.vue`
- `/cp/cms/pages/:id` → `CMSPageEdit` → `src/views/cms/CMSPageForm.vue`
- `/cp/cms/categories` → `CMSCategoryList` → `src/views/cms/CMSCategoryList.vue`
- `/cp/cms/categories/create` → `CMSCategoryCreate` → `src/views/cms/CMSCategoryForm.vue`
- `/cp/cms/categories/:id` → `CMSCategoryEdit` → `src/views/cms/CMSCategoryForm.vue`
- `/cp/cms/menus` → `CMSMenuList` → `src/views/cms/CMSMenuList.vue`
- `/cp/cms/menus/create` → `CMSMenuCreate` → `src/views/cms/CMSMenuForm.vue`
- `/cp/cms/menus/:id` → `CMSMenuEdit` → `src/views/cms/CMSMenuForm.vue`
- `/cp/cms/components` → `CMSComponentList` → `src/views/cms/CMSComponentList.vue`
- `/cp/cms/components/create` → `CMSComponentCreate` → `src/views/cms/CMSComponentForm.vue`
- `/cp/cms/components/:id` → `CMSComponentEdit` → `src/views/cms/CMSComponentForm.vue`
- `/cp/cms/templates` → `CMSTemplateList` → `src/views/cms/CMSTemplateList.vue`
- `/cp/cms/templates/create` → `CMSTemplateCreate` → `src/views/cms/CMSTemplateForm.vue`
- `/cp/cms/templates/:id` → `CMSTemplateEdit` → `src/views/cms/CMSTemplateForm.vue`
- `/cp/cms/404-manager` → `NotFoundManager` → `src/views/cms/NotFoundManager.vue`
- `/portal` → `CustomerPortal` → `src/views/customer-portal/Dashboard.vue`
- `/portal/invoices` → `CustomerInvoices` → `src/views/customer-portal/Invoices.vue`
- `/portal/invoices/:id` → `CustomerInvoiceDetail` → `src/views/customer-portal/InvoiceDetail.vue`
- `/portal/credit` → `CustomerCredit` → `src/views/customer-portal/Credit.vue`
- `/portal/appointments` → `CustomerAppointments` → `src/views/customer-portal/Appointments.vue`
- `/portal/inspections` → `CustomerInspections` → `src/views/customer-portal/Inspections.vue`
- `/portal/warranty-claims` → `CustomerWarrantyClaims` → `src/views/customer-portal/WarrantyClaims.vue`
- `/portal/warranty-claims/:id` → `CustomerWarrantyClaimDetail` → `src/views/customer-portal/WarrantyClaimDetail.vue`
- `/portal/vehicles` → `CustomerVehicles` → `src/views/customer-portal/Vehicles.vue`
- `/portal/profile` → `CustomerProfile` → `src/views/customer-portal/Profile.vue`
- `/request-estimate` → `EstimateRequestForm` → `src/views/public/EstimateRequestPage.vue`
- `/estimate/view` → `PublicEstimateView` → `src/views/public/PublicEstimateView.vue`
- `/` → `Home` → `src/views/public/CMSPage.vue`
- `/:pathMatch(.*)*` → `CMSPage` → `src/views/public/CMSPage.vue`

## Referenced view files (`src/views/**`)
- `src/views/appointments/AppointmentBook.vue`
- `src/views/appointments/AppointmentCalendar.vue`
- `src/views/appointments/AppointmentList.vue`
- `src/views/appointments/AvailabilitySettings.vue`
- `src/views/auth/CustomerLogin.vue`
- `src/views/auth/ForgotPassword.vue`
- `src/views/auth/Login.vue`
- `src/views/auth/Register.vue`
- `src/views/auth/ResetPassword.vue`
- `src/views/bundles/BundleForm.vue`
- `src/views/bundles/BundleList.vue`
- `src/views/cms/CMSCategoryForm.vue`
- `src/views/cms/CMSCategoryList.vue`
- `src/views/cms/CMSComponentForm.vue`
- `src/views/cms/CMSComponentList.vue`
- `src/views/cms/CMSDashboard.vue`
- `src/views/cms/CMSMenuForm.vue`
- `src/views/cms/CMSMenuList.vue`
- `src/views/cms/CMSPageForm.vue`
- `src/views/cms/CMSPageList.vue`
- `src/views/cms/CMSTemplateForm.vue`
- `src/views/cms/CMSTemplateList.vue`
- `src/views/cms/NotFoundManager.vue`
- `src/views/customer-portal/Appointments.vue`
- `src/views/customer-portal/Credit.vue`
- `src/views/customer-portal/Dashboard.vue`
- `src/views/customer-portal/InvoiceDetail.vue`
- `src/views/customer-portal/Invoices.vue`
- `src/views/customer-portal/Inspections.vue`
- `src/views/customer-portal/Profile.vue`
- `src/views/customer-portal/Vehicles.vue`
- `src/views/customer-portal/WarrantyClaimDetail.vue`
- `src/views/customer-portal/WarrantyClaims.vue`
- `src/views/customers/CustomerDetail.vue`
- `src/views/customers/CustomerForm.vue`
- `src/views/customers/CustomerList.vue`
- `src/views/dashboard/AdminDashboard.vue`
- `src/views/estimates/EstimateCreate.vue`
- `src/views/estimates/EstimateDetail.vue`
- `src/views/estimates/EstimateList.vue`
- `src/views/financial/FinancialEntries.vue`
- `src/views/financial/Reports.vue`
- `src/views/financial/VendorForm.vue`
- `src/views/financial/VendorList.vue`
- `src/views/inventory/InventoryAlerts.vue`
- `src/views/inventory/InventoryForm.vue`
- `src/views/inventory/InventoryList.vue`
- `src/views/inventory/InventoryLookupManager.vue`
- `src/views/inventory/PullRequestList.vue`
- `src/views/inspections/TechnicianInspections.vue`
- `src/views/inspections/TemplateManager.vue`
- `src/views/invoices/InvoiceCreate.vue`
- `src/views/invoices/InvoiceDetail.vue`
- `src/views/invoices/InvoiceList.vue`
- `src/views/public/CMSPage.vue`
- `src/views/public/EstimateRequestPage.vue`
- `src/views/public/PublicEstimateView.vue`
- `src/views/settings/ServiceTypes.vue`
- `src/views/settings/SettingsIntegrations.vue`
- `src/views/settings/SettingsLayout.vue`
- `src/views/settings/SettingsNotifications.vue`
- `src/views/settings/SettingsPayments.vue`
- `src/views/settings/SettingsPricing.vue`
- `src/views/settings/SettingsRejectionReasons.vue`
- `src/views/settings/SettingsShopProfile.vue`
- `src/views/settings/SettingsTemplates.vue`
- `src/views/settings/SettingsTerms.vue`
- `src/views/time/TechnicianPortal.vue`
- `src/views/time/TimeLogs.vue`
- `src/views/users/Profile.vue`
- `src/views/users/UserForm.vue`
- `src/views/users/UsersList.vue`
- `src/views/vehicle-master/VehicleMasterForm.vue`
- `src/views/vehicle-master/VehicleMasterList.vue`
- `src/views/vehicles/VehicleDetail.vue`
- `src/views/vehicles/VehicleForm.vue`
- `src/views/vehicles/VehicleList.vue`
- `src/views/workorders/WorkorderDetail.vue`
- `src/views/workorders/WorkorderList.vue`

## Reusable components (`src/components/**`)
- `src/components/auth/TwoFactorSetupWizard.vue`
- `src/components/charts/BarChart.vue`
- `src/components/charts/DoughnutChart.vue`
- `src/components/charts/LineChart.vue`
- `src/components/chat/ChatWidget.vue`
- `src/components/domain/AppointmentCard.vue`
- `src/components/domain/CustomerCard.vue`
- `src/components/domain/InvoiceCard.vue`
- `src/components/domain/VehicleCard.vue`
- `src/components/layout/AdminLayout.vue`
- `src/components/layout/CustomerLayout.vue`
- `src/components/layout/Navbar.vue`
- `src/components/layout/Sidebar.vue`
- `src/components/public/EstimateRequestForm.vue`
- `src/components/ui/Alert.vue`
- `src/components/ui/Autocomplete.vue`
- `src/components/ui/Badge.vue`
- `src/components/ui/Button.vue`
- `src/components/ui/Card.vue`
- `src/components/ui/Input.vue`
- `src/components/ui/Loading.vue`
- `src/components/ui/Modal.vue`
- `src/components/ui/Select.vue`
- `src/components/ui/Table.vue`
- `src/components/ui/Textarea.vue`

## Composables (`src/composables/**`)
- `src/composables/useRecaptcha.js`

## Vue-specific dependencies and React equivalents
- `vue` → `react`, `react-dom`
- `vue-router` → `react-router-dom`
- `@vitejs/plugin-vue` → `@vitejs/plugin-react`
- `@vueup/vue-quill` → `react-quill`
- `@fullcalendar/vue3` → `@fullcalendar/react`
- `@heroicons/vue` → `@heroicons/react`
- `vue-chartjs` → `react-chartjs-2`

## Migration plan (strangler vs full cutover)
### Recommendation
Use a **strangler approach** where Vue and React coexist while routes are migrated incrementally. This reduces risk by letting the team port routes in slices (e.g., admin settings first, then customer portal) while keeping the legacy Vue app operational.

### Routing during transition
1. **Keep Vue Router for legacy routes.**
   - Existing Vue routes (all current `src/router/index.js` paths) continue to be served by the Vue SPA.
2. **Mount React for new or migrated routes.**
   - Introduce a React entry point that handles a subset of paths (e.g., `/cp/settings/**` or `/portal/**`) using `react-router-dom`.
3. **Server-side routing split (preferred).**
   - Use the backend router (e.g., PHP `routes` or web server rewrite rules) to serve the React bundle on the new/migrated path prefixes and the Vue bundle on legacy paths.
4. **Fallback strategy if server split is not possible.**
   - Mount React under a new base path (e.g., `/react/*`) and use in-app links from Vue for migrated screens until server routing can be updated.

### PHP entry points: loading the React bundle for staged routes
Use the Vite manifest to select the React entry (`src/react/main.jsx`) for route prefixes that have migrated to React. Keep the existing Vue entry for legacy routes until cutover.

**Build-time output**
- `vite build` writes `dist/manifest.json` with two entries:
  - `main` → Vue SPA entry from `index.html`
  - `react` → React SPA entry from `src/react/main.jsx`

**Production PHP example (router split)**
```php
<?php
$manifest = json_decode(file_get_contents(__DIR__ . '/../dist/manifest.json'), true);

$isReactRoute = str_starts_with($normalizedPath, '/cp/settings')
    || str_starts_with($normalizedPath, '/portal');

$entry = $isReactRoute ? $manifest['react'] : $manifest['main'];

// Render the appropriate root element and bundle tags.
?>
<?php if ($isReactRoute): ?>
  <div id="react-root"></div>
<?php else: ?>
  <div id="app"></div>
<?php endif; ?>

<?php if (!empty($entry['css'])): ?>
  <?php foreach ($entry['css'] as $css): ?>
    <link rel="stylesheet" href="/<?= $css ?>">
  <?php endforeach; ?>
<?php endif; ?>

<script type="module" src="/<?= $entry['file'] ?>"></script>
```

**Development PHP example (Vite dev server)**
```php
<?php
$isReactRoute = str_starts_with($normalizedPath, '/cp/settings')
    || str_starts_with($normalizedPath, '/portal');
?>
<?php if ($isReactRoute): ?>
  <div id="react-root"></div>
  <script type="module" src="http://localhost:3000/@vite/client"></script>
  <script type="module" src="http://localhost:3000/src/react/main.jsx"></script>
<?php else: ?>
  <div id="app"></div>
  <script type="module" src="http://localhost:3000/@vite/client"></script>
  <script type="module" src="http://localhost:3000/src/main.js"></script>
<?php endif; ?>
```

### Phased execution (high level)
1. **Foundation:** add React tooling, shared design system primitives, and API client reuse.
2. **Pilot:** migrate a low-risk route group (e.g., `/cp/settings/**`).
3. **Scale:** migrate larger domains (inventory, invoices, customer portal).
4. **Cutover:** once route parity is complete, remove Vue app and switch all routing to React.

### Dependencies
- **Build tooling:** ensure Vite config supports parallel Vue + React bundles or a single build with multiple entry points.
- **Routing split:** backend or reverse-proxy rule updates to decide which SPA bundle to serve per path.
- **Shared services:** a unified API client, auth/token handling, and shared design tokens to keep UI consistent across frameworks.
- **State integration:** for cross-app navigation, ensure auth/session state is persisted in shared storage (cookies/localStorage).

### Risks
- **Route conflicts:** overlapping path prefixes can cause incorrect bundle delivery if server routing is misconfigured.
- **Auth/guards divergence:** Vue route guards and React route protection can drift; requires strict parity tests.
- **UX inconsistency:** styling and layout differences between Vue and React components during coexistence.
- **Operational complexity:** two build pipelines, two bundles, and deployment coordination.

### Rollback plan
- **Immediate rollback:** revert routing split to serve the Vue bundle for all SPA routes.
- **Feature rollback:** keep Vue routes intact and disable React links/feature flags pointing to migrated pages.
- **Data safety:** no schema changes are required for frontend-only migrations; if API changes are introduced for React, keep backward-compatible endpoints until full cutover is stable.

## Migration checklist
- [ ] Recreate route table in `react-router-dom` (including nested settings routes and auth/role gating).
- [ ] Port each view in the referenced `src/views/**` list to React pages.
- [ ] Convert reusable UI/layout/domain components to React equivalents.
- [ ] Replace composables with React hooks (start with `useRecaptcha`).
- [ ] Swap Vue-specific dependencies with React equivalents (see list above).
- [ ] Verify feature parity for FullCalendar, Quill editor, Chart.js, and Heroicons usage.
- [ ] Validate auth/role navigation guards in React routing layer.
