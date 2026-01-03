# Vue-to-React Migration Checklist

## Routes (from `old-views/src/router/index.js`)
**Canonical Vue source rule:** when a view exists in both `old-views/*` and `old-views/src/views/**`, the top-level `old-views/*` file is the source of truth.
- `/login` → `Login` → `old-views/auth/Login.vue`
- `/customer-login` → `CustomerLogin` → `old-views/auth/CustomerLogin.vue`
- `/forgot-password` → `ForgotPassword` → `old-views/auth/ForgotPassword.vue`
- `/reset-password/:token` → `ResetPassword` → `old-views/auth/ResetPassword.vue`
- `/cp/register` → `Register` → `old-views/auth/Register.vue`
- `/cp/dashboard` → `Dashboard` → `old-views/dashboard/AdminDashboard.vue`
- `/cp/profile` → `StaffProfile` → `old-views/users/Profile.vue`
- `/cp/invoices` → `InvoiceList` → `old-views/invoices/InvoiceList.vue`
- `/cp/invoices/:id` → `InvoiceDetail` → `old-views/invoices/InvoiceDetail.vue`
- `/cp/invoices/create` → `InvoiceCreate` → `old-views/invoices/InvoiceCreate.vue`
- `/cp/estimates` → `EstimateList` → `old-views/estimates/EstimateList.vue`
- `/cp/estimates/create` → `EstimateCreate` → `old-views/estimates/EstimateCreate.vue`
- `/cp/estimates/:id` → `EstimateDetail` → `old-views/estimates/EstimateDetail.vue`
- `/cp/estimates/:id/edit` → `EstimateEdit` → `old-views/estimates/EstimateCreate.vue`
- `/cp/workorders` → `WorkorderList` → `old-views/workorders/WorkorderList.vue`
- `/cp/workorders/:id` → `WorkorderDetail` → `old-views/workorders/WorkorderDetail.vue`
- `/cp/bundles` → `BundleList` → `old-views/bundles/BundleList.vue`
- `/cp/bundles/create` → `BundleCreate` → `old-views/bundles/BundleForm.vue`
- `/cp/bundles/:id/edit` → `BundleEdit` → `old-views/bundles/BundleForm.vue`
- `/cp/appointments` → `AppointmentList` → `old-views/appointments/AppointmentList.vue`
- `/cp/appointments/calendar` → `AppointmentCalendar` → `old-views/appointments/AppointmentCalendar.vue`
- `/cp/time-logs` → `TimeLogs` → `old-views/time/TimeLogs.vue`
- `/cp/my-time` → `TechnicianTime` → `old-views/time/TechnicianPortal.vue`
- `/cp/appointments/create` → `AppointmentBook` → `old-views/appointments/AppointmentBook.vue`
- `/cp/appointments/availability-settings` → `AvailabilitySettings` → `old-views/appointments/AvailabilitySettings.vue`
- `/cp/customers` → `CustomerList` → `old-views/customers/CustomerList.vue`
- `/cp/customers/create` → `CustomerCreate` → `old-views/customers/CustomerForm.vue`
- `/cp/customers/:id` → `CustomerDetail` → `old-views/customers/CustomerDetail.vue`
- `/customers/:id` → `CustomerPublicDetail` → `old-views/customers/CustomerDetail.vue`
- `/cp/vehicle-master` → `VehicleMasterList` → `old-views/vehicle-master/VehicleMasterList.vue`
- `/cp/vehicle-master/create` → `VehicleMasterCreate` → `old-views/vehicle-master/VehicleMasterForm.vue`
- `/cp/vehicle-master/:id/edit` → `VehicleMasterEdit` → `old-views/vehicle-master/VehicleMasterForm.vue`
- `/cp/vehicles` → `VehicleList` → `old-views/vehicles/VehicleList.vue`
- `/cp/vehicles/create` → `VehicleCreate` → `old-views/vehicles/VehicleForm.vue`
- `/cp/vehicles/:id/edit` → `VehicleEdit` → `old-views/vehicles/VehicleForm.vue`
- `/cp/vehicles/:id` → `VehicleDetail` → `old-views/vehicles/VehicleDetail.vue`
- `/vehicles/:id` → `VehiclePublicDetail` → `old-views/vehicles/VehicleDetail.vue`
- `/vehicles/:id/edit` → `VehiclePublicEdit` → `old-views/vehicles/VehicleForm.vue`
- `/cp/inventory` → `InventoryList` → `old-views/inventory/InventoryList.vue`
- `/cp/inventory/categories` → `InventoryCategories` → `old-views/inventory/InventoryLookupManager.vue`
- `/cp/inventory/vendors` → `InventoryVendors` → `old-views/inventory/InventoryLookupManager.vue`
- `/cp/inventory/locations` → `InventoryLocations` → `old-views/inventory/InventoryLookupManager.vue`
- `/cp/inventory/create` → `InventoryCreate` → `old-views/inventory/InventoryForm.vue`
- `/cp/inventory/:id/edit` → `InventoryEdit` → `old-views/inventory/InventoryForm.vue`
- `/cp/inventory/alerts` → `InventoryAlerts` → `old-views/inventory/InventoryAlerts.vue`
- `/cp/inventory/pull-requests` → `InventoryPullRequests` → `old-views/inventory/PullRequestList.vue`
- `/cp/financial/entries` → `FinancialEntries` → `old-views/financial/FinancialEntries.vue`
- `/cp/financial/vendors` → `FinancialVendors` → `old-views/financial/VendorList.vue`
- `/cp/financial/vendors/create` → `FinancialVendorCreate` → `old-views/financial/VendorForm.vue`
- `/cp/financial/vendors/:id/edit` → `FinancialVendorEdit` → `old-views/financial/VendorForm.vue`
- `/cp/reports` → `FinancialReports` → `old-views/financial/Reports.vue`
- `/cp/settings` (layout) → `old-views/settings/SettingsLayout.vue`
  - `/cp/settings` → `Settings` → `old-views/settings/SettingsShopProfile.vue`
  - `/cp/settings/terms` → `SettingsTerms` → `old-views/settings/SettingsTerms.vue`
  - `/cp/settings/templates` → `SettingsTemplates` → `old-views/settings/SettingsTemplates.vue`
  - `/cp/settings/rejection-reasons` → `SettingsRejectionReasons` → `old-views/settings/SettingsRejectionReasons.vue`
  - `/cp/settings/pricing` → `SettingsPricing` → `old-views/settings/SettingsPricing.vue`
  - `/cp/settings/notifications` → `SettingsNotifications` → `old-views/settings/SettingsNotifications.vue`
  - `/cp/settings/payments` → `SettingsPayments` → `old-views/settings/SettingsPayments.vue`
  - `/cp/settings/integrations` → `SettingsIntegrations` → `old-views/settings/SettingsIntegrations.vue`
  - `/cp/settings/services` → `ServiceTypes` → `old-views/settings/ServiceTypes.vue`
- `/cp/users` → `UsersList` → `old-views/users/UsersList.vue`
- `/cp/users/create` → `UserCreate` → `old-views/users/UserForm.vue`
- `/cp/users/:id` → `UserEdit` → `old-views/users/UserForm.vue`
- `/cp/inspections/templates` → `InspectionTemplates` → `old-views/inspections/TemplateManager.vue`
- `/cp/inspections/work` → `TechnicianInspections` → `old-views/inspections/TechnicianInspections.vue`
- `/cp/cms` → `CMSDashboard` → `old-views/cms/CMSDashboard.vue`
- `/cp/cms/pages` → `CMSPageList` → `old-views/cms/CMSPageList.vue`
- `/cp/cms/pages/create` → `CMSPageCreate` → `old-views/cms/CMSPageForm.vue`
- `/cp/cms/pages/:id` → `CMSPageEdit` → `old-views/cms/CMSPageForm.vue`
- `/cp/cms/categories` → `CMSCategoryList` → `old-views/cms/CMSCategoryList.vue`
- `/cp/cms/categories/create` → `CMSCategoryCreate` → `old-views/cms/CMSCategoryForm.vue`
- `/cp/cms/categories/:id` → `CMSCategoryEdit` → `old-views/cms/CMSCategoryForm.vue`
- `/cp/cms/menus` → `CMSMenuList` → `old-views/cms/CMSMenuList.vue`
- `/cp/cms/menus/create` → `CMSMenuCreate` → `old-views/cms/CMSMenuForm.vue`
- `/cp/cms/menus/:id` → `CMSMenuEdit` → `old-views/cms/CMSMenuForm.vue`
- `/cp/cms/components` → `CMSComponentList` → `old-views/cms/CMSComponentList.vue`
- `/cp/cms/components/create` → `CMSComponentCreate` → `old-views/cms/CMSComponentForm.vue`
- `/cp/cms/components/:id` → `CMSComponentEdit` → `old-views/cms/CMSComponentForm.vue`
- `/cp/cms/templates` → `CMSTemplateList` → `old-views/cms/CMSTemplateList.vue`
- `/cp/cms/templates/create` → `CMSTemplateCreate` → `old-views/cms/CMSTemplateForm.vue`
- `/cp/cms/templates/:id` → `CMSTemplateEdit` → `old-views/cms/CMSTemplateForm.vue`
- `/cp/cms/404-manager` → `NotFoundManager` → `old-views/cms/NotFoundManager.vue`
- `/portal` → `CustomerPortal` → `old-views/customer-portal/Dashboard.vue`
- `/portal/invoices` → `CustomerInvoices` → `old-views/customer-portal/Invoices.vue`
- `/portal/invoices/:id` → `CustomerInvoiceDetail` → `old-views/customer-portal/InvoiceDetail.vue`
- `/portal/credit` → `CustomerCredit` → `old-views/customer-portal/Credit.vue`
- `/portal/appointments` → `CustomerAppointments` → `old-views/customer-portal/Appointments.vue`
- `/portal/inspections` → `CustomerInspections` → `old-views/customer-portal/Inspections.vue`
- `/portal/warranty-claims` → `CustomerWarrantyClaims` → `old-views/customer-portal/WarrantyClaims.vue`
- `/portal/warranty-claims/:id` → `CustomerWarrantyClaimDetail` → `old-views/customer-portal/WarrantyClaimDetail.vue`
- `/portal/vehicles` → `CustomerVehicles` → `old-views/customer-portal/Vehicles.vue`
- `/portal/profile` → `CustomerProfile` → `old-views/customer-portal/Profile.vue`
- `/request-estimate` → `EstimateRequestForm` → `old-views/public/EstimateRequestPage.vue`
- `/estimate/view` → `PublicEstimateView` → `old-views/public/PublicEstimateView.vue`
- `/` → `Home` → `old-views/public/CMSPage.vue`
- `/:pathMatch(.*)*` → `CMSPage` → `old-views/public/CMSPage.vue`

## React route progress
- [x] `/portal/invoices` → React `src/react/views/customer-portal/Invoices.jsx` (mounted at `/react/portal/invoices`)

## Referenced view files (`old-views/**`)
- `old-views/appointments/AppointmentBook.vue`
- `old-views/appointments/AppointmentCalendar.vue`
- `old-views/appointments/AppointmentList.vue`
- `old-views/appointments/AvailabilitySettings.vue`
- `old-views/auth/CustomerLogin.vue`
- `old-views/auth/ForgotPassword.vue`
- `old-views/auth/Login.vue`
- `old-views/auth/Register.vue`
- `old-views/auth/ResetPassword.vue`
- `old-views/bundles/BundleForm.vue`
- `old-views/bundles/BundleList.vue`
- `old-views/cms/CMSCategoryForm.vue`
- `old-views/cms/CMSCategoryList.vue`
- `old-views/cms/CMSComponentForm.vue`
- `old-views/cms/CMSComponentList.vue`
- `old-views/cms/CMSDashboard.vue`
- `old-views/cms/CMSMenuForm.vue`
- `old-views/cms/CMSMenuList.vue`
- `old-views/cms/CMSPageForm.vue`
- `old-views/cms/CMSPageList.vue`
- `old-views/cms/CMSTemplateForm.vue`
- `old-views/cms/CMSTemplateList.vue`
- `old-views/cms/NotFoundManager.vue`
- `old-views/customer-portal/Appointments.vue`
- `old-views/customer-portal/Credit.vue`
- `old-views/customer-portal/Dashboard.vue`
- `old-views/customer-portal/InvoiceDetail.vue`
- `old-views/customer-portal/Invoices.vue`
- `old-views/customer-portal/Inspections.vue`
- `old-views/customer-portal/Profile.vue`
- `old-views/customer-portal/Vehicles.vue`
- `old-views/customer-portal/WarrantyClaimDetail.vue`
- `old-views/customer-portal/WarrantyClaims.vue`
- `old-views/customers/CustomerDetail.vue`
- `old-views/customers/CustomerForm.vue`
- `old-views/customers/CustomerList.vue`
- `old-views/dashboard/AdminDashboard.vue`
- `old-views/estimates/EstimateCreate.vue`
- `old-views/estimates/EstimateDetail.vue`
- `old-views/estimates/EstimateList.vue`
- `old-views/financial/FinancialEntries.vue`
- `old-views/financial/Reports.vue`
- `old-views/financial/VendorForm.vue`
- `old-views/financial/VendorList.vue`
- `old-views/inventory/InventoryAlerts.vue`
- `old-views/inventory/InventoryForm.vue`
- `old-views/inventory/InventoryList.vue`
- `old-views/inventory/InventoryLookupManager.vue`
- `old-views/inventory/PullRequestList.vue`
- `old-views/inspections/TechnicianInspections.vue`
- `old-views/inspections/TemplateManager.vue`
- `old-views/invoices/InvoiceCreate.vue`
- `old-views/invoices/InvoiceDetail.vue`
- `old-views/invoices/InvoiceList.vue`
- `old-views/public/CMSPage.vue`
- `old-views/public/EstimateRequestPage.vue`
- `old-views/public/PublicEstimateView.vue`
- `old-views/settings/ServiceTypes.vue`
- `old-views/settings/SettingsIntegrations.vue`
- `old-views/settings/SettingsLayout.vue`
- `old-views/settings/SettingsNotifications.vue`
- `old-views/settings/SettingsPayments.vue`
- `old-views/settings/SettingsPricing.vue`
- `old-views/settings/SettingsRejectionReasons.vue`
- `old-views/settings/SettingsShopProfile.vue`
- `old-views/settings/SettingsTemplates.vue`
- `old-views/settings/SettingsTerms.vue`
- `old-views/time/TechnicianPortal.vue`
- `old-views/time/TimeLogs.vue`
- `old-views/users/Profile.vue`
- `old-views/users/UserForm.vue`
- `old-views/users/UsersList.vue`
- `old-views/vehicle-master/VehicleMasterForm.vue`
- `old-views/vehicle-master/VehicleMasterList.vue`
- `old-views/vehicles/VehicleDetail.vue`
- `old-views/vehicles/VehicleForm.vue`
- `old-views/vehicles/VehicleList.vue`
- `old-views/workorders/WorkorderDetail.vue`
- `old-views/workorders/WorkorderList.vue`

## Canonical source notes
- **Duplicates resolved to top-level:** any view that exists in both `old-views/*` and `old-views/src/views/**` should be sourced from `old-views/*`.

### Exceptions (top-level missing or incomplete)
- `old-views/src/views/settings/SettingsPage.vue` (no top-level equivalent; review required).

### Top-level-only pages (migration priority)
- `old-views/financial/VendorForm.vue`
- `old-views/financial/VendorList.vue`
- `old-views/inventory/PullRequestList.vue`
- `old-views/public/PublicEstimateView.vue`
- `old-views/settings/ServiceTypes.vue`
- `old-views/settings/SettingsIntegrations.vue`
- `old-views/settings/SettingsLayout.vue`
- `old-views/settings/SettingsNotifications.vue`
- `old-views/settings/SettingsPayments.vue`
- `old-views/settings/SettingsPricing.vue`
- `old-views/settings/SettingsRejectionReasons.vue`
- `old-views/settings/SettingsShopProfile.vue`
- `old-views/settings/SettingsTemplates.vue`
- `old-views/settings/SettingsTerms.vue`
- `old-views/users/Profile.vue`
- `old-views/workorders/WorkorderDetail.vue`
- `old-views/workorders/WorkorderList.vue`

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
   - Existing Vue routes (all current `old-views/src/router/index.js` paths) continue to be served by the Vue SPA.
2. **Mount React for new or migrated routes.**
   - Introduce a React entry point that handles a subset of paths (e.g., `/cp/settings/**` or `/portal/**`) using `react-router-dom`.
3. **Server-side routing split (preferred).**
   - Use the backend router (e.g., PHP `routes` or web server rewrite rules) to serve the React bundle on the new/migrated path prefixes and the Vue bundle on legacy paths.
4. **Fallback strategy if server split is not possible.**
   - Mount React under a new base path (e.g., `/react/*`) and use in-app links from Vue for migrated screens until server routing can be updated.

### URL ownership and mapping rules (React + Vue)
- **Vue owns all legacy URLs** until a route is explicitly migrated.
- **React owns `/react/*` during migration** to avoid collisions with Vue.
- **React route mapping mirrors Vue paths** by prefixing the Vue path with `/react`.
  - Example: Vue `/login` → React `/react/login`
  - Example: Vue `/cp/dashboard` → React `/react/cp/dashboard`
- **Auth/public parity rule:** React routes must keep the same `guest` vs `requiresAuth` split as the Vue route metadata, even if the React view is a placeholder.
- **Cutover rule:** once a Vue route is migrated, switch server-side routing so the original Vue URL serves the React bundle, then remove the `/react` alias for that route.

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

### React state approach
**Chosen approach: React Context + hooks for UI-local state (toast/messages).**

**Rationale**
- **Minimal surface area:** no additional dependency while the React surface area remains small.
- **Clear ownership:** UI-scoped state (toasts, panels, modals) maps well to co-located providers and hooks.
- **Incremental migration:** easy to introduce alongside Vue/Pinia stores without forcing a global migration decision.
- **Extensible later:** can wrap or replace with Zustand/Redux once the React portion grows and needs shared tooling.

### CMS embedded widgets (React replacement for `createApp` mounts)
`old-views/public/CMSPage.vue` currently searches for `[data-vue-component]` and mounts Vue apps via `createApp`. The React migration should replace this with a **CMS widget registry + DOM mount manager** so CMS authors can keep embedding widgets inside rendered HTML.

**Recommended design**
- **Registry:** a single `cmsComponentRegistry` mapping string names to React components (e.g., `{ EstimateRequestForm: EstimateRequestForm }`).
- **DOM scan + mount:** after CMS HTML is injected, scan the DOM for mount points and `createRoot` per element, similar to Vue’s current `createApp` usage.
- **Portals (optional):** for overlays/modals that need to render outside the CMS node, mount a portal to `document.body` but keep a local wrapper div inside the CMS content for scoping.
- **Cleanup:** store roots in a map and unmount on route change or CMS page cleanup to avoid leaks.

**Data-attribute mapping (Vue ➜ React)**
- **Primary attribute:** migrate to `data-react-component="EstimateRequestForm"` in CMS content.
- **Compatibility alias:** during transition, allow `data-vue-component` to map to the same registry entries so legacy CMS pages continue to work.
- **Props input:**
  - `data-component-props='{"foo":"bar"}'` (JSON) is parsed and passed to the React component.
  - Additional `data-prop-*` attributes can be merged into props for simple values.
- **Example CMS snippet:**
  ```html
  <div
    data-react-component="EstimateRequestForm"
    data-component-props='{"serviceType":"Brake Repair"}'
  ></div>
  ```
  During migration, the same element can continue to use `data-vue-component="EstimateRequestForm"` and will still resolve to the React registry entry.

**Implementation sketch (React)**
1. Inject CMS HTML into a container with `dangerouslySetInnerHTML`.
2. On `useEffect`, find `[data-react-component], [data-vue-component]`.
3. Resolve the name from `data-react-component || data-vue-component`.
4. Look up the component in the registry; if found, `createRoot(element).render(<Component {...props} />)`.
5. On cleanup, unmount all roots.

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
- [ ] Port each view in the referenced `old-views/**` list to React pages.
- [ ] Convert reusable UI/layout/domain components to React equivalents.
- [ ] Replace composables with React hooks (start with `useRecaptcha`).
- [ ] Swap Vue-specific dependencies with React equivalents (see list above).
- [ ] Verify feature parity for FullCalendar, Quill editor, Chart.js, and Heroicons usage.
- [ ] Validate auth/role navigation guards in React routing layer.
- [ ] Implement CMS widget registry + mount manager to replace Vue `createApp` mounts, including support for `data-react-component` and a `data-vue-component` compatibility alias.
