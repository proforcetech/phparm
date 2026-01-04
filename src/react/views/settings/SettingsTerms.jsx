import { useCallback, useEffect, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import RichTextEditor from '../../components/ui/RichTextEditor'
import { fetchSettings, saveSettings } from '../../../services/settings.service'

const initialFormState = {
  estimates: '',
  invoices: '',
}

const getSetting = (settings, key, fallback = '') => settings?.[key]?.value ?? fallback
import SettingsFormShell from './SettingsFormShell'
import { TermsForm } from './SettingsFormSections'

export default function SettingsTerms() {
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [form, setForm] = useState(initialFormState)

  const hydrate = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      const settings = await fetchSettings()
      setForm({
        estimates: getSetting(settings, 'documents.terms.estimates', ''),
        invoices: getSetting(settings, 'documents.terms.invoices', ''),
      })
    } catch (fetchError) {
      setError(fetchError?.message || 'Unable to load terms settings.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    hydrate()
  }, [hydrate])

  const handleSave = async () => {
    setSaving(true)
    setMessage('')
    setError('')

    try {
      await saveSettings({
        'documents.terms.estimates': form.estimates,
        'documents.terms.invoices': form.invoices,
      })
      setMessage('Terms settings saved successfully.')
    } catch (saveError) {
      setError(saveError?.response?.data?.message || saveError?.message || 'Failed to save terms settings.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Terms &amp; Conditions</h2>
          <p className="text-sm text-gray-500">
            Customize the rich-text terms included on estimates and invoices.
          </p>
        </div>
        <Button loading={saving} onClick={handleSave}>Save Terms</Button>
      </div>

      {message ? <Alert variant="success">{message}</Alert> : null}
      {error ? <Alert variant="danger">{error}</Alert> : null}

      {loading ? (
        <div className="text-gray-500">Loading terms...</div>
      ) : (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <Card>
            <h3 className="text-base font-semibold text-gray-900 mb-3">Estimate Terms</h3>
            <RichTextEditor
              value={form.estimates}
              placeholder="Add terms shown on estimates"
              onChange={(value) => setForm((prev) => ({ ...prev, estimates: value }))}
            />
          </Card>
          <Card>
            <h3 className="text-base font-semibold text-gray-900 mb-3">Invoice Terms</h3>
            <RichTextEditor
              value={form.invoices}
              placeholder="Add terms shown on invoices"
              onChange={(value) => setForm((prev) => ({ ...prev, invoices: value }))}
            />
          </Card>
        </div>
      )}
    </div>
    <SettingsFormShell
      title="Terms & Documents"
      description="Control the terms that appear on estimates and invoices."
    >
      {({ form, updateField }) => (
        <div className="space-y-6">
          <TermsForm form={form} updateField={updateField} />
        </div>
      )}
    </SettingsFormShell>
  )
}
