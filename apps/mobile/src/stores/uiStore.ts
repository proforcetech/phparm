import AsyncStorage from '@react-native-async-storage/async-storage'
import { Dimensions } from 'react-native'
import { create } from 'zustand'

export type Notification = {
  id: number | string
  message?: string
  type?: string
  duration?: number
  timestamp?: Date
  [key: string]: any
}

export type ChatNotification = {
  id: string
  createdAt: string
  read: boolean
  [key: string]: any
}

type UIState = {
  sidebarOpen: boolean
  sidebarCollapsed: boolean
  modals: Record<string, boolean>
  notifications: Notification[]
  chatNotifications: ChatNotification[]
  globalLoading: boolean
  loadingMessage: string
  theme: string
  isMobile: boolean
  hasOpenModal: () => boolean
  notificationCount: () => number
  toggleSidebar: () => void
  openSidebar: () => void
  closeSidebar: () => void
  collapseSidebar: () => void
  expandSidebar: () => void
  openModal: (modalId: string) => void
  closeModal: (modalId: string) => void
  closeAllModals: () => void
  isModalOpen: (modalId: string) => boolean
  addNotification: (notification: Notification) => number
  removeNotification: (id: number | string) => void
  clearNotifications: () => void
  addChatNotification: (notification: Partial<ChatNotification>) => string
  markChatNotificationRead: (id: string) => void
  clearChatNotifications: () => void
  showGlobalLoading: (message?: string) => void
  hideGlobalLoading: () => void
  setTheme: (newTheme: string) => Promise<void>
  toggleTheme: () => Promise<void>
  initialize: () => Promise<void>
  cleanup: () => void
}

let resizeSubscription: { remove: () => void } | null = null
let removeNotificationRef: ((id: number | string) => void) | null = null

export const useUIStore = create<UIState>((set, get) => ({
  sidebarOpen: true,
  sidebarCollapsed: false,
  modals: {},
  notifications: [],
  chatNotifications: [],
  globalLoading: false,
  loadingMessage: '',
  theme: 'light',
  isMobile: false,
  hasOpenModal: () => Object.values(get().modals).some((isOpen) => isOpen === true),
  notificationCount: () => get().notifications.length,
  toggleSidebar: () => set((state) => ({ sidebarOpen: !state.sidebarOpen })),
  openSidebar: () => set({ sidebarOpen: true }),
  closeSidebar: () => set({ sidebarOpen: false }),
  collapseSidebar: () => set({ sidebarCollapsed: true }),
  expandSidebar: () => set({ sidebarCollapsed: false }),
  openModal: (modalId) => set((state) => ({ modals: { ...state.modals, [modalId]: true } })),
  closeModal: (modalId) => set((state) => ({ modals: { ...state.modals, [modalId]: false } })),
  closeAllModals: () =>
    set((state) => {
      const next = { ...state.modals }
      Object.keys(next).forEach((key) => {
        next[key] = false
      })
      return { modals: next }
    }),
  isModalOpen: (modalId) => get().modals[modalId] === true,
  removeNotification: (id) =>
    set((state) => ({
      notifications: state.notifications.filter((notification) => notification.id !== id),
    })),
  addNotification: (notification) => {
    const id = Date.now()
    const nextNotification = {
      id,
      ...notification,
      timestamp: new Date(),
    }
    set((state) => ({ notifications: [...state.notifications, nextNotification] }))

    const duration = notification.duration || 5000
    if (duration > 0) {
      setTimeout(() => {
        removeNotificationRef?.(id)
      }, duration)
    }

    return id
  },
  clearNotifications: () => set({ notifications: [] }),
  addChatNotification: (notification) => {
    const id = notification?.id ?? `chat-${Date.now()}-${Math.random().toString(16).slice(2)}`
    set((state) => ({
      chatNotifications: [
        {
          id,
          createdAt: notification?.createdAt ?? new Date().toISOString(),
          read: false,
          ...notification,
        },
        ...state.chatNotifications,
      ],
    }))
    return id
  },
  markChatNotificationRead: (id) =>
    set((state) => ({
      chatNotifications: state.chatNotifications.map((notification) =>
        notification.id === id ? { ...notification, read: true } : notification
      ),
    })),
  clearChatNotifications: () => set({ chatNotifications: [] }),
  showGlobalLoading: (message = 'Loading...') => set({ globalLoading: true, loadingMessage: message }),
  hideGlobalLoading: () => set({ globalLoading: false, loadingMessage: '' }),
  setTheme: async (newTheme) => {
    set({ theme: newTheme })
    await AsyncStorage.setItem('theme', newTheme)
  },
  toggleTheme: async () => {
    const theme = get().theme
    await get().setTheme(theme === 'light' ? 'dark' : 'light')
  },
  initialize: async () => {
    const savedTheme = await AsyncStorage.getItem('theme')
    if (savedTheme) {
      set({ theme: savedTheme })
    }
    const { width } = Dimensions.get('window')
    set({ isMobile: width < 768 })

    resizeSubscription?.remove()
    resizeSubscription = Dimensions.addEventListener('change', ({ window }) => {
      const nextIsMobile = window.width < 768
      set({ isMobile: nextIsMobile })
      if (nextIsMobile && get().sidebarOpen) {
        get().closeSidebar()
      }
    })
  },
  cleanup: () => {
    resizeSubscription?.remove()
    resizeSubscription = null
  },
}))

removeNotificationRef = (id) => {
  useUIStore.getState().removeNotification(id)
}
