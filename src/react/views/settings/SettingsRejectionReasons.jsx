import SettingsFormShell from './SettingsFormShell'
import { RejectionReasonsForm } from './SettingsFormSections'

export default function SettingsRejectionReasons() {
  return (
    <SettingsFormShell
      title="Rejection reasons"
      description="Maintain the list of reasons customers can choose when declining a job."
    >
      {({ form, updateField }) => (
        <div className="space-y-6">
          <RejectionReasonsForm form={form} updateField={updateField} />
        </div>
      )}
    </SettingsFormShell>
  )
}
