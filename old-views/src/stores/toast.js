import { reactive } from 'vue'

const state = reactive({
  messages: [],
})

let counter = 0

function dismiss(id) {
  const index = state.messages.findIndex((item) => item.id === id)
  if (index >= 0) {
    state.messages.splice(index, 1)
  }
}

function push(message, type = 'info') {
  const id = ++counter
  state.messages.push({ id, message, type })
  setTimeout(() => dismiss(id), 3500)
}

export function useToast() {
  return {
    messages: state.messages,
    success: (message) => push(message, 'success'),
    error: (message) => push(message, 'error'),
    info: (message) => push(message, 'info'),
    dismiss,
  }
}

export default useToast
