# PHPArm Mobile App Release Guide

This guide covers releasing the PHPArm mobile app to TestFlight (iOS) and Google Play Console (Android).

## Prerequisites

### 1. Install EAS CLI
```bash
npm install -g eas-cli
eas login
```

### 2. Initialize EAS Project (First Time Only)
```bash
cd apps/mobile
eas init
```

### 3. Configure Environment
```bash
cp .env.example .env
# Edit .env with your values
```

## Build Profiles

| Profile | Use Case | Output |
|---------|----------|--------|
| `development` | Local dev with simulators | Internal |
| `preview` | Internal testing | APK/IPA |
| `production` | App store submission | AAB/IPA |

## iOS Release (TestFlight)

```bash
# Build for TestFlight
npm run build:prod:ios

# Submit to TestFlight
npm run submit:ios
```

## Android Release (Play Console)

```bash
# Build for Play Store
npm run build:prod:android

# Submit to Play Console
npm run submit:android
```

## OTA Updates

Push JavaScript updates without a new app store release:

```bash
npm run update:preview   # Preview channel
npm run update:prod      # Production channel
```

## Version Management

Update in `app.config.ts`:
```typescript
const APP_VERSION = '1.0.0'      // Semantic version
const IOS_BUILD_NUMBER = '1'     // iOS build number
const ANDROID_VERSION_CODE = 1   // Android version code
```

## Required Before Release

- [ ] Replace placeholder assets in `assets/`
- [ ] Configure production API URL
- [ ] Set up Apple/Google developer accounts
- [ ] Test all features on both platforms
- [ ] Prepare app store listing content
