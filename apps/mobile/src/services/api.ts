import AsyncStorage from '@react-native-async-storage/async-storage'
import axios from 'axios'

import { getEnv } from '../config/env'

const { apiBaseUrl } = getEnv()

export const api = axios.create({
  baseURL: apiBaseUrl,
})

api.interceptors.request.use(async (config) => {
  const token = await AsyncStorage.getItem('auth_token')
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
      await AsyncStorage.removeItem('auth_token')
    }
    return Promise.reject(error)
  }
)

export async function fetchWithAuth(
  input: RequestInfo | URL,
  init: RequestInit = {}
): Promise<Response> {
  const token = await AsyncStorage.getItem('auth_token')
  const headers = new Headers(init.headers)

  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  return fetch(input, {
    ...init,
    headers,
  })
}
