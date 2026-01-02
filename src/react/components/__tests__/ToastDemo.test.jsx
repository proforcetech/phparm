import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import ToastDemo from '../ToastDemo'
import { ToastProvider } from '../../stores/toast.jsx'

describe('ToastDemo', () => {
  it('renders and dismisses a toast message', async () => {
    render(
      <ToastProvider timeoutMs={10000}>
        <ToastDemo />
      </ToastProvider>
    )

    expect(screen.getByText('No toasts yet.')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Trigger success' }))

    expect(screen.getByText('Saved successfully.')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Dismiss' }))

    expect(screen.getByText('No toasts yet.')).toBeInTheDocument()
  })
})
