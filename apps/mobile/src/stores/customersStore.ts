import { create } from 'zustand'

import customerService from '../services/customer.service'

type Customer = Record<string, any>

type CustomerFilters = {
  search: string
  status: string
  has_credit: boolean | null
}

type Pagination = {
  currentPage: number
  pageSize: number
  total: number
}

type CustomerState = {
  customers: Customer[]
  currentCustomer: Customer | null
  loading: boolean
  error: string | null
  filters: CustomerFilters
  pagination: Pagination
  filteredCustomers: () => Customer[]
  activeCustomers: () => Customer[]
  hasFilters: () => boolean
  fetchCustomers: (params?: Record<string, unknown>) => Promise<Customer[]>
  fetchCustomer: (id: number | string) => Promise<Customer>
  createCustomer: (data: Record<string, unknown>) => Promise<Customer>
  updateCustomer: (id: number | string, data: Record<string, unknown>) => Promise<Customer>
  deleteCustomer: (id: number | string) => Promise<void>
  setFilter: (key: keyof CustomerFilters, value: string | boolean | null) => void
  clearFilters: () => void
  setPage: (page: number) => void
  reset: () => void
}

const defaultFilters: CustomerFilters = {
  search: '',
  status: '',
  has_credit: null,
}

const defaultPagination: Pagination = {
  currentPage: 1,
  pageSize: 50,
  total: 0,
}

export const useCustomerStore = create<CustomerState>((set, get) => ({
  customers: [],
  currentCustomer: null,
  loading: false,
  error: null,
  filters: defaultFilters,
  pagination: defaultPagination,
  filteredCustomers: () => {
    const { customers, filters } = get()
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
  },
  activeCustomers: () => get().customers.filter((customer) => customer.status === 'active'),
  hasFilters: () => {
    const { filters } = get()
    return Boolean(filters.search || filters.status || filters.has_credit !== null)
  },
  fetchCustomers: async (params = {}) => {
    try {
      set({ loading: true, error: null })

      const queryParams = {
        ...get().filters,
        ...params,
        limit: get().pagination.pageSize,
        offset: (get().pagination.currentPage - 1) * get().pagination.pageSize,
      }

      Object.keys(queryParams).forEach((key) => {
        if (queryParams[key as keyof typeof queryParams] === '' || queryParams[key as keyof typeof queryParams] === null) {
          delete queryParams[key as keyof typeof queryParams]
        }
      })

      const response = await customerService.listCustomers(queryParams)
      const data = response.data || response || []
      set({ customers: data })
      return data
    } catch (err: any) {
      set({ error: err.message || 'Failed to fetch customers' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  fetchCustomer: async (id) => {
    try {
      set({ loading: true, error: null })

      const response = await customerService.getCustomer(id)
      const data = response.data || response
      set({ currentCustomer: data })
      return data
    } catch (err: any) {
      set({ error: err.message || 'Failed to fetch customer' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  createCustomer: async (data) => {
    try {
      set({ loading: true, error: null })

      const response = await customerService.createCustomer(data)
      const newCustomer = response.data || response

      set({ customers: [newCustomer, ...get().customers], currentCustomer: newCustomer })

      return newCustomer
    } catch (err: any) {
      set({ error: err.message || 'Failed to create customer' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  updateCustomer: async (id, data) => {
    try {
      set({ loading: true, error: null })

      const response = await customerService.updateCustomer(id, data)
      const updatedCustomer = response.data || response

      set({
        customers: get().customers.map((customer) =>
          customer.id === id ? updatedCustomer : customer
        ),
        currentCustomer: get().currentCustomer?.id === id ? updatedCustomer : get().currentCustomer,
      })

      return updatedCustomer
    } catch (err: any) {
      set({ error: err.message || 'Failed to update customer' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  deleteCustomer: async (id) => {
    try {
      set({ loading: true, error: null })

      await customerService.deleteCustomer(id)

      set({
        customers: get().customers.filter((customer) => customer.id !== id),
        currentCustomer: get().currentCustomer?.id === id ? null : get().currentCustomer,
      })
    } catch (err: any) {
      set({ error: err.message || 'Failed to delete customer' })
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
      customers: [],
      currentCustomer: null,
      loading: false,
      error: null,
      filters: defaultFilters,
      pagination: defaultPagination,
    })
  },
}))
