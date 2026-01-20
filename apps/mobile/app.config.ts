import type { ConfigContext, ExpoConfig } from 'expo/config'

const apiBaseUrl = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'https://example.com/api'
const buildVariant = process.env.EXPO_PUBLIC_BUILD_VARIANT ?? 'development'

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: 'phparm-mobile',
  slug: 'phparm-mobile',
  scheme: 'phparm',
  version: '0.1.0',
  orientation: 'portrait',
  userInterfaceStyle: 'automatic',
  extra: {
    apiBaseUrl,
    buildVariant,
  },
  ios: {
    supportsTablet: true,
    bundleIdentifier: 'com.phparm.mobile',
  },
  android: {
    package: 'com.phparm.mobile',
  },
})
