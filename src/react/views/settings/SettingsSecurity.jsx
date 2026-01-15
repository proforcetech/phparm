import SettingsFormShell from './SettingsFormShell'
import { SecurityForm } from './SettingsFormSections'

const roleOptions = [
  { value: 'admin', label: 'Admin', description: 'Full access across the platform.' },
  { value: 'dispatcher', label: 'Dispatcher', description: 'Dispatch operations and roadside coordination.' },
  { value: 'manager', label: 'Manager', description: 'Manage shop operations and reports.' },
  { value: 'technician', label: 'Technician', description: 'Work orders, inspections, and time tracking.' },
  { value: 'parts', label: 'Parts', description: 'Inventory alerts and ordering.' },
  { value: 'roadside', label: 'Roadside', description: 'Roadside assistance and dispatch workflows.' },
  { value: 'cms', label: 'CMS', description: 'Manage CMS content and redirects.' },
]

export default function SettingsSecurity() {
  return (
    <SettingsFormShell
      title="Security"
      description="Define role-based two-factor authentication requirements."
    >
      {({ form, updateField }) => (
        <SecurityForm form={form} updateField={updateField} roleOptions={roleOptions} />
      )}
    </SettingsFormShell>
  )
}
