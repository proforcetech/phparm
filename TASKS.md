
# Automotive Repair Shop Management System – Implementation Tasks

The following are the updated tasks to complete the transition of our WordPress plugin to a standalone system. For development purpose the WordPress plug can be found in the directory: arm-main. The files within that directory will need to be referrenced to ensure that the scope and feature set of the standalobe system match as closely as possible.

As each section is complete it will marked as such.

[x] Examine the estimates functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/estimate-review.md for identified follow-ups.)

[x] Examine the invoices functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/invoice-review.md for identified follow-ups.)

[x] Examine the credit accounts functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/credit-review.md for identified follow-ups.)

[x] Examine the vehicle data and management functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/vehicle-review.md for identified follow-ups.)

[x] Examine the warranty claims system functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/warranty-review.md for identified follow-ups.)

[x] Examine the time tracing and technician dashboard functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/time-tracking-review.md for identified follow-ups.)

[x] Examine the settings management functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/settings-review.md for identified follow-ups.)

[x] Examine the inventory management functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/inventory-review.md for identified follow-ups.)

[x] Examine the inventory notifications/alerts functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/inventory-notifications-review.md for identified follow-ups.)

[x] Examine the customer dashboard functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/customer-dashboard-review.md for identified follow-ups.)

[x] Examine the reminder system functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/reminder-review.md for identified follow-ups.)

[x] Examine the appointment functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/appointment-review.md for identified follow-ups.)

[x] Examine the appointment availability functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/appointment-availability-review.md for identified follow-ups.)

[x] Examine the Time Logs functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/time-logs-review.md for identified follow-ups.)

[x] Examine the Purchases, Expenses and basic accounting functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/purchases-expenses-review.md for identified follow-ups.)

[x] Examine the preset bundles functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/preset-bundles-review.md for identified follow-ups.)

[x] Examine the time logs functionality of the existing plugin (located in arm-main) and ensure they exist in the standalone script, both frontend and backend. Create tasks for each missing function. (See docs/time-logs-review.md for identified follow-ups.)

## Vue to React Cutover Tasks

- [x] Remove Vue dependencies from `package.json` (`vue`, `vue-router`, `pinia`, `vue-chartjs`, `@vueup/vue-quill`, `@fullcalendar/vue3`, `@vitejs/plugin-vue`, `@heroicons/vue`).
- [ ] Remove Vue plugin usage from `vite.config.js`.
- [ ] Remove Vue entrypoints and source files: `src/main.js`, `src/App.vue`, `src/router/`, `src/views/**/*.vue`.
- [ ] Remove other Vue-only directories if unused: `src/components/`, `src/composables/`, `src/stores/`, and any Vue-specific CMS assets.
- [ ] Update `index.html` and build config to reference only React entry points.
- [ ] Update backend comments/references that mention Vue SPA in `routes/cms.php` and `routes/api.php`.
- [ ] Run `npm install` to update lockfile after dependency cleanup.
- [ ] Verify `npm run build` and `npm run test:react`.
- [ ] Document final cutover steps and rollback plan.
