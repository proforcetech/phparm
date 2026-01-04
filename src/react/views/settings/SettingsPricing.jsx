import SettingsFormShell from './SettingsFormShell'
import { PricingForm } from './SettingsFormSections'

export default function SettingsPricing() {
  return (
    <SettingsFormShell
      title="Pricing"
      description="Set default pricing values applied to new estimates and invoices."
    >
      {({ form, updateField }) => (
        <div className="space-y-6">
          <PricingForm form={form} updateField={updateField} />
        </div>
      )}
    </SettingsFormShell>
  )
}
