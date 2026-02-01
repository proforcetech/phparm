import type { ConfigContext, ExpoConfig } from 'expo/config'

const apiBaseUrl = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'https://example.com/api'
const buildVariant = process.env.EXPO_PUBLIC_BUILD_VARIANT ?? 'development'

// Version management
const APP_VERSION = '1.0.0'
const IOS_BUILD_NUMBER = '1'
const ANDROID_VERSION_CODE = 1

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: buildVariant === 'production' ? 'PHPArm' : `PHPArm (${buildVariant})`,
  slug: 'phparm-mobile',
  scheme: 'phparm',
  version: APP_VERSION,
  orientation: 'portrait',
  userInterfaceStyle: 'dark',

  // App icon - create a 1024x1024 PNG
  icon: './assets/icon.png',

  // Splash screen configuration
  splash: {
    image: './assets/splash.png',
    resizeMode: 'contain',
    backgroundColor: '#0f172a',
  },

  // Additional app configuration
  extra: {
    apiBaseUrl,
    buildVariant,
    eas: {
      projectId: process.env.EAS_PROJECT_ID,
    },
  },

  // Owner for EAS
  owner: process.env.EXPO_OWNER,

  // iOS Configuration
  ios: {
    supportsTablet: true,
    bundleIdentifier: 'com.phparm.mobile',
    buildNumber: IOS_BUILD_NUMBER,
    config: {
      usesNonExemptEncryption: false,
    },
    infoPlist: {
      NSCameraUsageDescription: 'PHPArm needs camera access to take photos for vehicle inspections and damage reports.',
      NSPhotoLibraryUsageDescription: 'PHPArm needs photo library access to attach existing photos to inspections and damage reports.',
      NSPhotoLibraryAddUsageDescription: 'PHPArm needs permission to save inspection photos to your photo library.',
      NSLocationWhenInUseUsageDescription: 'PHPArm uses your location to track job assignments and optimize dispatch.',
      NSFaceIDUsageDescription: 'PHPArm uses Face ID for secure authentication.',
      UIBackgroundModes: ['fetch', 'remote-notification'],
    },
    entitlements: {
      'aps-environment': buildVariant === 'production' ? 'production' : 'development',
    },
  },

  // Android Configuration
  android: {
    package: 'com.phparm.mobile',
    versionCode: ANDROID_VERSION_CODE,
    adaptiveIcon: {
      foregroundImage: './assets/adaptive-icon.png',
      backgroundColor: '#0f172a',
    },
    permissions: [
      'android.permission.CAMERA',
      'android.permission.READ_EXTERNAL_STORAGE',
      'android.permission.WRITE_EXTERNAL_STORAGE',
      'android.permission.ACCESS_FINE_LOCATION',
      'android.permission.ACCESS_COARSE_LOCATION',
      'android.permission.USE_BIOMETRIC',
      'android.permission.USE_FINGERPRINT',
      'android.permission.RECEIVE_BOOT_COMPLETED',
      'android.permission.VIBRATE',
    ],
    googleServicesFile: process.env.GOOGLE_SERVICES_JSON ?? './google-services.json',
  },

  // Web Configuration (for Expo web if needed)
  web: {
    bundler: 'metro',
    favicon: './assets/favicon.png',
  },

  // Expo plugins for native functionality
  plugins: [
    'expo-router',
    'expo-secure-store',
    'expo-local-authentication',
    [
      'expo-image-picker',
      {
        photosPermission: 'PHPArm needs access to your photos for inspections and damage reports.',
        cameraPermission: 'PHPArm needs camera access to take photos for vehicle inspections.',
      },
    ],
    [
      'expo-notifications',
      {
        icon: './assets/notification-icon.png',
        color: '#38bdf8',
        sounds: ['./assets/notification-sound.wav'],
      },
    ],
    [
      'expo-location',
      {
        locationAlwaysAndWhenInUsePermission: 'PHPArm uses your location for job assignments and dispatch optimization.',
      },
    ],
    [
      'expo-sqlite',
      {
        enableFTS: true,
      },
    ],
    [
      'expo-build-properties',
      {
        android: {
          usesCleartextTraffic: buildVariant !== 'production',
          compileSdkVersion: 34,
          targetSdkVersion: 34,
          minSdkVersion: 24,
        },
        ios: {
          deploymentTarget: '14.0',
        },
      },
    ],
  ],

  // Expo updates configuration
  updates: {
    enabled: true,
    fallbackToCacheTimeout: 30000,
    url: `https://u.expo.dev/${process.env.EAS_PROJECT_ID}`,
  },

  // Runtime version for OTA updates
  runtimeVersion: {
    policy: 'appVersion',
  },

  // Experiments
  experiments: {
    typedRoutes: true,
  },
})
