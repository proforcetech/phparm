import axios from 'axios'

import { getEnv } from '../config/env'
import { getAuthToken, removeAuthToken } from '../utils/secureStorage'

const { apiBaseUrl, buildVariant } = getEnv()

if (buildVariant === 'production' && apiBaseUrl.startsWith('http://')) {
  throw new Error('Production API must use HTTPS')
}

export const api = axios.create({
  baseURL: apiBaseUrl,
  timeout: 30000,
})

export function setApiBaseUrl(url: string): void {
  api.defaults.baseURL = url
}

export function getApiBaseUrl(): string {
  return (api.defaults.baseURL as string) ?? apiBaseUrl
}

api.interceptors.request.use(async (config) => {
  try {
    const token = await getAuthToken()
    if (token) {
      config.headers.set('Authorization', `Bearer ${token}`)
    }
  } catch (err) {
    // SecureStore may be unavailable (e.g. web environment) — proceed without token
    console.warn('Failed to read auth token', err)
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error?.response?.status === 401) {
      try {
        await removeAuthToken()
      } catch (err) {
        console.warn('Failed to remove auth token', err)
      }
    }
    return Promise.reject(error)
  }
)

export async function fetchWithAuth(
  input: RequestInfo | URL,
  init: RequestInit = {}
): Promise<Response> {
  const token = await getAuthToken()
  const headers = new Headers(init.headers)

  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  return fetch(input, {
    ...init,
    headers,
  })
}
