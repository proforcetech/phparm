import { createContext, useCallback, useContext, useMemo, useState } from 'react'

import invoiceService from '../../services/invoice.service'

const InvoiceContext = createContext(null)

const defaultFilters = {
  status: '',
  customer_id: '',
  vehicle_id: '',
  search: '',
  date_from: '',
  date_to: '',
}

const defaultPagination = {
  currentPage: 1,
  pageSize: 50,
  total: 0,
}

export function InvoiceProvider({ children }) {
  const [invoices, setInvoices] = useState([])
  const [currentInvoice, setCurrentInvoice] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [filters, setFilters] = useState(defaultFilters)
  const [pagination, setPagination] = useState(defaultPagination)

  const filteredInvoices = useMemo(() => {
    let result = invoices

    if (filters.status) {
      result = result.filter((invoice) => invoice.status === filters.status)
    }

    if (filters.customer_id) {
      result = result.filter(
        (invoice) => String(invoice.customer_id) === String(filters.customer_id)
      )
    }

    if (filters.vehicle_id) {
      result = result.filter(
        (invoice) => String(invoice.vehicle_id) === String(filters.vehicle_id)
      )
    }

    if (filters.search) {
      const search = filters.search.toLowerCase()
      result = result.filter(
        (invoice) =>
          invoice.invoice_number?.toLowerCase().includes(search) ||
          invoice.customer_name?.toLowerCase().includes(search)
      )
    }

    return result
  }, [filters, invoices])

  const hasFilters = useMemo(
    () =>
      Boolean(
        filters.status ||
          filters.customer_id ||
          filters.vehicle_id ||
          filters.search ||
          filters.date_from ||
          filters.date_to
      ),
    [filters]
  )

  const fetchInvoices = useCallback(
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
          if (!queryParams[key]) delete queryParams[key]
        })

        const response = await invoiceService.getAll(queryParams)
        const data = response.data || response || []
        setInvoices(data)
        return data
      } catch (err) {
        setError(err.message || 'Failed to fetch invoices')
        throw err
      } finally {
        setLoading(false)
      }
    },
    [filters, pagination]
  )

  const fetchInvoice = useCallback(async (id) => {
    try {
      setLoading(true)
      setError(null)

      const response = await invoiceService.getById(id)
      const data = response.data || response
      setCurrentInvoice(data)
      return data
    } catch (err) {
      setError(err.message || 'Failed to fetch invoice')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const createInvoice = useCallback(async (data) => {
    try {
      setLoading(true)
      setError(null)

      const response = await invoiceService.create(data)
      const newInvoice = response.data || response

      setInvoices((prev) => [newInvoice, ...prev])
      setCurrentInvoice(newInvoice)

      return newInvoice
    } catch (err) {
      setError(err.message || 'Failed to create invoice')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const updateInvoice = useCallback(async (id, data) => {
    try {
      setLoading(true)
      setError(null)

      const response = await invoiceService.update(id, data)
      const updatedInvoice = response.data || response

      setInvoices((prev) =>
        prev.map((invoice) => (invoice.id === id ? updatedInvoice : invoice))
      )

      setCurrentInvoice((prev) => (prev?.id === id ? updatedInvoice : prev))

      return updatedInvoice
    } catch (err) {
      setError(err.message || 'Failed to update invoice')
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
    setInvoices([])
    setCurrentInvoice(null)
    setLoading(false)
    setError(null)
    setFilters(defaultFilters)
    setPagination(defaultPagination)
  }, [])

  const value = useMemo(
    () => ({
      invoices,
      currentInvoice,
      loading,
      error,
      filters,
      pagination,
      filteredInvoices,
      hasFilters,
      fetchInvoices,
      fetchInvoice,
      createInvoice,
      updateInvoice,
      setFilter,
      clearFilters,
      setPage,
      reset,
    }),
    [
      clearFilters,
      createInvoice,
      currentInvoice,
      error,
      fetchInvoice,
      fetchInvoices,
      filteredInvoices,
      filters,
      hasFilters,
      invoices,
      loading,
      pagination,
      reset,
      setFilter,
      setPage,
      updateInvoice,
    ]
  )

  return <InvoiceContext.Provider value={value}>{children}</InvoiceContext.Provider>
}

export function useInvoiceStore() {
  const context = useContext(InvoiceContext)

  if (!context) {
    throw new Error('useInvoiceStore must be used within an InvoiceProvider.')
  }

  return context
}
