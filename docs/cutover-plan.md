# Vue to React Cutover: Final Steps, Rollback, and Risks

This document captures the production cutover checklist for moving from the legacy Vue SPA to the React front end, along with rollback guidance, risks, and required configuration.

## Final Cutover Steps

1. **Pre-cutover validation**
   - Confirm the React build artifacts are produced (`npm run build`) and stored/deployed to the correct hosting location.
   - Verify that the PHP backend endpoints referenced by the React UI are reachable and responding as expected.
   - Ensure runtime environment variables and config values are present (see [Required Configurations](#required-configurations)).
2. **Deploy new front end**
   - Ship the React build output to the target web server or CDN.
   - Ensure `index.html` and static assets are served from the correct location and that cache headers allow for quick rollback.
3. **Switch traffic**
   - Update any routing rules or reverse proxy configs to point the SPA entrypoint to the React build output.
   - Confirm the base path matches the deployed location (for example, if the app is served from a subdirectory).
4. **Post-cutover verification**
   - Load the app in a clean browser session and confirm navigation, authentication, and core workflows.
   - Validate key backend flows: invoices, estimates, inventory, appointments, and settings.
   - Monitor server logs for errors and client errors for at least one business day.

## Rollback Plan

1. **Restore previous front end**
   - Redeploy the last-known-good Vue build (or restore the previous static assets from backup).
   - Restore the prior `index.html` and asset directory.
2. **Revert routing**
   - Update reverse proxy or routing rules to point back to the Vue build entrypoint.
3. **Confirm stability**
   - Verify the Vue SPA loads and core workflows function.
   - Continue monitoring logs for errors or regressions.

## Notable Risks

- **Caching issues:** Stale CDN or browser caches could serve mismatched assets (mitigate with cache-busting and short TTLs during cutover).
- **Base path mismatch:** Incorrect asset base path or router base can break navigation on deployment.
- **API compatibility:** Any missed API mismatches between the React UI and backend routes could surface at runtime.
- **Auth/session handling:** Differences in session handling could impact login and redirect flows.

## Required Configurations

- **Environment variables:** Confirm any front-end runtime configuration (API base URLs, feature flags) is present for the deployment environment.
- **Static asset hosting:** Ensure the web server or CDN serves compiled assets with appropriate headers and compression.
- **Reverse proxy routing:** Confirm the SPA fallback route is configured to serve `index.html` for client-side routing paths.
