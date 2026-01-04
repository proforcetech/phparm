import { Link } from 'react-router-dom'

import Card from '../../components/ui/Card'

const settingsLinks = [
  {
    title: 'Shop profile',
    description: 'Update shop contact details, branding, and address information.',
    to: '/cp/settings/profile',
  },
  {
    title: 'Terms & Documents',
    description: 'Manage estimate and invoice terms shown to customers.',
    to: '/cp/settings/terms',
  },
  {
    title: 'Rejection reasons',
    description: 'Maintain the predefined reasons customers can select when declining work.',
    to: '/cp/settings/rejection-reasons',
  },
  {
    title: 'Pricing',
    description: 'Set default tax, labor, and fee values for new work.',
    to: '/cp/settings/pricing',
  },
  {
    title: 'Notifications',
    description: 'Configure outbound email and SMS settings.',
    to: '/cp/settings/notifications',
  },
  {
    title: 'Payments',
    description: 'Connect payment processors and update redirect URLs.',
    to: '/cp/settings/payments',
  },
  {
    title: 'Integrations',
    description: 'Manage third-party integrations like Zoho, reCAPTCHA, and PartsTech.',
    to: '/cp/settings/integrations',
  },
  {
    title: 'Service types',
    description: 'Add and organize service categories used across estimates and bundles.',
    to: '/cp/settings/services',
  },
]

export default function SettingsPage() {
  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Settings overview</h1>
        <p className="mt-1 text-sm text-gray-500">
          Jump into a category to update your shop settings.
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {settingsLinks.map((link) => (
          <Link key={link.to} to={link.to} className="group">
            <Card className="h-full transition group-hover:shadow-md">
              <h2 className="text-lg font-semibold text-gray-900">{link.title}</h2>
              <p className="mt-2 text-sm text-gray-500">{link.description}</p>
              <span className="mt-4 inline-flex text-sm font-medium text-primary-600">
                Manage settings →
              </span>
            </Card>
          </Link>
        ))}
      </div>
    </div>
  )
}
