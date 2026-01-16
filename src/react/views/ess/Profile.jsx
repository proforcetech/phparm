import { useState } from 'react'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'

export default function EssProfile() {
  const [profile, setProfile] = useState({
    legalName: 'Alex Morgan',
    preferredName: 'Alex',
    email: 'alex.morgan@example.com',
    phone: '(555) 290-4481',
    address: '1420 Northlake Ave, Suite 200, Seattle, WA',
    emergencyName: 'Jordan Morgan',
    emergencyPhone: '(555) 388-2210',
    emergencyRelation: 'Spouse',
  })
  const [status, setStatus] = useState('')

  const handleChange = (field) => (event) => {
    setProfile((prev) => ({
      ...prev,
      [field]: event.target.value,
    }))
  }

  const handleSubmit = (event) => {
    event.preventDefault()
    setStatus('Profile update submitted for HR review.')
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Profile Updates</h1>
        <p className="mt-1 text-sm text-gray-500">
          Keep your contact information current to receive scheduling and payroll alerts.
        </p>
      </div>

      <Card title="Personal information">
        <form className="space-y-4" onSubmit={handleSubmit}>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="text-sm font-medium text-gray-700">Legal name</label>
              <input
                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
                value={profile.legalName}
                onChange={handleChange('legalName')}
              />
            </div>
            <div>
              <label className="text-sm font-medium text-gray-700">Preferred name</label>
              <input
                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
                value={profile.preferredName}
                onChange={handleChange('preferredName')}
              />
            </div>
            <div>
              <label className="text-sm font-medium text-gray-700">Email</label>
              <input
                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
                type="email"
                value={profile.email}
                onChange={handleChange('email')}
              />
            </div>
            <div>
              <label className="text-sm font-medium text-gray-700">Phone</label>
              <input
                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
                value={profile.phone}
                onChange={handleChange('phone')}
              />
            </div>
          </div>

          <div>
            <label className="text-sm font-medium text-gray-700">Address</label>
            <input
              className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
              value={profile.address}
              onChange={handleChange('address')}
            />
          </div>

          <div className="border-t border-gray-200 pt-4">
            <h3 className="text-sm font-semibold text-gray-700">Emergency contact</h3>
            <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-3">
              <div>
                <label className="text-sm font-medium text-gray-700">Name</label>
                <input
                  className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
                  value={profile.emergencyName}
                  onChange={handleChange('emergencyName')}
                />
              </div>
              <div>
                <label className="text-sm font-medium text-gray-700">Phone</label>
                <input
                  className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
                  value={profile.emergencyPhone}
                  onChange={handleChange('emergencyPhone')}
                />
              </div>
              <div>
                <label className="text-sm font-medium text-gray-700">Relation</label>
                <input
                  className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
                  value={profile.emergencyRelation}
                  onChange={handleChange('emergencyRelation')}
                />
              </div>
            </div>
          </div>

          <div className="flex items-center justify-between">
            <Button type="submit">Submit updates</Button>
            {status ? <span className="text-sm text-emerald-600">{status}</span> : null}
          </div>
        </form>
      </Card>
    </div>
  )
}
