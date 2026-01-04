import SettingsFormShell from './SettingsFormShell'
import { NotificationsForm } from './SettingsFormSections'

export default function SettingsNotifications() {
  return (
    <SettingsFormShell
      title="Notifications"
      description="Configure outbound email and SMS messaging settings."
    >
      {({ form, updateField }) => (
        <div className="space-y-6">
          <NotificationsForm form={form} updateField={updateField} />
        </div>
      )}
    </SettingsFormShell>
  )
}
