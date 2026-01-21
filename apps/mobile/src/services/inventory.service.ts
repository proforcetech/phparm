import { api } from './api'
import {
  getCachedEntities,
  getCachedEntity,
  replaceCachedEntities,
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

const inventoryService = {
  async list(params: Record<string, unknown> = {}) {
    try {
      const response = await api.get('/inventory', { params })
      const data = response.data
      const items = extractList(data)
      if (items.length > 0) {
        await replaceCachedEntities('inventory', items)
      }
      return data
    } catch (error) {
      const cached = await getCachedEntities('inventory')
      if (cached.length > 0) {
        return cached
      }
      throw error
    }
  },

  async get(id: number | string) {
    try {
      const response = await api.get(`/inventory/${id}`)
      const data = response.data
      if (data) {
        await upsertCachedEntity('inventory', data)
      }
      return data
    } catch (error) {
      const cached = await getCachedEntity('inventory', id)
      if (cached) {
        return cached
      }
      throw error
    }
  },

  async searchParts(query: string, limit = 15) {
    try {
      const response = await api.get('/inventory/search-parts', {
        params: { query, limit },
      })
      const data = response.data
      const items = extractList(data)
      if (items.length > 0) {
        await replaceCachedEntities('inventory', items)
      }
      return data
    } catch (error) {
      const cached = await getCachedEntities('inventory')
      if (cached.length > 0) {
        const lowered = query.toLowerCase()
        return cached.filter(
          (item) =>
            item?.name?.toLowerCase?.().includes(lowered) ||
            item?.sku?.toLowerCase?.().includes(lowered)
        )
      }
      throw error
    }
  },
}

export default inventoryService
