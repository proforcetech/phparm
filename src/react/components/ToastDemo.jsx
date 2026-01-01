import { useToast } from '../stores/toast'

export default function ToastDemo() {
  const { messages, success, error, info, dismiss } = useToast()

  return (
    <section>
      <h2>Toast store demo</h2>
      <p>Trigger a message to verify the React toast store wiring.</p>
      <div>
        <button type="button" onClick={() => success('Saved successfully.')}>
          Trigger success
        </button>
        <button type="button" onClick={() => error('Something went wrong.')}>
          Trigger error
        </button>
        <button type="button" onClick={() => info('Heads up!')}>
          Trigger info
        </button>
      </div>
      {messages.length === 0 ? (
        <p>No toasts yet.</p>
      ) : (
        <ul>
          {messages.map((toast) => (
            <li key={toast.id}>
              <strong>{toast.type}:</strong> {toast.message}{' '}
              <button type="button" onClick={() => dismiss(toast.id)}>
                Dismiss
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
