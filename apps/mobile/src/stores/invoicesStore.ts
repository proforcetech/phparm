import { create } from 'zustand'

import invoiceService from '../services/invoice.service'

type Invoice = Record<string, any>

type InvoiceFilters = {
  status: string
  customer_id: string
  vehicle_id: string
  search: string
  date_from: string
  date_to: string
}

type Pagination = {
  currentPage: number
  pageSize: number
  total: number
}

type InvoiceState = {
  invoices: Invoice[]
  currentInvoice: Invoice | null
  loading: boolean
  error: string | null
  filters: InvoiceFilters
  pagination: Pagination
  filteredInvoices: () => Invoice[]
  hasFilters: () => boolean
  fetchInvoices: (params?: Record<string, unknown>) => Promise<Invoice[]>
  fetchInvoice: (id: number | string) => Promise<Invoice>
  createInvoice: (data: Record<string, unknown>) => Promise<Invoice>
  updateInvoice: (id: number | string, data: Record<string, unknown>) => Promise<Invoice>
  setFilter: (key: keyof InvoiceFilters, value: string) => void
  clearFilters: () => void
  setPage: (page: number) => void
  reset: () => void
}

const defaultFilters: InvoiceFilters = {
  status: '',
  customer_id: '',
  vehicle_id: '',
  search: '',
  date_from: '',
  date_to: '',
}

const defaultPagination: Pagination = {
  currentPage: 1,
  pageSize: 50,
  total: 0,
}

export const useInvoiceStore = create<InvoiceState>((set, get) => ({
  invoices: [],
  currentInvoice: null,
  loading: false,
  error: null,
  filters: defaultFilters,
  pagination: defaultPagination,
  filteredInvoices: () => {
    const { invoices, filters } = get()
    let result = invoices

    if (filters.status) {
      result = result.filter((invoice) => invoice.status === filters.status)
    }

    if (filters.customer_id) {
      result = result.filter((invoice) => String(invoice.customer_id) === String(filters.customer_id))
    }

    if (filters.vehicle_id) {
      result = result.filter((invoice) => String(invoice.vehicle_id) === String(filters.vehicle_id))
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
  },
  hasFilters: () => {
    const { filters } = get()
    return Boolean(
      filters.status ||
        filters.customer_id ||
        filters.vehicle_id ||
        filters.search ||
        filters.date_from ||
        filters.date_to
    )
  },
  fetchInvoices: async (params = {}) => {
    try {
      set({ loading: true, error: null })

      const queryParams = {
        ...get().filters,
        ...params,
        limit: get().pagination.pageSize,
        offset: (get().pagination.currentPage - 1) * get().pagination.pageSize,
      }

      Object.keys(queryParams).forEach((key) => {
        if (!queryParams[key as keyof typeof queryParams]) delete queryParams[key as keyof typeof queryParams]
      })

      const response = await invoiceService.getAll(queryParams)
      const data = response.data || response || []
      set({ invoices: data })
      return data
    } catch (err: any) {
      set({ error: err.message || 'Failed to fetch invoices' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  fetchInvoice: async (id) => {
    try {
      set({ loading: true, error: null })

      const response = await invoiceService.getById(id)
      const data = response.data || response
      set({ currentInvoice: data })
      return data
    } catch (err: any) {
      set({ error: err.message || 'Failed to fetch invoice' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  createInvoice: async (data) => {
    try {
      set({ loading: true, error: null })

      const response = await invoiceService.create(data)
      const newInvoice = response.data || response

      set({ invoices: [newInvoice, ...get().invoices], currentInvoice: newInvoice })

      return newInvoice
    } catch (err: any) {
      set({ error: err.message || 'Failed to create invoice' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  updateInvoice: async (id, data) => {
    try {
      set({ loading: true, error: null })

      const response = await invoiceService.update(id, data)
      const updatedInvoice = response.data || response

      set({
        invoices: get().invoices.map((invoice) =>
          invoice.id === id ? updatedInvoice : invoice
        ),
        currentInvoice: get().currentInvoice?.id === id ? updatedInvoice : get().currentInvoice,
      })

      return updatedInvoice
    } catch (err: any) {
      set({ error: err.message || 'Failed to update invoice' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  setFilter: (key, value) => {
    set((state) => ({
      filters: { ...state.filters, [key]: value },
      pagination: { ...state.pagination, currentPage: 1 },
    }))
  },
  clearFilters: () => {
    set((state) => ({
      filters: defaultFilters,
      pagination: { ...state.pagination, currentPage: 1 },
    }))
  },
  setPage: (page) => {
    set((state) => ({ pagination: { ...state.pagination, currentPage: page } }))
  },
  reset: () => {
    set({
      invoices: [],
      currentInvoice: null,
      loading: false,
      error: null,
      filters: defaultFilters,
      pagination: defaultPagination,
    })
  },
}))
