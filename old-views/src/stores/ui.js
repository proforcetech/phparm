import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export const useUIStore = defineStore('ui', () => {
  // Sidebar state
  const sidebarOpen = ref(true)
  const sidebarCollapsed = ref(false)

  // Modal state
  const modals = ref({})

  // Notifications/Toast queue (in addition to toast store)
  const notifications = ref([])

  // Loading overlays
  const globalLoading = ref(false)
  const loadingMessage = ref('')

  // Theme
  const theme = ref('light') // 'light' or 'dark'

  // Mobile detection
  const isMobile = ref(false)

  // Computed
  const hasOpenModal = computed(() => {
    return Object.values(modals.value).some(isOpen => isOpen === true)
  })

  const notificationCount = computed(() => {
    return notifications.value.length
  })

  // Actions
  function toggleSidebar() {
    sidebarOpen.value = !sidebarOpen.value
  }

  function openSidebar() {
    sidebarOpen.value = true
  }

  function closeSidebar() {
    sidebarOpen.value = false
  }

  function collapseSidebar() {
    sidebarCollapsed.value = true
  }

  function expandSidebar() {
    sidebarCollapsed.value = false
  }

  function openModal(modalId) {
    modals.value[modalId] = true
  }

  function closeModal(modalId) {
    modals.value[modalId] = false
  }

  function closeAllModals() {
    Object.keys(modals.value).forEach(key => {
      modals.value[key] = false
    })
  }

  function isModalOpen(modalId) {
    return modals.value[modalId] === true
  }

  function addNotification(notification) {
    const id = Date.now()
    notifications.value.push({
      id,
      ...notification,
      timestamp: new Date()
    })

    // Auto-remove after duration (default 5 seconds)
    const duration = notification.duration || 5000
    if (duration > 0) {
      setTimeout(() => {
        removeNotification(id)
      }, duration)
    }

    return id
  }

  function removeNotification(id) {
    notifications.value = notifications.value.filter(n => n.id !== id)
  }

  function clearNotifications() {
    notifications.value = []
  }

  function showGlobalLoading(message = 'Loading...') {
    globalLoading.value = true
    loadingMessage.value = message
  }

  function hideGlobalLoading() {
    globalLoading.value = false
    loadingMessage.value = ''
  }

  function setTheme(newTheme) {
    theme.value = newTheme
    // Apply theme to document
    if (typeof document !== 'undefined') {
      document.documentElement.setAttribute('data-theme', newTheme)
      localStorage.setItem('theme', newTheme)
    }
  }

  function toggleTheme() {
    setTheme(theme.value === 'light' ? 'dark' : 'light')
  }

  function initializeTheme() {
    if (typeof window !== 'undefined') {
      const savedTheme = localStorage.getItem('theme')
      if (savedTheme) {
        setTheme(savedTheme)
      }
    }
  }

  function checkMobile() {
    if (typeof window !== 'undefined') {
      isMobile.value = window.innerWidth < 768
    }
  }

  function handleResize() {
    checkMobile()
    // Auto-close sidebar on mobile
    if (isMobile.value && sidebarOpen.value) {
      closeSidebar()
    }
  }

  // Initialize
  function initialize() {
    initializeTheme()
    checkMobile()

    if (typeof window !== 'undefined') {
      window.addEventListener('resize', handleResize)
    }
  }

  function cleanup() {
    if (typeof window !== 'undefined') {
      window.removeEventListener('resize', handleResize)
    }
  }

  return {
    // State
    sidebarOpen,
    sidebarCollapsed,
    modals,
    notifications,
    globalLoading,
    loadingMessage,
    theme,
    isMobile,

    // Computed
    hasOpenModal,
    notificationCount,

    // Sidebar actions
    toggleSidebar,
    openSidebar,
    closeSidebar,
    collapseSidebar,
    expandSidebar,

    // Modal actions
    openModal,
    closeModal,
    closeAllModals,
    isModalOpen,

    // Notification actions
    addNotification,
    removeNotification,
    clearNotifications,

    // Loading actions
    showGlobalLoading,
    hideGlobalLoading,

    // Theme actions
    setTheme,
    toggleTheme,

    // Lifecycle
    initialize,
    cleanup
  }
})
