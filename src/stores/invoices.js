import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import invoiceService from '@/services/invoice.service'

export const useInvoiceStore = defineStore('invoices', () => {
  const invoices = ref([])
  const currentInvoice = ref(null)
  const loading = ref(false)
  const error = ref(null)

  // Filters
  const filters = ref({
    status: '',
    customer_id: '',
    vehicle_id: '',
    search: '',
    date_from: '',
    date_to: ''
  })

  // Pagination
  const pagination = ref({
    currentPage: 1,
    pageSize: 50,
    total: 0
  })

  // Computed
  const filteredInvoices = computed(() => {
    let result = invoices.value

    if (filters.value.status) {
      result = result.filter(inv => inv.status === filters.value.status)
    }

    if (filters.value.customer_id) {
      result = result.filter(inv => inv.customer_id == filters.value.customer_id)
    }

    if (filters.value.vehicle_id) {
      result = result.filter(inv => inv.vehicle_id == filters.value.vehicle_id)
    }

    if (filters.value.search) {
      const search = filters.value.search.toLowerCase()
      result = result.filter(inv =>
        inv.invoice_number?.toLowerCase().includes(search) ||
        inv.customer_name?.toLowerCase().includes(search)
      )
    }

    return result
  })

  const hasFilters = computed(() => {
    return !!(filters.value.status || filters.value.customer_id ||
              filters.value.vehicle_id || filters.value.search ||
              filters.value.date_from || filters.value.date_to)
  })

  // Actions
  async function fetchInvoices(params = {}) {
    try {
      loading.value = true
      error.value = null

      const queryParams = {
        ...filters.value,
        ...params,
        limit: pagination.value.pageSize,
        offset: (pagination.value.currentPage - 1) * pagination.value.pageSize
      }

      // Remove empty params
      Object.keys(queryParams).forEach(key => {
        if (!queryParams[key]) delete queryParams[key]
      })

      const response = await invoiceService.getInvoices(queryParams)
      invoices.value = response.data || []

      return invoices.value
    } catch (err) {
      error.value = err.message || 'Failed to fetch invoices'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchInvoice(id) {
    try {
      loading.value = true
      error.value = null

      const response = await invoiceService.getInvoice(id)
      currentInvoice.value = response.data

      return currentInvoice.value
    } catch (err) {
      error.value = err.message || 'Failed to fetch invoice'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createInvoice(data) {
    try {
      loading.value = true
      error.value = null

      const response = await invoiceService.createInvoice(data)
      const newInvoice = response.data

      invoices.value.unshift(newInvoice)
      currentInvoice.value = newInvoice

      return newInvoice
    } catch (err) {
      error.value = err.message || 'Failed to create invoice'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateInvoice(id, data) {
    try {
      loading.value = true
      error.value = null

      const response = await invoiceService.updateInvoice(id, data)
      const updatedInvoice = response.data

      // Update in list
      const index = invoices.value.findIndex(inv => inv.id === id)
      if (index !== -1) {
        invoices.value[index] = updatedInvoice
      }

      // Update current
      if (currentInvoice.value?.id === id) {
        currentInvoice.value = updatedInvoice
      }

      return updatedInvoice
    } catch (err) {
      error.value = err.message || 'Failed to update invoice'
      throw err
    } finally {
      loading.value = false
    }
  }

  function setFilter(key, value) {
    filters.value[key] = value
    pagination.value.currentPage = 1 // Reset to first page
  }

  function clearFilters() {
    filters.value = {
      status: '',
      customer_id: '',
      vehicle_id: '',
      search: '',
      date_from: '',
      date_to: ''
    }
    pagination.value.currentPage = 1
  }

  function setPage(page) {
    pagination.value.currentPage = page
  }

  function reset() {
    invoices.value = []
    currentInvoice.value = null
    loading.value = false
    error.value = null
    clearFilters()
    pagination.value.currentPage = 1
  }

  return {
    // State
    invoices,
    currentInvoice,
    loading,
    error,
    filters,
    pagination,

    // Computed
    filteredInvoices,
    hasFilters,

    // Actions
    fetchInvoices,
    fetchInvoice,
    createInvoice,
    updateInvoice,
    setFilter,
    clearFilters,
    setPage,
    reset
  }
})
