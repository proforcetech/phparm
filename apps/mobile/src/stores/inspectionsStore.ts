import AsyncStorage from '@react-native-async-storage/async-storage'
import { create } from 'zustand'

import inspectionService, {
  type Inspection,
  type InspectionItem,
  type InspectionItemStatus,
  type InspectionListParams,
  type InspectionStatus,
  type InspectionTemplate,
  type CreateInspectionData,
  type UpdateInspectionData,
  type AddInspectionItemData,
  type UpdateInspectionItemData,
} from '../services/inspection.service'

const DRAFT_STORAGE_KEY = 'inspection_draft'
const AUTO_SAVE_DEBOUNCE_MS = 30000

type InspectionDraft = {
  inspectionId?: number | string
  data: Partial<CreateInspectionData>
  items: Record<string, Partial<InspectionItem>>
  updatedAt: string
}

type InspectionsState = {
  // Data
  inspections: Inspection[]
  currentInspection: Inspection | null
  templates: InspectionTemplate[]
  draft: InspectionDraft | null

  // UI State
  loading: boolean
  error: string | null
  statusFilter: InspectionStatus | 'all'

  // Computed/derived
  filteredInspections: () => Inspection[]
  pendingInspections: () => Inspection[]
  inProgressInspections: () => Inspection[]
  completedInspections: () => Inspection[]
  getProgress: () => { completed: number; total: number; percentage: number }

  // Actions - Inspections
  loadInspections: (params?: InspectionListParams) => Promise<void>
  loadInspection: (id: number | string) => Promise<Inspection | null>
  createInspection: (data: CreateInspectionData) => Promise<Inspection | null>
  updateInspection: (id: number | string, data: UpdateInspectionData) => Promise<void>
  completeInspection: (id: number | string) => Promise<void>

  // Actions - Items
  updateItem: (
    inspectionId: number | string,
    itemId: number | string,
    data: UpdateInspectionItemData
  ) => Promise<void>
  updateItemStatus: (
    inspectionId: number | string,
    itemId: number | string,
    status: InspectionItemStatus
  ) => Promise<void>
  addItemPhoto: (
    inspectionId: number | string,
    itemId: number | string,
    imageUri: string
  ) => Promise<string | null>

  // Actions - Templates
  loadTemplates: () => Promise<void>

  // Actions - Draft
  saveDraft: () => Promise<void>
  loadDraft: () => Promise<void>
  clearDraft: () => Promise<void>
  updateDraftItem: (itemId: string, data: Partial<InspectionItem>) => void

  // Actions - UI
  setStatusFilter: (status: InspectionStatus | 'all') => void
  setError: (error: string | null) => void
  reset: () => void
}

const initialState = {
  inspections: [],
  currentInspection: null,
  templates: [],
  draft: null,
  loading: false,
  error: null,
  statusFilter: 'all' as InspectionStatus | 'all',
}

export const useInspectionsStore = create<InspectionsState>((set, get) => ({
  ...initialState,

  // Computed values
  filteredInspections: () => {
    const { inspections, statusFilter } = get()
    if (statusFilter === 'all') return inspections
    return inspections.filter((i) => i.status === statusFilter)
  },

  pendingInspections: () => {
    return get().inspections.filter((i) => i.status === 'pending')
  },

  inProgressInspections: () => {
    return get().inspections.filter((i) => i.status === 'in_progress')
  },

  completedInspections: () => {
    return get().inspections.filter((i) => i.status === 'completed')
  },

  getProgress: () => {
    const inspection = get().currentInspection
    if (!inspection) return { completed: 0, total: 0, percentage: 0 }

    const total = inspection.items?.length || 0
    const completed = inspection.items?.filter((item) => item.status !== 'pending').length || 0
    const percentage = total > 0 ? Math.round((completed / total) * 100) : 0

    return { completed, total, percentage }
  },

  // Load inspections list
  loadInspections: async (params = {}) => {
    try {
      set({ loading: true, error: null })
      const response = await inspectionService.getInspections(params)
      set({ inspections: response.data || [] })
    } catch (err: any) {
      const message = err.response?.data?.message || err.message || 'Failed to load inspections'
      set({ error: message })
      throw err
    } finally {
      set({ loading: false })
    }
  },

  // Load single inspection
  loadInspection: async (id) => {
    try {
      set({ loading: true, error: null })
      const response = await inspectionService.getInspection(id)
      const inspection = response.data
      set({ currentInspection: inspection })

      // Initialize draft from current inspection
      if (inspection) {
        set({
          draft: {
            inspectionId: inspection.id,
            data: {
              summary: inspection.summary,
              mileage: inspection.mileage,
            },
            items: inspection.items.reduce(
              (acc, item) => ({
                ...acc,
                [item.id]: {
                  status: item.status,
                  notes: item.notes,
                  photos: item.photos,
                },
              }),
              {}
            ),
            updatedAt: new Date().toISOString(),
          },
        })
      }

      return inspection
    } catch (err: any) {
      const message = err.response?.data?.message || err.message || 'Failed to load inspection'
      set({ error: message })
      throw err
    } finally {
      set({ loading: false })
    }
  },

  // Create new inspection
  createInspection: async (data) => {
    try {
      set({ loading: true, error: null })
      const response = await inspectionService.createInspection(data)
      const newInspection = response.data

      if (newInspection) {
        set((state) => ({
          inspections: [newInspection, ...state.inspections],
          currentInspection: newInspection,
        }))
      }

      return newInspection
    } catch (err: any) {
      const message = err.response?.data?.message || err.message || 'Failed to create inspection'
      set({ error: message })
      throw err
    } finally {
      set({ loading: false })
    }
  },

  // Update inspection
  updateInspection: async (id, data) => {
    try {
      set({ loading: true, error: null })
      const response = await inspectionService.updateInspection(id, data)

      if (response.data) {
        set((state) => ({
          inspections: state.inspections.map((i) =>
            i.id === id ? { ...i, ...response.data } : i
          ),
          currentInspection:
            state.currentInspection?.id === id
              ? { ...state.currentInspection, ...response.data }
              : state.currentInspection,
        }))
      }
    } catch (err: any) {
      const message = err.response?.data?.message || err.message || 'Failed to update inspection'
      set({ error: message })
      throw err
    } finally {
      set({ loading: false })
    }
  },

  // Complete inspection
  completeInspection: async (id) => {
    try {
      set({ loading: true, error: null })
      await inspectionService.completeInspection(id)

      set((state) => ({
        inspections: state.inspections.map((i) =>
          i.id === id ? { ...i, status: 'completed' as InspectionStatus } : i
        ),
        currentInspection:
          state.currentInspection?.id === id
            ? { ...state.currentInspection, status: 'completed' as InspectionStatus }
            : state.currentInspection,
      }))

      // Clear draft after completion
      await get().clearDraft()
    } catch (err: any) {
      const message = err.response?.data?.message || err.message || 'Failed to complete inspection'
      set({ error: message })
      throw err
    } finally {
      set({ loading: false })
    }
  },

  // Update inspection item
  updateItem: async (inspectionId, itemId, data) => {
    try {
      // Optimistic update
      set((state) => {
        if (!state.currentInspection) return state

        const updatedItems = state.currentInspection.items.map((item) =>
          item.id === itemId ? { ...item, ...data } : item
        )

        return {
          currentInspection: {
            ...state.currentInspection,
            items: updatedItems,
          },
        }
      })

      // Also update draft
      get().updateDraftItem(String(itemId), data)

      // Sync to server
      await inspectionService.updateInspectionItem(inspectionId, itemId, data)
    } catch (err: any) {
      const message = err.response?.data?.message || err.message || 'Failed to update item'
      set({ error: message })
      // Note: Optimistic update remains even on error for offline support
    }
  },

  // Update item status (convenience method)
  updateItemStatus: async (inspectionId, itemId, status) => {
    await get().updateItem(inspectionId, itemId, { status })
  },

  // Add photo to inspection item
  addItemPhoto: async (inspectionId, itemId, imageUri) => {
    try {
      // Optimistic update - add photo URI locally first
      set((state) => {
        if (!state.currentInspection) return state

        const updatedItems = state.currentInspection.items.map((item) => {
          if (item.id === itemId) {
            return {
              ...item,
              photos: [...(item.photos || []), imageUri],
            }
          }
          return item
        })

        return {
          currentInspection: {
            ...state.currentInspection,
            items: updatedItems,
          },
        }
      })

      // Upload to server
      const response = await inspectionService.uploadItemMedia(inspectionId, itemId, imageUri)

      // Update with actual URL from server if available
      if (response.data?.url && response.data.url !== imageUri) {
        set((state) => {
          if (!state.currentInspection) return state

          const updatedItems = state.currentInspection.items.map((item) => {
            if (item.id === itemId) {
              return {
                ...item,
                photos: item.photos.map((p) => (p === imageUri ? response.data.url : p)),
              }
            }
            return item
          })

          return {
            currentInspection: {
              ...state.currentInspection,
              items: updatedItems,
            },
          }
        })
      }

      return response.data?.url || imageUri
    } catch (err: any) {
      const message = err.response?.data?.message || err.message || 'Failed to upload photo'
      set({ error: message })
      // Photo remains locally even on error for offline support
      return imageUri
    }
  },

  // Load templates
  loadTemplates: async () => {
    try {
      set({ loading: true, error: null })
      const response = await inspectionService.getTemplates()
      set({ templates: response.data || [] })
    } catch (err: any) {
      const message = err.response?.data?.message || err.message || 'Failed to load templates'
      set({ error: message })
      throw err
    } finally {
      set({ loading: false })
    }
  },

  // Save draft to AsyncStorage
  saveDraft: async () => {
    const { draft } = get()
    if (!draft) return

    try {
      await AsyncStorage.setItem(DRAFT_STORAGE_KEY, JSON.stringify(draft))
    } catch (err) {
      console.error('Failed to save draft:', err)
    }
  },

  // Load draft from AsyncStorage
  loadDraft: async () => {
    try {
      const stored = await AsyncStorage.getItem(DRAFT_STORAGE_KEY)
      if (stored) {
        const draft = JSON.parse(stored) as InspectionDraft
        set({ draft })
      }
    } catch (err) {
      console.error('Failed to load draft:', err)
    }
  },

  // Clear draft
  clearDraft: async () => {
    try {
      await AsyncStorage.removeItem(DRAFT_STORAGE_KEY)
      set({ draft: null })
    } catch (err) {
      console.error('Failed to clear draft:', err)
    }
  },

  // Update draft item
  updateDraftItem: (itemId, data) => {
    set((state) => {
      const draft = state.draft || {
        inspectionId: state.currentInspection?.id,
        data: {},
        items: {},
        updatedAt: new Date().toISOString(),
      }

      return {
        draft: {
          ...draft,
          items: {
            ...draft.items,
            [itemId]: {
              ...draft.items[itemId],
              ...data,
            },
          },
          updatedAt: new Date().toISOString(),
        },
      }
    })
  },

  // Set status filter
  setStatusFilter: (status) => {
    set({ statusFilter: status })
  },

  // Set error
  setError: (error) => {
    set({ error })
  },

  // Reset store
  reset: () => {
    set(initialState)
  },
}))

// Auto-save draft periodically
let autoSaveTimeout: ReturnType<typeof setTimeout> | null = null

export const startAutoSave = () => {
  if (autoSaveTimeout) clearInterval(autoSaveTimeout)

  autoSaveTimeout = setInterval(() => {
    const { draft, saveDraft } = useInspectionsStore.getState()
    if (draft) {
      saveDraft()
    }
  }, AUTO_SAVE_DEBOUNCE_MS)
}

export const stopAutoSave = () => {
  if (autoSaveTimeout) {
    clearInterval(autoSaveTimeout)
    autoSaveTimeout = null
  }
}
