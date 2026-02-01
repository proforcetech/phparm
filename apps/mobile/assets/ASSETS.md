# PHPArm Mobile App Assets

This directory should contain the following assets for app store submission.

## Required Assets

### App Icon
- **icon.png** (1024x1024 px)
  - Main app icon used for iOS and Android
  - Should be square with no transparency
  - No rounded corners (iOS applies them automatically)

### Adaptive Icon (Android)
- **adaptive-icon.png** (1024x1024 px)
  - Foreground layer for Android adaptive icons
  - Should have some padding (safe zone is center 66%)
  - Background color is set in app.config.ts (#0f172a)

### Splash Screen
- **splash.png** (1284x2778 px recommended)
  - Displayed during app loading
  - Should work on dark background (#0f172a)
  - Center the logo/branding

### Notification Icon (Android)
- **notification-icon.png** (96x96 px)
  - Used for push notification icons on Android
  - Should be a simple silhouette (white on transparent)
  - Android will apply the accent color (#38bdf8)

### Web Favicon (Optional)
- **favicon.png** (48x48 px)
  - Used if running in web/PWA mode

## Design Guidelines

### Colors
- Primary Background: #0f172a (Slate 900)
- Accent Color: #38bdf8 (Sky 400)
- Success: #22c55e (Green 500)
- Error: #ef4444 (Red 500)

### Brand
- App Name: PHPArm
- Tagline: Auto Repair Shop Management
