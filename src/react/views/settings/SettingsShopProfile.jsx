import SettingsFormShell from './SettingsFormShell'
import { ShopProfileForm } from './SettingsFormSections'

export default function SettingsShopProfile() {
  return (
    <SettingsFormShell
      title="Shop profile"
      description="Update the contact information and address shown on documents."
    >
      {({ form, updateField }) => (
        <div className="space-y-6">
          <ShopProfileForm form={form} updateField={updateField} />
        </div>
      )}
    </SettingsFormShell>
  )
}
