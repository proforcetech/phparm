import { create } from 'zustand'

import workorderService, { WorkorderFilters } from '../services/workorder.service'

/**
 * Work order status values
 */
export const WORKORDER_STATUSES = {
  PENDING: 'pending',
  IN_PROGRESS: 'in_progress',
  ON_HOLD: 'on_hold',
  COMPLETED: 'completed',
  CANCELLED: 'cancelled',
} as const

export type WorkorderStatus = (typeof WORKORDER_STATUSES)[keyof typeof WORKORDER_STATUSES]

/**
 * Priority levels
 */
export const WORKORDER_PRIORITIES = {
  LOW: 'low',
  NORMAL: 'normal',
  HIGH: 'high',
  URGENT: 'urgent',
} as const

export type WorkorderPriority = (typeof WORKORDER_PRIORITIES)[keyof typeof WORKORDER_PRIORITIES]

/**
 * Vehicle type for work orders
 */
export type WorkorderVehicle = {
  id: number
  vin?: string | null
  year?: number | string | null
  make?: string | null
  model?: string | null
  license_plate?: string | null
  display_name?: string | null
}

/**
 * Customer type for work orders
 */
export type WorkorderCustomer = {
  id: number
  name?: string | null
  first_name?: string | null
  last_name?: string | null
  email?: string | null
  phone?: string | null
}

/**
 * Job item within a work order
 */
export type WorkorderJob = {
  id: number
  title: string
  description?: string | null
  status: string
  technician_id?: number | null
  technician_name?: string | null
  labor_hours?: number | null
  labor_rate?: number | null
  customer_status?: string | null
}

/**
 * Full work order type
 */
export type Workorder = {
  id: number
  number: string
  estimate_id?: number | null
  customer_id?: number | null
  vehicle_id?: number | null
  branch_id?: number | null
  status: WorkorderStatus | string
  priority: WorkorderPriority | string
  assigned_technician_id?: number | null
  subtotal?: number | null
  tax?: number | null
  grand_total?: number | null
  internal_notes?: string | null
  customer_notes?: string | null
  due_date?: string | null
  created_at?: string | null
  updated_at?: string | null
  // Enriched data from API
  customer?: WorkorderCustomer | null
  vehicle?: WorkorderVehicle | null
  jobs?: WorkorderJob[]
}

/**
 * Technician job assignment (from time-tracking/technician/jobs)
 */
export type TechnicianJob = {
  id: number
  title: string
  estimate_number: string
  is_mobile: boolean
  customer_name: string
  vehicle_vin?: string | null
  customer_status: string
}

type WorkordersState = {
  workorders: Workorder[]
  selectedWorkorder: Workorder | null
  technicianJobs: TechnicianJob[]
  loading: boolean
  error: string | null
  filters: WorkorderFilters
  // Computed getters
  pendingWorkorders: () => Workorder[]
  inProgressWorkorders: () => Workorder[]
  // Actions
  loadWorkorders: (params?: WorkorderFilters) => Promise<Workorder[]>
  loadWorkorderDetails: (id: number | string) => Promise<Workorder>
  loadTechnicianJobs: () => Promise<TechnicianJob[]>
  refresh: () => Promise<void>
  setFilter: (key: keyof WorkorderFilters, value: string | number | undefined) => void
  clearFilters: () => void
  clearSelectedWorkorder: () => void
  reset: () => void
}

const defaultFilters: WorkorderFilters = {
  status: undefined,
  technician_id: undefined,
  priority: undefined,
  term: undefined,
  limit: 50,
  offset: 0,
}

export const useWorkordersStore = create<WorkordersState>((set, get) => ({
  workorders: [],
  selectedWorkorder: null,
  technicianJobs: [],
  loading: false,
  error: null,
  filters: defaultFilters,

  pendingWorkorders: () => {
    const { workorders } = get()
    return workorders.filter(
      (wo) => wo.status === WORKORDER_STATUSES.PENDING
    )
  },

  inProgressWorkorders: () => {
    const { workorders } = get()
    return workorders.filter(
      (wo) => wo.status === WORKORDER_STATUSES.IN_PROGRESS
    )
  },

  loadWorkorders: async (params = {}) => {
    try {
      set({ loading: true, error: null })

      const queryParams: WorkorderFilters = {
        ...get().filters,
        ...params,
      }

      // Remove undefined/null values
      const cleanParams = Object.fromEntries(
        Object.entries(queryParams).filter(
          ([, value]) => value !== undefined && value !== null && value !== ''
        )
      )

      const response = await workorderService.getWorkorders(cleanParams)
      const data: Workorder[] = response.data?.data ?? response.data ?? []

      set({ workorders: data })
      return data
    } catch (err: unknown) {
      const message =
        err instanceof Error ? err.message : 'Failed to load work orders'
      set({ error: message })
      throw err
    } finally {
      set({ loading: false })
    }
  },

  loadWorkorderDetails: async (id) => {
    try {
      set({ loading: true, error: null })

      const response = await workorderService.getWorkorder(id)
      const data: Workorder = response.data?.data ?? response.data

      set({ selectedWorkorder: data })
      return data
    } catch (err: unknown) {
      const message =
        err instanceof Error ? err.message : 'Failed to load work order details'
      set({ error: message })
      throw err
    } finally {
      set({ loading: false })
    }
  },

  loadTechnicianJobs: async () => {
    try {
      set({ loading: true, error: null })

      const response = await workorderService.getTechnicianJobs()
      const data: TechnicianJob[] = response.data?.data ?? response.data ?? []

      set({ technicianJobs: data })
      return data
    } catch (err: unknown) {
      const message =
        err instanceof Error ? err.message : 'Failed to load assigned jobs'
      set({ error: message })
      throw err
    } finally {
      set({ loading: false })
    }
  },

  refresh: async () => {
    const { filters } = get()
    await get().loadWorkorders(filters)
  },

  setFilter: (key, value) => {
    set((state) => ({
      filters: { ...state.filters, [key]: value },
    }))
  },

  clearFilters: () => {
    set({ filters: defaultFilters })
  },

  clearSelectedWorkorder: () => {
    set({ selectedWorkorder: null })
  },

  reset: () => {
    set({
      workorders: [],
      selectedWorkorder: null,
      technicianJobs: [],
      loading: false,
      error: null,
      filters: defaultFilters,
    })
  },
}))
