import { createRoot } from 'react-dom/client'

import '../assets/styles/main.css'

import App from './App'
import { ToastProvider } from './stores/toast.jsx'

const container = document.getElementById('app')

if (!container) {
  throw new Error('React root element (#app) not found.')
}

createRoot(container).render(
  <ToastProvider>
    <App />
  </ToastProvider>
)
