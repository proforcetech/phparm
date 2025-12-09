import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import customerService from '@/services/customer.service'

export const useCustomerStore = defineStore('customers', () => {
  const customers = ref([])
  const currentCustomer = ref(null)
  const loading = ref(false)
  const error = ref(null)

  // Filters
  const filters = ref({
    search: '',
    status: '',
    has_credit: null
  })

  // Pagination
  const pagination = ref({
    currentPage: 1,
    pageSize: 50,
    total: 0
  })

  // Computed
  const filteredCustomers = computed(() => {
    let result = customers.value

    if (filters.value.search) {
      const search = filters.value.search.toLowerCase()
      result = result.filter(c =>
        c.name?.toLowerCase().includes(search) ||
        c.email?.toLowerCase().includes(search) ||
        c.phone?.toLowerCase().includes(search)
      )
    }

    if (filters.value.status) {
      result = result.filter(c => c.status === filters.value.status)
    }

    if (filters.value.has_credit !== null) {
      result = result.filter(c => !!c.credit_account_id === filters.value.has_credit)
    }

    return result
  })

  const activeCustomers = computed(() => {
    return customers.value.filter(c => c.status === 'active')
  })

  const hasFilters = computed(() => {
    return !!(filters.value.search || filters.value.status || filters.value.has_credit !== null)
  })

  // Actions
  async function fetchCustomers(params = {}) {
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
        if (queryParams[key] === '' || queryParams[key] === null) {
          delete queryParams[key]
        }
      })

      const response = await customerService.getCustomers(queryParams)
      customers.value = response.data || []

      return customers.value
    } catch (err) {
      error.value = err.message || 'Failed to fetch customers'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchCustomer(id) {
    try {
      loading.value = true
      error.value = null

      const response = await customerService.getCustomer(id)
      currentCustomer.value = response.data

      return currentCustomer.value
    } catch (err) {
      error.value = err.message || 'Failed to fetch customer'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createCustomer(data) {
    try {
      loading.value = true
      error.value = null

      const response = await customerService.createCustomer(data)
      const newCustomer = response.data

      customers.value.unshift(newCustomer)
      currentCustomer.value = newCustomer

      return newCustomer
    } catch (err) {
      error.value = err.message || 'Failed to create customer'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateCustomer(id, data) {
    try {
      loading.value = true
      error.value = null

      const response = await customerService.updateCustomer(id, data)
      const updatedCustomer = response.data

      // Update in list
      const index = customers.value.findIndex(c => c.id === id)
      if (index !== -1) {
        customers.value[index] = updatedCustomer
      }

      // Update current
      if (currentCustomer.value?.id === id) {
        currentCustomer.value = updatedCustomer
      }

      return updatedCustomer
    } catch (err) {
      error.value = err.message || 'Failed to update customer'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deleteCustomer(id) {
    try {
      loading.value = true
      error.value = null

      await customerService.deleteCustomer(id)

      // Remove from list
      customers.value = customers.value.filter(c => c.id !== id)

      // Clear current if deleted
      if (currentCustomer.value?.id === id) {
        currentCustomer.value = null
      }
    } catch (err) {
      error.value = err.message || 'Failed to delete customer'
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
      search: '',
      status: '',
      has_credit: null
    }
    pagination.value.currentPage = 1
  }

  function setPage(page) {
    pagination.value.currentPage = page
  }

  function reset() {
    customers.value = []
    currentCustomer.value = null
    loading.value = false
    error.value = null
    clearFilters()
    pagination.value.currentPage = 1
  }

  return {
    // State
    customers,
    currentCustomer,
    loading,
    error,
    filters,
    pagination,

    // Computed
    filteredCustomers,
    activeCustomers,
    hasFilters,

    // Actions
    fetchCustomers,
    fetchCustomer,
    createCustomer,
    updateCustomer,
    deleteCustomer,
    setFilter,
    clearFilters,
    setPage,
    reset
  }
})
