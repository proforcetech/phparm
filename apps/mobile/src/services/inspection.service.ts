import { api } from './api'
import { registerSyncHandler } from './offlineSync'
import { enqueueItem } from '../utils/offlineQueue'
import { isOnline } from '../utils/network'

// Sync handler types for offline queue
const INSPECTION_CREATE = 'inspection_create'
const INSPECTION_UPDATE = 'inspection_update'
const INSPECTION_ITEM_ADD = 'inspection_item_add'
const INSPECTION_ITEM_UPDATE = 'inspection_item_update'
const INSPECTION_COMPLETE = 'inspection_complete'
const INSPECTION_MEDIA_UPLOAD = 'inspection_media_upload'

// Register sync handlers for offline operations
registerSyncHandler(INSPECTION_CREATE, async (payload) => {
  const response = await api.post('/inspections', payload)
  return response.data
})

registerSyncHandler(INSPECTION_UPDATE, async (payload) => {
  const { inspectionId, ...data } = payload
  const response = await api.put(`/inspections/${inspectionId}`, data)
  return response.data
})

registerSyncHandler(INSPECTION_ITEM_ADD, async (payload) => {
  const { inspectionId, ...data } = payload
  const response = await api.post(`/inspections/${inspectionId}/items`, data)
  return response.data
})

registerSyncHandler(INSPECTION_ITEM_UPDATE, async (payload) => {
  const { inspectionId, itemId, ...data } = payload
  const response = await api.put(`/inspections/${inspectionId}/items/${itemId}`, data)
  return response.data
})

registerSyncHandler(INSPECTION_COMPLETE, async (payload) => {
  const { inspectionId, ...data } = payload
  const response = await api.post(`/inspections/${inspectionId}/complete`, data)
  return response.data
})

registerSyncHandler(INSPECTION_MEDIA_UPLOAD, async (payload) => {
  const { inspectionId, itemId, formData } = payload
  const response = await api.post(
    `/inspections/${inspectionId}/items/${itemId}/media`,
    formData,
    {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    }
  )
  return response.data
})

// Type definitions
export type InspectionStatus = 'pending' | 'in_progress' | 'completed' | 'cancelled'

export type InspectionItemStatus = 'pass' | 'fail' | 'na' | 'pending'

export type InspectionCategory =
  | 'exterior'
  | 'interior'
  | 'under_hood'
  | 'under_vehicle'

export type InspectionItem = {
  id: number
  inspection_id: number
  category: InspectionCategory
  name: string
  description?: string
  status: InspectionItemStatus
  notes?: string
  photos: string[]
  sort_order: number
  created_at: string
  updated_at: string
}

export type Inspection = {
  id: number
  workorder_id?: number
  vehicle_id?: number
  customer_id?: number
  technician_id: number
  template_id?: number
  status: InspectionStatus
  summary?: string
  mileage?: number
  items: InspectionItem[]
  items_total: number
  items_completed: number
  created_at: string
  updated_at: string
  completed_at?: string
  vehicle?: {
    id: number
    year: number
    make: string
    model: string
    vin?: string
    license_plate?: string
  }
  customer?: {
    id: number
    name: string
    email?: string
    phone?: string
  }
  technician?: {
    id: number
    name: string
  }
}

export type InspectionTemplate = {
  id: number
  name: string
  description?: string
  categories: {
    category: InspectionCategory
    items: {
      name: string
      description?: string
      sort_order: number
    }[]
  }[]
  created_at: string
  updated_at: string
}

export type InspectionListParams = {
  status?: InspectionStatus
  technician_id?: number
  vehicle_id?: number
  customer_id?: number
  workorder_id?: number
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}

export type CreateInspectionData = {
  workorder_id?: number
  vehicle_id?: number
  customer_id?: number
  template_id?: number
  summary?: string
  mileage?: number
}

export type UpdateInspectionData = {
  status?: InspectionStatus
  summary?: string
  mileage?: number
}

export type AddInspectionItemData = {
  category: InspectionCategory
  name: string
  description?: string
  status?: InspectionItemStatus
  notes?: string
  sort_order?: number
}

export type UpdateInspectionItemData = {
  status?: InspectionItemStatus
  notes?: string
  photos?: string[]
}

// API Service
const inspectionService = {
  /**
   * List inspections with optional filters
   */
  async getInspections(params: InspectionListParams = {}) {
    const response = await api.get<{ success: boolean; data: Inspection[] }>('/inspections', { params })
    return response.data
  },

  /**
   * Get a single inspection by ID
   */
  async getInspection(id: number | string) {
    const response = await api.get<{ success: boolean; data: Inspection }>(`/inspections/${id}`)
    return response.data
  },

  /**
   * Get inspection templates
   */
  async getTemplates() {
    const response = await api.get<{ success: boolean; data: InspectionTemplate[] }>('/inspections/templates')
    return response.data
  },

  /**
   * Get a single template by ID
   */
  async getTemplate(id: number | string) {
    const response = await api.get<{ success: boolean; data: InspectionTemplate }>(`/inspections/templates/${id}`)
    return response.data
  },

  /**
   * Create a new inspection
   * Queues for offline sync if not connected
   */
  async createInspection(data: CreateInspectionData) {
    const online = await isOnline()
    if (!online) {
      const clientToken = `${Date.now()}-${Math.random().toString(16).slice(2)}`
      await enqueueItem(INSPECTION_CREATE, { ...data, clientToken })
      return {
        success: true,
        data: {
          id: `pending_${clientToken}`,
          ...data,
          status: 'pending' as InspectionStatus,
          items: [],
          items_total: 0,
          items_completed: 0,
          created_at: new Date().toISOString(),
          updated_at: new Date().toISOString(),
          _offline: true,
          _clientToken: clientToken,
        } as unknown as Inspection,
        message: 'Inspection queued for sync',
        queued: true,
      }
    }

    const response = await api.post<{ success: boolean; data: Inspection }>('/inspections', data)
    return response.data
  },

  /**
   * Update an inspection
   * Queues for offline sync if not connected
   */
  async updateInspection(id: number | string, data: UpdateInspectionData) {
    const online = await isOnline()
    if (!online) {
      await enqueueItem(INSPECTION_UPDATE, { inspectionId: id, ...data })
      return { success: true, data: null, message: 'Update queued for sync', queued: true }
    }

    const response = await api.put<{ success: boolean; data: Inspection }>(`/inspections/${id}`, data)
    return response.data
  },

  /**
   * Add an item to an inspection
   * Queues for offline sync if not connected
   */
  async addInspectionItem(inspectionId: number | string, data: AddInspectionItemData) {
    const online = await isOnline()
    if (!online) {
      const clientToken = `${Date.now()}-${Math.random().toString(16).slice(2)}`
      await enqueueItem(INSPECTION_ITEM_ADD, { inspectionId, ...data, clientToken })
      return {
        success: true,
        data: {
          id: `pending_${clientToken}`,
          inspection_id: Number(inspectionId),
          ...data,
          status: data.status || 'pending',
          photos: [],
          created_at: new Date().toISOString(),
          updated_at: new Date().toISOString(),
          _offline: true,
        } as unknown as InspectionItem,
        message: 'Item queued for sync',
        queued: true,
      }
    }

    const response = await api.post<{ success: boolean; data: InspectionItem }>(
      `/inspections/${inspectionId}/items`,
      data
    )
    return response.data
  },

  /**
   * Update an inspection item
   * Queues for offline sync if not connected
   */
  async updateInspectionItem(
    inspectionId: number | string,
    itemId: number | string,
    data: UpdateInspectionItemData
  ) {
    const online = await isOnline()
    if (!online) {
      await enqueueItem(INSPECTION_ITEM_UPDATE, { inspectionId, itemId, ...data })
      return { success: true, data: null, message: 'Update queued for sync', queued: true }
    }

    const response = await api.put<{ success: boolean; data: InspectionItem }>(
      `/inspections/${inspectionId}/items/${itemId}`,
      data
    )
    return response.data
  },

  /**
   * Complete an inspection
   * Queues for offline sync if not connected
   */
  async completeInspection(inspectionId: number | string, data: { signature?: string } = {}) {
    const online = await isOnline()
    if (!online) {
      await enqueueItem(INSPECTION_COMPLETE, { inspectionId, ...data })
      return { success: true, data: null, message: 'Completion queued for sync', queued: true }
    }

    const response = await api.post<{ success: boolean; data: Inspection }>(
      `/inspections/${inspectionId}/complete`,
      data
    )
    return response.data
  },

  /**
   * Upload media for an inspection item
   * Uses FormData for file upload
   * Queues for offline sync if not connected (stores base64)
   */
  async uploadItemMedia(
    inspectionId: number | string,
    itemId: number | string,
    imageUri: string,
    mimeType: string = 'image/jpeg'
  ) {
    const online = await isOnline()

    // Create FormData
    const formData = new FormData()
    const filename = `inspection_${inspectionId}_item_${itemId}_${Date.now()}.jpg`

    formData.append('media', {
      uri: imageUri,
      type: mimeType,
      name: filename,
    } as unknown as Blob)

    if (!online) {
      // Store the image URI for later upload
      await enqueueItem(INSPECTION_MEDIA_UPLOAD, {
        inspectionId,
        itemId,
        imageUri,
        mimeType,
        filename,
      })
      return {
        success: true,
        data: { url: imageUri, _offline: true },
        message: 'Media queued for sync',
        queued: true,
      }
    }

    const response = await api.post(
      `/inspections/${inspectionId}/items/${itemId}/media`,
      formData,
      {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      }
    )
    return response.data
  },

  /**
   * Delete an inspection
   */
  async deleteInspection(id: number | string) {
    const response = await api.delete(`/inspections/${id}`)
    return response.data
  },

  /**
   * Delete an inspection item
   */
  async deleteInspectionItem(inspectionId: number | string, itemId: number | string) {
    const response = await api.delete(`/inspections/${inspectionId}/items/${itemId}`)
    return response.data
  },

  /**
   * Delete media from an inspection item
   */
  async deleteItemMedia(inspectionId: number | string, itemId: number | string, mediaUrl: string) {
    const response = await api.delete(`/inspections/${inspectionId}/items/${itemId}/media`, {
      data: { url: mediaUrl },
    })
    return response.data
  },
}

export default inspectionService
