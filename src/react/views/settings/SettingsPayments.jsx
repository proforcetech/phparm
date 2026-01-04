import SettingsFormShell from './SettingsFormShell'
import { PaymentsForm } from './SettingsFormSections'

export default function SettingsPayments() {
  return (
    <SettingsFormShell
      title="Payments"
      description="Manage payment gateways and post-payment redirects."
    >
      {({ form, updateField }) => (
        <div className="space-y-6">
          <PaymentsForm form={form} updateField={updateField} />
        </div>
      )}
    </SettingsFormShell>
  )
}
