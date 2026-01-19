import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react'

const UIContext = createContext(null)

export function UIProvider({ children }) {
  const [sidebarOpen, setSidebarOpen] = useState(true)
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false)
  const [modals, setModals] = useState({})
  const [notifications, setNotifications] = useState([])
  const [chatNotifications, setChatNotifications] = useState([])
  const [globalLoading, setGlobalLoading] = useState(false)
  const [loadingMessage, setLoadingMessage] = useState('')
  const [theme, setThemeState] = useState('light')
  const [isMobile, setIsMobile] = useState(false)
  const resizeHandlerRef = useRef(null)
  const removeNotificationRef = useRef(null)

  const hasOpenModal = useMemo(
    () => Object.values(modals).some((isOpen) => isOpen === true),
    [modals]
  )

  const notificationCount = useMemo(() => notifications.length, [notifications])

  const toggleSidebar = useCallback(() => {
    setSidebarOpen((prev) => !prev)
  }, [])

  const openSidebar = useCallback(() => {
    setSidebarOpen(true)
  }, [])

  const closeSidebar = useCallback(() => {
    setSidebarOpen(false)
  }, [])

  const collapseSidebar = useCallback(() => {
    setSidebarCollapsed(true)
  }, [])

  const expandSidebar = useCallback(() => {
    setSidebarCollapsed(false)
  }, [])

  const openModal = useCallback((modalId) => {
    setModals((prev) => ({ ...prev, [modalId]: true }))
  }, [])

  const closeModal = useCallback((modalId) => {
    setModals((prev) => ({ ...prev, [modalId]: false }))
  }, [])

  const closeAllModals = useCallback(() => {
    setModals((prev) => {
      const next = { ...prev }
      Object.keys(next).forEach((key) => {
        next[key] = false
      })
      return next
    })
  }, [])

  const isModalOpen = useCallback((modalId) => modals[modalId] === true, [modals])

  const removeNotification = useCallback((id) => {
    setNotifications((prev) => prev.filter((notification) => notification.id !== id))
  }, [])

  // Keep ref updated to avoid stale closures in setTimeout
  removeNotificationRef.current = removeNotification

  const addNotification = useCallback((notification) => {
    const id = Date.now()
    setNotifications((prev) => [
      ...prev,
      {
        id,
        ...notification,
        timestamp: new Date(),
      },
    ])

    const duration = notification.duration || 5000
    if (duration > 0) {
      setTimeout(() => {
        removeNotificationRef.current(id)
      }, duration)
    }

    return id
  }, [])

  const clearNotifications = useCallback(() => {
    setNotifications([])
  }, [])

  const addChatNotification = useCallback((notification) => {
    const id = notification?.id ?? `chat-${Date.now()}-${Math.random().toString(16).slice(2)}`
    setChatNotifications((prev) => [
      {
        id,
        createdAt: notification?.createdAt ?? new Date().toISOString(),
        read: false,
        ...notification,
      },
      ...prev,
    ])
    return id
  }, [])

  const markChatNotificationRead = useCallback((id) => {
    setChatNotifications((prev) =>
      prev.map((notification) =>
        notification.id === id ? { ...notification, read: true } : notification
      )
    )
  }, [])

  const clearChatNotifications = useCallback(() => {
    setChatNotifications([])
  }, [])

  const showGlobalLoading = useCallback((message = 'Loading...') => {
    setGlobalLoading(true)
    setLoadingMessage(message)
  }, [])

  const hideGlobalLoading = useCallback(() => {
    setGlobalLoading(false)
    setLoadingMessage('')
  }, [])

  const applyTheme = useCallback((newTheme) => {
    setThemeState(newTheme)
    if (typeof document !== 'undefined') {
      document.documentElement.setAttribute('data-theme', newTheme)
      localStorage.setItem('theme', newTheme)
    }
  }, [])

  const toggleTheme = useCallback(() => {
    applyTheme(theme === 'light' ? 'dark' : 'light')
  }, [applyTheme, theme])

  const initializeTheme = useCallback(() => {
    if (typeof window !== 'undefined') {
      const savedTheme = localStorage.getItem('theme')
      if (savedTheme) {
        applyTheme(savedTheme)
      }
    }
  }, [applyTheme])

  const checkMobile = useCallback(() => {
    if (typeof window !== 'undefined') {
      setIsMobile(window.innerWidth < 768)
    }
  }, [])

  const handleResize = useCallback(() => {
    checkMobile()
    if (isMobile && sidebarOpen) {
      closeSidebar()
    }
  }, [checkMobile, closeSidebar, isMobile, sidebarOpen])

  const initialize = useCallback(() => {
    initializeTheme()
    checkMobile()

    if (typeof window !== 'undefined') {
      resizeHandlerRef.current = handleResize
      window.addEventListener('resize', handleResize)
    }
  }, [checkMobile, handleResize, initializeTheme])

  const cleanup = useCallback(() => {
    if (typeof window !== 'undefined' && resizeHandlerRef.current) {
      window.removeEventListener('resize', resizeHandlerRef.current)
    }
  }, [])

  const value = useMemo(
    () => ({
      sidebarOpen,
      sidebarCollapsed,
      modals,
      notifications,
      chatNotifications,
      globalLoading,
      loadingMessage,
      theme,
      isMobile,
      hasOpenModal,
      notificationCount,
      toggleSidebar,
      openSidebar,
      closeSidebar,
      collapseSidebar,
      expandSidebar,
      openModal,
      closeModal,
      closeAllModals,
      isModalOpen,
      addNotification,
      removeNotification,
      clearNotifications,
      addChatNotification,
      markChatNotificationRead,
      clearChatNotifications,
      showGlobalLoading,
      hideGlobalLoading,
      setTheme: applyTheme,
      toggleTheme,
      initialize,
      cleanup,
    }),
    [
      addNotification,
      addChatNotification,
      applyTheme,
      clearChatNotifications,
      cleanup,
      closeModal,
      closeSidebar,
      collapseSidebar,
      expandSidebar,
      globalLoading,
      handleResize,
      hasOpenModal,
      hideGlobalLoading,
      initialize,
      isMobile,
      isModalOpen,
      loadingMessage,
      modals,
      notificationCount,
      notifications,
      chatNotifications,
      openModal,
      openSidebar,
      markChatNotificationRead,
      removeNotification,
      sidebarCollapsed,
      sidebarOpen,
      showGlobalLoading,
      theme,
      toggleSidebar,
      toggleTheme,
    ]
  )

  return <UIContext.Provider value={value}>{children}</UIContext.Provider>
}

export function useUIStore() {
  const context = useContext(UIContext)

  if (!context) {
    throw new Error('useUIStore must be used within a UIProvider.')
  }

  return context
}
