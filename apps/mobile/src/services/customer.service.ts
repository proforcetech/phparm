import { api } from './api'
import {
  getCachedEntities,
  getCachedEntity,
  replaceCachedEntities,
  removeCachedEntity,
  upsertCachedEntity,
} from '../utils/localCache'

const extractList = (payload: any) => {
  if (Array.isArray(payload)) {
    return payload
  }
  if (Array.isArray(payload?.data)) {
    return payload.data
  }
  return []
}

export async function listCustomers(params: Record<string, unknown> = {}) {
  try {
    const response = await api.get('/customers', { params })
    const data = response.data
    const items = extractList(data)
    if (items.length > 0) {
      await replaceCachedEntities('customers', items)
    }
    return data
  } catch (error) {
    const cached = await getCachedEntities('customers')
    if (cached.length > 0) {
      return cached
    }
    throw error
  }
}

export async function searchCustomers(query: string) {
  try {
    const response = await api.get('/customers', {
      params: {
        query,
        limit: 10,
      },
    })
    const data = response.data
    const items = extractList(data)
    if (items.length > 0) {
      await replaceCachedEntities('customers', items)
    }
    return data
  } catch (error) {
    const cached = await getCachedEntities('customers')
    if (cached.length > 0) {
      return cached
    }
    throw error
  }
}

export async function getCustomer(id: number | string) {
  try {
    const response = await api.get(`/customers/${id}`)
    const data = response.data
    if (data) {
      await upsertCachedEntity('customers', data)
    }
    return data
  } catch (error) {
    const cached = await getCachedEntity('customers', id)
    if (cached) {
      return cached
    }
    throw error
  }
}

export function getCustomerVehicles(customerId: number | string) {
  return api.get(`/customers/${customerId}/vehicles`).then((r) => r.data)
}

export async function createCustomer(payload: Record<string, unknown>) {
  const response = await api.post('/customers', payload)
  const data = response.data
  if (data) {
    await upsertCachedEntity('customers', data)
  }
  return data
}

export async function updateCustomer(id: number | string, payload: Record<string, unknown>) {
  const response = await api.put(`/customers/${id}`, payload)
  const data = response.data
  if (data) {
    await upsertCachedEntity('customers', data)
  }
  return data
}

export async function deleteCustomer(id: number | string) {
  const response = await api.delete(`/customers/${id}`)
  await removeCachedEntity('customers', id)
  return response.data
}

const customerService = {
  listCustomers,
  searchCustomers,
  getCustomer,
  getCustomerVehicles,
  createCustomer,
  updateCustomer,
  deleteCustomer,
}

export default customerService
