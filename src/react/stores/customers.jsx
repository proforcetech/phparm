import { createContext, useCallback, useContext, useMemo, useState } from 'react'

import customerService from '../../services/customer.service'

const CustomerContext = createContext(null)

const defaultFilters = {
  search: '',
  status: '',
  has_credit: null,
}

const defaultPagination = {
  currentPage: 1,
  pageSize: 50,
  total: 0,
}

export function CustomerProvider({ children }) {
  const [customers, setCustomers] = useState([])
  const [currentCustomer, setCurrentCustomer] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [filters, setFilters] = useState(defaultFilters)
  const [pagination, setPagination] = useState(defaultPagination)

  const filteredCustomers = useMemo(() => {
    let result = customers

    if (filters.search) {
      const search = filters.search.toLowerCase()
      result = result.filter(
        (customer) =>
          customer.name?.toLowerCase().includes(search) ||
          customer.email?.toLowerCase().includes(search) ||
          customer.phone?.toLowerCase().includes(search)
      )
    }

    if (filters.status) {
      result = result.filter((customer) => customer.status === filters.status)
    }

    if (filters.has_credit !== null) {
      result = result.filter(
        (customer) => Boolean(customer.credit_account_id) === filters.has_credit
      )
    }

    return result
  }, [customers, filters])

  const activeCustomers = useMemo(
    () => customers.filter((customer) => customer.status === 'active'),
    [customers]
  )

  const hasFilters = useMemo(
    () => Boolean(filters.search || filters.status || filters.has_credit !== null),
    [filters]
  )

  const fetchCustomers = useCallback(
    async (params = {}) => {
      try {
        setLoading(true)
        setError(null)

        const queryParams = {
          ...filters,
          ...params,
          limit: pagination.pageSize,
          offset: (pagination.currentPage - 1) * pagination.pageSize,
        }

        Object.keys(queryParams).forEach((key) => {
          if (queryParams[key] === '' || queryParams[key] === null) {
            delete queryParams[key]
          }
        })

        const response = await customerService.listCustomers(queryParams)
        const data = response.data || response || []
        setCustomers(data)
        return data
      } catch (err) {
        setError(err.message || 'Failed to fetch customers')
        throw err
      } finally {
        setLoading(false)
      }
    },
    [filters, pagination]
  )

  const fetchCustomer = useCallback(async (id) => {
    try {
      setLoading(true)
      setError(null)

      const response = await customerService.getCustomer(id)
      const data = response.data || response
      setCurrentCustomer(data)
      return data
    } catch (err) {
      setError(err.message || 'Failed to fetch customer')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const createCustomer = useCallback(async (data) => {
    try {
      setLoading(true)
      setError(null)

      const response = await customerService.createCustomer(data)
      const newCustomer = response.data || response

      setCustomers((prev) => [newCustomer, ...prev])
      setCurrentCustomer(newCustomer)

      return newCustomer
    } catch (err) {
      setError(err.message || 'Failed to create customer')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const updateCustomer = useCallback(async (id, data) => {
    try {
      setLoading(true)
      setError(null)

      const response = await customerService.updateCustomer(id, data)
      const updatedCustomer = response.data || response

      setCustomers((prev) =>
        prev.map((customer) => (customer.id === id ? updatedCustomer : customer))
      )

      setCurrentCustomer((prev) => (prev?.id === id ? updatedCustomer : prev))

      return updatedCustomer
    } catch (err) {
      setError(err.message || 'Failed to update customer')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const deleteCustomer = useCallback(async (id) => {
    try {
      setLoading(true)
      setError(null)

      await customerService.deleteCustomer(id)

      setCustomers((prev) => prev.filter((customer) => customer.id !== id))
      setCurrentCustomer((prev) => (prev?.id === id ? null : prev))
    } catch (err) {
      setError(err.message || 'Failed to delete customer')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const setFilter = useCallback((key, value) => {
    setFilters((prev) => ({ ...prev, [key]: value }))
    setPagination((prev) => ({ ...prev, currentPage: 1 }))
  }, [])

  const clearFilters = useCallback(() => {
    setFilters(defaultFilters)
    setPagination((prev) => ({ ...prev, currentPage: 1 }))
  }, [])

  const setPage = useCallback((page) => {
    setPagination((prev) => ({ ...prev, currentPage: page }))
  }, [])

  const reset = useCallback(() => {
    setCustomers([])
    setCurrentCustomer(null)
    setLoading(false)
    setError(null)
    setFilters(defaultFilters)
    setPagination(defaultPagination)
  }, [])

  const value = useMemo(
    () => ({
      customers,
      currentCustomer,
      loading,
      error,
      filters,
      pagination,
      filteredCustomers,
      activeCustomers,
      hasFilters,
      fetchCustomers,
      fetchCustomer,
      createCustomer,
      updateCustomer,
      deleteCustomer,
      setFilter,
      clearFilters,
      setPage,
      reset,
    }),
    [
      activeCustomers,
      clearFilters,
      createCustomer,
      currentCustomer,
      customers,
      deleteCustomer,
      error,
      fetchCustomer,
      fetchCustomers,
      filteredCustomers,
      filters,
      hasFilters,
      loading,
      pagination,
      reset,
      setFilter,
      setPage,
      updateCustomer,
    ]
  )

  return <CustomerContext.Provider value={value}>{children}</CustomerContext.Provider>
}

export function useCustomerStore() {
  const context = useContext(CustomerContext)

  if (!context) {
    throw new Error('useCustomerStore must be used within a CustomerProvider.')
  }

  return context
}
