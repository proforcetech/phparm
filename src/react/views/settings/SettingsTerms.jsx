import SettingsFormShell from './SettingsFormShell'
import { TermsForm } from './SettingsFormSections'

export default function SettingsTerms() {
  return (
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
