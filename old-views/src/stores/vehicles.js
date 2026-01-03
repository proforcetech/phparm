import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import vehicleService from '@/services/vehicle.service'

export const useVehicleStore = defineStore('vehicles', () => {
  const vehicles = ref([])
  const currentVehicle = ref(null)
  const loading = ref(false)
  const error = ref(null)

  // Filters
  const filters = ref({
    customer_id: '',
    search: '',
    year: '',
    make: '',
    model: ''
  })

  // Master data for cascading dropdowns
  const years = ref([])
  const makes = ref([])
  const models = ref([])
  const engines = ref([])
  const transmissions = ref([])
  const drives = ref([])

  // Computed
  const filteredVehicles = computed(() => {
    let result = vehicles.value

    if (filters.value.customer_id) {
      result = result.filter(v => v.customer_id == filters.value.customer_id)
    }

    if (filters.value.search) {
      const search = filters.value.search.toLowerCase()
      result = result.filter(v =>
        v.vin?.toLowerCase().includes(search) ||
        v.plate?.toLowerCase().includes(search) ||
        v.year?.toString().includes(search) ||
        v.make?.toLowerCase().includes(search) ||
        v.model?.toLowerCase().includes(search)
      )
    }

    if (filters.value.year) {
      result = result.filter(v => v.year == filters.value.year)
    }

    if (filters.value.make) {
      result = result.filter(v => v.make?.toLowerCase() === filters.value.make.toLowerCase())
    }

    if (filters.value.model) {
      result = result.filter(v => v.model?.toLowerCase() === filters.value.model.toLowerCase())
    }

    return result
  })

  const hasFilters = computed(() => {
    return !!(filters.value.customer_id || filters.value.search ||
              filters.value.year || filters.value.make || filters.value.model)
  })

  // Actions
  async function fetchVehicles(params = {}) {
    try {
      loading.value = true
      error.value = null

      const queryParams = {
        ...filters.value,
        ...params
      }

      // Remove empty params
      Object.keys(queryParams).forEach(key => {
        if (!queryParams[key]) delete queryParams[key]
      })

      const response = await vehicleService.getVehicles(queryParams)
      vehicles.value = response.data || []

      return vehicles.value
    } catch (err) {
      error.value = err.message || 'Failed to fetch vehicles'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchVehicle(id) {
    try {
      loading.value = true
      error.value = null

      const response = await vehicleService.getVehicle(id)
      currentVehicle.value = response.data

      return currentVehicle.value
    } catch (err) {
      error.value = err.message || 'Failed to fetch vehicle'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createVehicle(data) {
    try {
      loading.value = true
      error.value = null

      const response = await vehicleService.createVehicle(data)
      const newVehicle = response.data

      vehicles.value.push(newVehicle)
      currentVehicle.value = newVehicle

      return newVehicle
    } catch (err) {
      error.value = err.message || 'Failed to create vehicle'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateVehicle(id, data) {
    try {
      loading.value = true
      error.value = null

      const response = await vehicleService.updateVehicle(id, data)
      const updatedVehicle = response.data

      // Update in list
      const index = vehicles.value.findIndex(v => v.id === id)
      if (index !== -1) {
        vehicles.value[index] = updatedVehicle
      }

      // Update current
      if (currentVehicle.value?.id === id) {
        currentVehicle.value = updatedVehicle
      }

      return updatedVehicle
    } catch (err) {
      error.value = err.message || 'Failed to update vehicle'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deleteVehicle(id) {
    try {
      loading.value = true
      error.value = null

      await vehicleService.deleteVehicle(id)

      // Remove from list
      vehicles.value = vehicles.value.filter(v => v.id !== id)

      // Clear current if deleted
      if (currentVehicle.value?.id === id) {
        currentVehicle.value = null
      }
    } catch (err) {
      error.value = err.message || 'Failed to delete vehicle'
      throw err
    } finally {
      loading.value = false
    }
  }

  // Master data methods
  async function fetchYears() {
    try {
      const response = await vehicleService.getYears()
      years.value = response.data || []
      return years.value
    } catch (err) {
      console.error('Failed to fetch years:', err)
      return []
    }
  }

  async function fetchMakes(year) {
    try {
      const response = await vehicleService.getMakes(year)
      makes.value = response.data || []
      return makes.value
    } catch (err) {
      console.error('Failed to fetch makes:', err)
      return []
    }
  }

  async function fetchModels(year, make) {
    try {
      const response = await vehicleService.getModels(year, make)
      models.value = response.data || []
      return models.value
    } catch (err) {
      console.error('Failed to fetch models:', err)
      return []
    }
  }

  async function fetchEngines(year, make, model) {
    try {
      const response = await vehicleService.getEngines(year, make, model)
      engines.value = response.data || []
      return engines.value
    } catch (err) {
      console.error('Failed to fetch engines:', err)
      return []
    }
  }

  function setFilter(key, value) {
    filters.value[key] = value
  }

  function clearFilters() {
    filters.value = {
      customer_id: '',
      search: '',
      year: '',
      make: '',
      model: ''
    }
  }

  function reset() {
    vehicles.value = []
    currentVehicle.value = null
    loading.value = false
    error.value = null
    clearFilters()
    years.value = []
    makes.value = []
    models.value = []
    engines.value = []
    transmissions.value = []
    drives.value = []
  }

  return {
    // State
    vehicles,
    currentVehicle,
    loading,
    error,
    filters,
    years,
    makes,
    models,
    engines,
    transmissions,
    drives,

    // Computed
    filteredVehicles,
    hasFilters,

    // Actions
    fetchVehicles,
    fetchVehicle,
    createVehicle,
    updateVehicle,
    deleteVehicle,
    fetchYears,
    fetchMakes,
    fetchModels,
    fetchEngines,
    setFilter,
    clearFilters,
    reset
  }
})
