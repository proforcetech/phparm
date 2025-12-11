import { defineStore } from 'pinia'
import { ref } from 'vue'
import cmsService from '@/services/cms.service'

export const useCmsMenuStore = defineStore('cmsMenus', () => {
  const menus = ref([])
  const currentMenu = ref(null)
  const drafts = ref({})
  const loading = ref(false)
  const saving = ref(false)
  const error = ref(null)

  function setDraft(id, data) {
    const key = id || 'new'
    drafts.value[key] = { ...data }
  }

  function clearDraft(id) {
    const key = id || 'new'
    delete drafts.value[key]
  }

  function mergeMenu(updated) {
    const index = menus.value.findIndex(menu => menu.id === updated.id)
    if (index !== -1) {
      menus.value[index] = updated
    } else {
      menus.value.push(updated)
    }
  }

  async function fetchMenus(params = {}) {
    try {
      loading.value = true
      error.value = null

      const data = await cmsService.getMenus(params)
      menus.value = data.data || data || []
      return menus.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load menus'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchMenu(id) {
    try {
      loading.value = true
      error.value = null

      const data = await cmsService.getMenu(id)
      currentMenu.value = data.data || data
      return currentMenu.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load menu'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createMenu(payload) {
    try {
      saving.value = true
      error.value = null

      const data = await cmsService.createMenu(payload)
      const created = data.data || data
      mergeMenu(created)
      currentMenu.value = created
      clearDraft('new')
      return created
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create menu'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function updateMenu(id, payload) {
    try {
      saving.value = true
      error.value = null

      const data = await cmsService.updateMenu(id, payload)
      const updated = data.data || data
      mergeMenu(updated)
      currentMenu.value = updated
      clearDraft(id)
      return updated
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update menu'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function publishMenu(id) {
    try {
      saving.value = true
      error.value = null

      const data = await cmsService.publishMenu(id)
      const published = data.data || data
      mergeMenu(published)
      currentMenu.value = published
      clearDraft(id)
      return published
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to publish menu'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function deleteMenu(id) {
    try {
      saving.value = true
      error.value = null

      await cmsService.deleteMenu(id)
      menus.value = menus.value.filter(menu => menu.id !== id)
      if (currentMenu.value?.id === id) {
        currentMenu.value = null
      }
      clearDraft(id)
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to delete menu'
      throw err
    } finally {
      saving.value = false
    }
  }

  return {
    menus,
    currentMenu,
    drafts,
    loading,
    saving,
    error,
    fetchMenus,
    fetchMenu,
    createMenu,
    updateMenu,
    publishMenu,
    deleteMenu,
    setDraft,
    clearDraft,
  }
})

export default useCmsMenuStore
