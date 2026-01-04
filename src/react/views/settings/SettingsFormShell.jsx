import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import useSettingsForm from './useSettingsForm'

export default function SettingsFormShell({ title, description, children }) {
  const {
    error,
    form,
    handleSave,
    loading,
    message,
    saving,
    updateField,
  } = useSettingsForm()

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{title}</h1>
          <p className="mt-1 text-sm text-gray-500">{description}</p>
        </div>
        <Button loading={saving} onClick={handleSave}>Save Settings</Button>
      </div>

      {message ? <Alert variant="success" className="mb-4">{message}</Alert> : null}
      {error ? <Alert variant="danger" className="mb-4">{error}</Alert> : null}

      {loading ? (
        <div className="text-gray-500">Loading settings...</div>
      ) : (
        children({ form, updateField })
      )}
    </div>
  )
}
