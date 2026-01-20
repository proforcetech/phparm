import Constants from 'expo-constants'

export type AppEnv = {
  apiBaseUrl: string
  buildVariant: string
}

const DEFAULT_ENV: AppEnv = {
  apiBaseUrl: 'https://example.com/api',
  buildVariant: 'development',
}

export function getEnv(): AppEnv {
  const extra = (Constants.expoConfig?.extra ?? Constants.manifest?.extra ?? {}) as Partial<AppEnv>
  return {
    apiBaseUrl: extra.apiBaseUrl ?? DEFAULT_ENV.apiBaseUrl,
    buildVariant: extra.buildVariant ?? DEFAULT_ENV.buildVariant,
  }
}
