import SettingsFormShell from './SettingsFormShell'
import { IntegrationsForm } from './SettingsFormSections'

export default function SettingsIntegrations() {
  return (
    <SettingsFormShell
      title="Integrations"
      description="Connect third-party platforms and configure advanced integrations."
    >
      {({ form, updateField }) => (
        <div className="space-y-6">
          <IntegrationsForm form={form} updateField={updateField} />
        </div>
      )}
    </SettingsFormShell>
  )
}
