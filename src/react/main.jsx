import { createRoot } from 'react-dom/client'

import App from './App'
import { ToastProvider } from './stores/toast.jsx'

const container = document.getElementById('react-root')

if (!container) {
  throw new Error('React root element (#react-root) not found.')
}

createRoot(container).render(
  <ToastProvider>
    <App />
  </ToastProvider>
)
