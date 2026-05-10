// @deprecated Phase 2a — frozen for legacy `customer` role. New portal lives at src/react/views/portal/*.
import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import { reminderService } from '../../../services/reminder.service'

const channelOptions = [
  { label: 'Email', value: 'mail' },
  { label: 'SMS', value: 'sms' },
  { label: 'Email & SMS', value: 'both' },
  { label: 'Do Not Send', value: 'none' },
]

const timezoneOptions = [
  { label: 'UTC', value: 'UTC' },
  { label: 'Eastern Time (ET)', value: 'America/New_York' },
  { label: 'Central Time (CT)', value: 'America/Chicago' },
  { label: 'Mountain Time (MT)', value: 'America/Denver' },
  { label: 'Pacific Time (PT)', value: 'America/Los_Angeles' },
]

export default function Profile() {
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [successMessage, setSuccessMessage] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const [profileForm, setProfileForm] = useState({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
  })
  const [preferenceForm, setPreferenceForm] = useState({
    preferred_channel: 'both',
    timezone: 'UTC',
    lead_days: 3,
    preferred_hour: 9,
    is_active: true,
  })

  const hourOptions = useMemo(
    () =>
      Array.from({ length: 24 }).map((_, index) => ({
        label: new Date(0, 0, 0, index).toLocaleTimeString([], { hour: 'numeric', hour12: true }),
        value: index,
      })),
    []
  )

  const loadPreferences = useCallback(async () => {
    setLoading(true)
    setErrorMessage('')

    try {
      const data = await reminderService.getPreferences()

      if (data.customer) {
        setProfileForm((prev) => ({
          ...prev,
          first_name: data.customer.first_name || '',
          last_name: data.customer.last_name || '',
          email: data.customer.email || '',
          phone: data.customer.phone || '',
        }))
      }

      if (data.preference) {
        setPreferenceForm((prev) => ({
          ...prev,
          preferred_channel: data.preference.preferred_channel || 'both',
          timezone: data.preference.timezone || 'UTC',
          lead_days: data.preference.lead_days ?? 3,
          preferred_hour: data.preference.preferred_hour ?? 9,
          is_active: data.preference.is_active ?? true,
        }))
      }
    } catch (error) {
      setErrorMessage(error.response?.data?.message || 'Unable to load preferences.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadPreferences()
  }, [loadPreferences])

  const savePreferences = async (event) => {
    event.preventDefault()
    setSaving(true)
    setSuccessMessage('')
    setErrorMessage('')

    try {
      const payload = {
        ...profileForm,
        ...preferenceForm,
      }

      const data = await reminderService.updatePreferences(payload)

      setSuccessMessage('Preferences saved successfully.')

      if (data.preference) {
        setPreferenceForm((prev) => ({
          ...prev,
          preferred_channel: data.preference.preferred_channel || 'both',
          timezone: data.preference.timezone || 'UTC',
          lead_days: data.preference.lead_days ?? 3,
          preferred_hour: data.preference.preferred_hour ?? 9,
          is_active: data.preference.is_active ?? true,
        }))
      }

      if (data.customer) {
        setProfileForm((prev) => ({
          ...prev,
          first_name: data.customer.first_name || '',
          last_name: data.customer.last_name || '',
          email: data.customer.email || '',
          phone: data.customer.phone || '',
        }))
      }
    } catch (error) {
      setErrorMessage(error.response?.data?.message || 'Unable to save preferences.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">My Profile</h1>
        <p className="mt-1 text-sm text-gray-500">Update your contact information and reminder preferences.</p>
      </div>

      {errorMessage ? (
        <Alert variant="danger" className="mb-4" onClose={() => setErrorMessage('')}>
          {errorMessage}
        </Alert>
      ) : null}

      {successMessage ? (
        <Alert variant="success" className="mb-4" onClose={() => setSuccessMessage('')}>
          {successMessage}
        </Alert>
      ) : null}

      <form onSubmit={savePreferences}>
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2 space-y-6">
            <Card title="Personal Information">
              <div className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <Input
                    modelValue={profileForm.first_name}
                    label="First Name"
                    placeholder="John"
                    required
                    onUpdateModelValue={(value) =>
                      setProfileForm((prev) => ({
                        ...prev,
                        first_name: value,
                      }))
                    }
                  />
                  <Input
                    modelValue={profileForm.last_name}
                    label="Last Name"
                    placeholder="Doe"
                    required
                    onUpdateModelValue={(value) =>
                      setProfileForm((prev) => ({
                        ...prev,
                        last_name: value,
                      }))
                    }
                  />
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <Input
                    modelValue={profileForm.email}
                    label="Email"
                    type="email"
                    placeholder="john@example.com"
                    onUpdateModelValue={(value) =>
                      setProfileForm((prev) => ({
                        ...prev,
                        email: value,
                      }))
                    }
                  />
                  <Input
                    modelValue={profileForm.phone}
                    label="Phone"
                    type="tel"
                    placeholder="(555) 123-4567"
                    onUpdateModelValue={(value) =>
                      setProfileForm((prev) => ({
                        ...prev,
                        phone: value,
                      }))
                    }
                  />
                </div>
                <p className="text-sm text-gray-500">Provide at least one contact method so we can send reminders.</p>
              </div>
            </Card>

            <Card title="Reminder Preferences">
              <div className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <Select
                    modelValue={preferenceForm.preferred_channel}
                    options={channelOptions}
                    label="Reminder Channel"
                    onUpdateModelValue={(value) =>
                      setPreferenceForm((prev) => ({
                        ...prev,
                        preferred_channel: value,
                      }))
                    }
                  />
                  <Select
                    modelValue={preferenceForm.timezone}
                    options={timezoneOptions}
                    label="Timezone"
                    onUpdateModelValue={(value) =>
                      setPreferenceForm((prev) => ({
                        ...prev,
                        timezone: value,
                      }))
                    }
                  />
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <Input
                    modelValue={preferenceForm.lead_days}
                    type="number"
                    min="0"
                    label="Lead Days"
                    helperText="How many days before to send notices"
                    required
                    onUpdateModelValue={(value) =>
                      setPreferenceForm((prev) => ({
                        ...prev,
                        lead_days: value === '' ? '' : Number(value),
                      }))
                    }
                  />
                  <Select
                    modelValue={preferenceForm.preferred_hour}
                    options={hourOptions}
                    label="Preferred Hour"
                    onUpdateModelValue={(value) =>
                      setPreferenceForm((prev) => ({
                        ...prev,
                        preferred_hour: value,
                      }))
                    }
                  />
                </div>

                <label className="flex items-center space-x-2 text-sm text-gray-700">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                    checked={preferenceForm.is_active}
                    onChange={(event) =>
                      setPreferenceForm((prev) => ({
                        ...prev,
                        is_active: event.target.checked,
                      }))
                    }
                  />
                  <span>Enable automated reminders</span>
                </label>
              </div>
            </Card>
          </div>

          <div>
            <Card title="Actions">
              <div className="space-y-4">
                <Button disabled={saving || loading} type="submit" className="w-full">
                  {saving ? 'Saving...' : 'Save Preferences'}
                </Button>
                <p className="text-xs text-gray-500">Changes update your contact info and how you receive reminders.</p>
              </div>
            </Card>
          </div>
        </div>
      </form>
    </div>
  )
}
