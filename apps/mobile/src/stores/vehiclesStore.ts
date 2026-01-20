import { create } from 'zustand'

import * as vehicleService from '../services/vehicle.service'

type Vehicle = Record<string, any>

type VehicleFilters = {
  customer_id: string
  search: string
  year: string
  make: string
  model: string
}

type VehicleState = {
  vehicles: Vehicle[]
  currentVehicle: Vehicle | null
  loading: boolean
  error: string | null
  filters: VehicleFilters
  years: string[]
  makes: string[]
  models: string[]
  engines: string[]
  transmissions: string[]
  drives: string[]
  filteredVehicles: () => Vehicle[]
  hasFilters: () => boolean
  fetchVehicles: (params?: Record<string, unknown>) => Promise<Vehicle[]>
  fetchVehicle: (id: number | string) => Promise<Vehicle>
  createVehicle: (data: Record<string, unknown>) => Promise<Vehicle>
  updateVehicle: (id: number | string, data: Record<string, unknown>, customerId?: number | string) => Promise<Vehicle>
  deleteVehicle: (id: number | string, customerId: number | string) => Promise<void>
  fetchYears: () => Promise<string[]>
  fetchMakes: () => Promise<string[]>
  fetchModels: () => Promise<string[]>
  fetchEngines: () => Promise<string[]>
  setFilter: (key: keyof VehicleFilters, value: string) => void
  clearFilters: () => void
  reset: () => void
}

const defaultFilters: VehicleFilters = {
  customer_id: '',
  search: '',
  year: '',
  make: '',
  model: '',
}

export const useVehicleStore = create<VehicleState>((set, get) => ({
  vehicles: [],
  currentVehicle: null,
  loading: false,
  error: null,
  filters: defaultFilters,
  years: [],
  makes: [],
  models: [],
  engines: [],
  transmissions: [],
  drives: [],
  filteredVehicles: () => {
    const { vehicles, filters } = get()
    let result = vehicles

    if (filters.customer_id) {
      result = result.filter((vehicle) => String(vehicle.customer_id) === String(filters.customer_id))
    }

    if (filters.search) {
      const search = filters.search.toLowerCase()
      result = result.filter(
        (vehicle) =>
          vehicle.vin?.toLowerCase().includes(search) ||
          vehicle.plate?.toLowerCase().includes(search) ||
          vehicle.year?.toString().includes(search) ||
          vehicle.make?.toLowerCase().includes(search) ||
          vehicle.model?.toLowerCase().includes(search)
      )
    }

    if (filters.year) {
      result = result.filter((vehicle) => String(vehicle.year) === String(filters.year))
    }

    if (filters.make) {
      result = result.filter((vehicle) => vehicle.make?.toLowerCase() === filters.make.toLowerCase())
    }

    if (filters.model) {
      result = result.filter((vehicle) => vehicle.model?.toLowerCase() === filters.model.toLowerCase())
    }

    return result
  },
  hasFilters: () => {
    const { filters } = get()
    return Boolean(filters.customer_id || filters.search || filters.year || filters.make || filters.model)
  },
  fetchVehicles: async (params = {}) => {
    try {
      set({ loading: true, error: null })

      const queryParams = {
        ...get().filters,
        ...params,
      }

      Object.keys(queryParams).forEach((key) => {
        if (!queryParams[key as keyof typeof queryParams]) delete queryParams[key as keyof typeof queryParams]
      })

      const response = await vehicleService.listVehicles(queryParams)
      const data = response.data || response || []
      set({ vehicles: data })

      return data
    } catch (err: any) {
      set({ error: err.message || 'Failed to fetch vehicles' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  fetchVehicle: async (id) => {
    try {
      set({ loading: true, error: null })

      const response = await vehicleService.getVehicle(id)
      const data = response.data || response
      set({ currentVehicle: data })

      return data
    } catch (err: any) {
      set({ error: err.message || 'Failed to fetch vehicle' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  createVehicle: async (data) => {
    try {
      set({ loading: true, error: null })

      const response = await vehicleService.createVehicle(data)
      const newVehicle = response.data || response

      set({ vehicles: [...get().vehicles, newVehicle], currentVehicle: newVehicle })

      return newVehicle
    } catch (err: any) {
      set({ error: err.message || 'Failed to create vehicle' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  updateVehicle: async (id, data, customerId = data?.customer_id) => {
    try {
      set({ loading: true, error: null })

      const response = await vehicleService.updateVehicle(customerId, id, data)
      const updatedVehicle = response.data || response

      set({
        vehicles: get().vehicles.map((vehicle) => vehicle.id === id ? updatedVehicle : vehicle),
        currentVehicle: get().currentVehicle?.id === id ? updatedVehicle : get().currentVehicle,
      })

      return updatedVehicle
    } catch (err: any) {
      set({ error: err.message || 'Failed to update vehicle' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  deleteVehicle: async (id, customerId) => {
    try {
      set({ loading: true, error: null })

      await vehicleService.deleteVehicle(customerId, id)

      set({
        vehicles: get().vehicles.filter((vehicle) => vehicle.id !== id),
        currentVehicle: get().currentVehicle?.id === id ? null : get().currentVehicle,
      })
    } catch (err: any) {
      set({ error: err.message || 'Failed to delete vehicle' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  fetchYears: async () => {
    set({ years: [] })
    return []
  },
  fetchMakes: async () => {
    set({ makes: [] })
    return []
  },
  fetchModels: async () => {
    set({ models: [] })
    return []
  },
  fetchEngines: async () => {
    set({ engines: [] })
    return []
  },
  setFilter: (key, value) => {
    set((state) => ({ filters: { ...state.filters, [key]: value } }))
  },
  clearFilters: () => {
    set({ filters: defaultFilters })
  },
  reset: () => {
    set({
      vehicles: [],
      currentVehicle: null,
      loading: false,
      error: null,
      filters: defaultFilters,
      years: [],
      makes: [],
      models: [],
      engines: [],
      transmissions: [],
      drives: [],
    })
  },
}))
