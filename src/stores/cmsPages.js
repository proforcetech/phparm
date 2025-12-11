import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import cmsService from '@/services/cms.service'

export const useCmsPageStore = defineStore('cmsPages', () => {
  const pages = ref([])
  const currentPage = ref(null)
  const drafts = ref({})
  const loading = ref(false)
  const saving = ref(false)
  const error = ref(null)

  const draftForCurrent = computed(() => {
    const key = currentPage.value?.id || 'new'
    return drafts.value[key]
  })

  function setDraft(id, data) {
    const key = id || 'new'
    drafts.value[key] = { ...data }
  }

  function clearDraft(id) {
    const key = id || 'new'
    delete drafts.value[key]
  }

  function mergePage(updated) {
    const index = pages.value.findIndex(page => page.id === updated.id)
    if (index !== -1) {
      pages.value[index] = updated
    } else {
      pages.value.push(updated)
    }
  }

  async function fetchPages(params = {}) {
    try {
      loading.value = true
      error.value = null

      const data = await cmsService.getPages(params)
      pages.value = data.data || data || []
      return pages.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load pages'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchPage(id) {
    try {
      loading.value = true
      error.value = null

      const data = await cmsService.getPage(id)
      currentPage.value = data.data || data
      return currentPage.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load page'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createPage(payload) {
    try {
      saving.value = true
      error.value = null

      const data = await cmsService.createPage(payload)
      const created = data.data || data
      mergePage(created)
      currentPage.value = created
      clearDraft('new')
      return created
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create page'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function updatePage(id, payload) {
    try {
      saving.value = true
      error.value = null

      const data = await cmsService.updatePage(id, payload)
      const updated = data.data || data
      mergePage(updated)
      currentPage.value = updated
      clearDraft(id)
      return updated
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update page'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function publishPage(id) {
    try {
      saving.value = true
      error.value = null

      const data = await cmsService.publishPage(id)
      const published = data.data || data
      mergePage(published)
      currentPage.value = published
      clearDraft(id)
      return published
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to publish page'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function deletePage(id) {
    try {
      saving.value = true
      error.value = null

      await cmsService.deletePage(id)
      pages.value = pages.value.filter(page => page.id !== id)
      if (currentPage.value?.id === id) {
        currentPage.value = null
      }
      clearDraft(id)
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to delete page'
      throw err
    } finally {
      saving.value = false
    }
  }

  return {
    pages,
    currentPage,
    drafts,
    loading,
    saving,
    error,
    draftForCurrent,
    fetchPages,
    fetchPage,
    createPage,
    updatePage,
    publishPage,
    deletePage,
    setDraft,
    clearDraft,
  }
})

export default useCmsPageStore
