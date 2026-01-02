import { createContext, useCallback, useContext, useMemo, useState } from 'react'

import * as vehicleService from '../../services/vehicle.service'

const VehicleContext = createContext(null)

const defaultFilters = {
  customer_id: '',
  search: '',
  year: '',
  make: '',
  model: '',
}

export function VehicleProvider({ children }) {
  const [vehicles, setVehicles] = useState([])
  const [currentVehicle, setCurrentVehicle] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [filters, setFilters] = useState(defaultFilters)

  const [years, setYears] = useState([])
  const [makes, setMakes] = useState([])
  const [models, setModels] = useState([])
  const [engines, setEngines] = useState([])
  const [transmissions, setTransmissions] = useState([])
  const [drives, setDrives] = useState([])

  const filteredVehicles = useMemo(() => {
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
      result = result.filter(
        (vehicle) => vehicle.make?.toLowerCase() === filters.make.toLowerCase()
      )
    }

    if (filters.model) {
      result = result.filter(
        (vehicle) => vehicle.model?.toLowerCase() === filters.model.toLowerCase()
      )
    }

    return result
  }, [filters, vehicles])

  const hasFilters = useMemo(
    () => Boolean(filters.customer_id || filters.search || filters.year || filters.make || filters.model),
    [filters]
  )

  const fetchVehicles = useCallback(
    async (params = {}) => {
      try {
        setLoading(true)
        setError(null)

        const queryParams = {
          ...filters,
          ...params,
        }

        Object.keys(queryParams).forEach((key) => {
          if (!queryParams[key]) delete queryParams[key]
        })

        const response = await vehicleService.listVehicles(queryParams)
        const data = response.data || response || []
        setVehicles(data)

        return data
      } catch (err) {
        setError(err.message || 'Failed to fetch vehicles')
        throw err
      } finally {
        setLoading(false)
      }
    },
    [filters]
  )

  const fetchVehicle = useCallback(async (id) => {
    try {
      setLoading(true)
      setError(null)

      const response = await vehicleService.getVehicle(id)
      const data = response.data || response
      setCurrentVehicle(data)

      return data
    } catch (err) {
      setError(err.message || 'Failed to fetch vehicle')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const createVehicle = useCallback(async (data) => {
    try {
      setLoading(true)
      setError(null)

      const response = await vehicleService.createVehicle(data)
      const newVehicle = response.data || response

      setVehicles((prev) => [...prev, newVehicle])
      setCurrentVehicle(newVehicle)

      return newVehicle
    } catch (err) {
      setError(err.message || 'Failed to create vehicle')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const updateVehicle = useCallback(async (id, data, customerId = data?.customer_id) => {
    try {
      setLoading(true)
      setError(null)

      const response = await vehicleService.updateVehicle(customerId, id, data)
      const updatedVehicle = response.data || response

      setVehicles((prev) =>
        prev.map((vehicle) => (vehicle.id === id ? updatedVehicle : vehicle))
      )

      setCurrentVehicle((prev) => (prev?.id === id ? updatedVehicle : prev))

      return updatedVehicle
    } catch (err) {
      setError(err.message || 'Failed to update vehicle')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const deleteVehicle = useCallback(async (id, customerId) => {
    try {
      setLoading(true)
      setError(null)

      await vehicleService.deleteVehicle(customerId, id)

      setVehicles((prev) => prev.filter((vehicle) => vehicle.id !== id))
      setCurrentVehicle((prev) => (prev?.id === id ? null : prev))
    } catch (err) {
      setError(err.message || 'Failed to delete vehicle')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const fetchYears = useCallback(async () => {
    setYears([])
    return []
  }, [])

  const fetchMakes = useCallback(async () => {
    setMakes([])
    return []
  }, [])

  const fetchModels = useCallback(async () => {
    setModels([])
    return []
  }, [])

  const fetchEngines = useCallback(async () => {
    setEngines([])
    return []
  }, [])

  const setFilter = useCallback((key, value) => {
    setFilters((prev) => ({ ...prev, [key]: value }))
  }, [])

  const clearFilters = useCallback(() => {
    setFilters(defaultFilters)
  }, [])

  const reset = useCallback(() => {
    setVehicles([])
    setCurrentVehicle(null)
    setLoading(false)
    setError(null)
    setFilters(defaultFilters)
    setYears([])
    setMakes([])
    setModels([])
    setEngines([])
    setTransmissions([])
    setDrives([])
  }, [])

  const value = useMemo(
    () => ({
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
      filteredVehicles,
      hasFilters,
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
      reset,
    }),
    [
      clearFilters,
      createVehicle,
      currentVehicle,
      deleteVehicle,
      drives,
      engines,
      error,
      fetchEngines,
      fetchMakes,
      fetchModels,
      fetchVehicle,
      fetchVehicles,
      fetchYears,
      filteredVehicles,
      filters,
      hasFilters,
      loading,
      makes,
      models,
      reset,
      setFilter,
      transmissions,
      updateVehicle,
      vehicles,
      years,
    ]
  )

  return <VehicleContext.Provider value={value}>{children}</VehicleContext.Provider>
}

export function useVehicleStore() {
  const context = useContext(VehicleContext)

  if (!context) {
    throw new Error('useVehicleStore must be used within a VehicleProvider.')
  }

  return context
}
