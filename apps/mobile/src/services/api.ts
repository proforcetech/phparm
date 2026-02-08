import axios from 'axios'

import { getEnv } from '../config/env'
import { getAuthToken, removeAuthToken } from '../utils/secureStorage'

const { apiBaseUrl, buildVariant } = getEnv()

if (buildVariant === 'production' && apiBaseUrl.startsWith('http://')) {
  throw new Error('Production API must use HTTPS')
}

export const api = axios.create({
  baseURL: apiBaseUrl,
})

api.interceptors.request.use(async (config) => {
  const token = await getAuthToken()
  if (token) {
    config.headers = {
      ...config.headers,
      Authorization: `Bearer ${token}`,
    }
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error?.response?.status === 401) {
      await removeAuthToken()
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
