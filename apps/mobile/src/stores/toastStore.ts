import { create } from 'zustand'

type ToastMessage = {
  id: number
  message: string
  type: 'success' | 'error' | 'info'
}

type ToastState = {
  messages: ToastMessage[]
  dismiss: (id: number) => void
  push: (message: string, type?: ToastMessage['type']) => number
  success: (message: string) => number
  error: (message: string) => number
  info: (message: string) => number
}

const defaultTimeoutMs = 3500
const timeouts = new Map<number, ReturnType<typeof setTimeout>>()
let counter = 0

export const useToastStore = create<ToastState>((set, get) => ({
  messages: [],
  dismiss: (id) => {
    set((state) => ({ messages: state.messages.filter((item) => item.id !== id) }))

    const timeoutId = timeouts.get(id)
    if (timeoutId) {
      clearTimeout(timeoutId)
      timeouts.delete(id)
    }
  },
  push: (message, type = 'info') => {
    const id = ++counter
    set((state) => ({ messages: [...state.messages, { id, message, type }] }))

    const timeoutId = setTimeout(() => get().dismiss(id), defaultTimeoutMs)
    timeouts.set(id, timeoutId)

    return id
  },
  success: (message) => get().push(message, 'success'),
  error: (message) => get().push(message, 'error'),
  info: (message) => get().push(message, 'info'),
}))
