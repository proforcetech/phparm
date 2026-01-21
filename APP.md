App Development Plan

Target Audience: Technicians (Mechanics) & Drivers (Tow/Transport) Recommended Tech Stack: React Native (Expo recommended for rapid development).

    Justification: You are already using React (src/react) and Tailwind CSS. React Native allows you to share significant business logic, API services, and potentially UI styling/components with your existing web frontend.

1. [x] Authentication & Security

    Endpoint: /api/auth/login (JWT based on JwtService.php)

    Features:

        Persistent Login: Store JWT in SecureStore/Keychain.

        Biometrics: specific to mobile (FaceID/Fingerprint) for quick re-entry without re-typing passwords.

        Offline Access: Allow app entry without network if a valid token exists (cached mode).

    Role-Based Access:

        Check user.roles on login to toggle between "Technician Interface" and "Driver Interface" (referencing RolePermissions.php).

2. Technician Module

    Time Tracking (Priority):

        API: /api/time-tracking/* (Clock In/Out, Break, Job Start/Stop).

        Feature: Big, easy-to-hit buttons. Geolocation capture on clock-in (if required).

    Work Orders:

        API: /api/workorders/assigned

        View: List of assigned jobs with status indicators (Pending, In Progress).

        Detail View: Vehicle info (VIN, Year/Make/Model), Customer Concern, Service History.

    Digital Inspections:

        API: /api/inspections

        UI: Convert the web-based checklist (src/react/views/inspections/TechnicianInspections.jsx) into a touch-friendly mobile form.

        Media: Camera integration for taking photos of damage/repairs directly into the report.

    Inventory & Parts:

        Barcode Scanner: Use device camera to scan part UPCs or Bin Locations (Port logic from BarcodeScanner.jsx).

        Parts Request: "Request Part" button on the Work Order screen.

3. [x] Driver/Dispatch Module

    Job Management:

        API: /api/dispatch/jobs (Referencing DriverDispatchController.php).

        Flow: "Job Offer" push notification -> Accept/Decline -> Job Details.

    Workflow Status:

        Buttons for: En Route -> Arrived -> Hooked -> Dropped.

    Navigation:

        "Navigate" button launching Google Maps/Apple Maps/Waze with coordinates.

    Damage Reporting:

        API: /api/jobs/damage-report

        Feature: Mandatory photo upload sequence (4 corners of vehicle) before towing (Referencing JobDamageReport.php).

4. [x] Offline Synchronization (Critical)

    Architecture: Implement an "Offline Queue" similar to src/react/services/offlineSync.js.

    Strategy:

        Read: Cache Work Orders, Inventory, and Customer data to local SQLite/Realm database.

        Write: When offline, save actions (e.g., "Complete Inspection") to the local queue.

        Sync: Background worker detects connection restoration and flushes the queue to the API.

5. [x] Push Notifications

    Service: Firebase Cloud Messaging (FCM).

    Backend: Update DriverPushTokenService.php to handle device token registration from the app.

    Triggers:

        New Job Offer (Driver).

        Work Order Assigned (Technician).

        Chat Message Received (MessagingService.php).

6. Customer Facing Features (Phase 2)

    Approvals: Push notification when an estimate is ready. "Approve" button digitally signs the estimate.

    Payments: Native Apple Pay/Google Pay integration hitting the StripeGateway.php or SquareGateway.php endpoints.

Implementation Roadmap

- [x] Setup: Initialize Expo project + Zustand stores mirroring `src/react/stores/`.
- [x] API Bridge: Port `src/react/services/*.js` to mobile, with JWT injection in axios/fetch interceptors.
- [ ] Technician MVP: Login -> Time Clock -> View Jobs.
- [ ] Driver MVP: Login -> View Dispatch List -> Status Updates.
- [ ] Media & Offline: Implement camera capture and offline queueing (SQLite).
- [ ] Release: TestFlight (iOS) and Play Console (Android).
