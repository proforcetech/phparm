import { RouterProvider } from 'react-router-dom'

import ToastDemo from './components/ToastDemo'
import { router } from './router'

export default function App() {
  return (
    <>
      <ToastDemo />
      <RouterProvider router={router} />
    </>
  )
}
