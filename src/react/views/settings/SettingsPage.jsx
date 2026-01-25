import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import { fetchSettings, saveSettings } from '../../../services/settings.service'

const initialFormState = {
  profile: {
    name: '',
    email: '',
    phone: '',
    logoUrl: '',
    address: {
      street: '',
      city: '',
      state: '',
      postal_code: '',
      country: '',
    },
  },
  pricing: { taxRate: 0, laborRate: 0, callOutFee: 0, mileageRate: 0 },
  storageFees: { dailyStorage: 0, gateFee: 0, impoundFee: 0 },
  notifications: { fromName: '', fromAddress: '', smsNumber: '', twilioSid: '', twilioToken: '' },
  smtp: { host: '', port: 587, username: '', password: '', encryption: 'tls' },
  payments: {
    successUrl: '',
    cancelUrl: '',
    stripePublic: '',
    stripeSecret: '',
    stripeWebhook: '',
    squareToken: '',
    squareSignature: '',
    paypalClientId: '',
    paypalClientSecret: '',
    paypalWebhook: '',
  },
  security: { recaptchaEnabled: false, recaptchaSiteKey: '', recaptchaSecretKey: '' },
  integrations: {
    zohoClientId: '',
    zohoClientSecret: '',
    zohoRefreshToken: '',
    zohoOrgId: '',
    partsTechBase: '',
    partsTechKey: '',
    partsTechMarkup: '',
  },
}

const getSetting = (settings, key, fallback = null) => settings?.[key]?.value ?? fallback

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
    title: 'Security',
    description: 'Require two-factor authentication for high-privilege roles.',
    to: '/cp/settings/security',
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
  {
    title: 'Dispatch',
    description: 'Configure load balancing, driver selection strategy, and fair distribution.',
    to: '/cp/settings/dispatch',
  },
]

export default function SettingsPage() {
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [form, setForm] = useState(initialFormState)

  const hydrate = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      const settings = await fetchSettings()
      setForm((prev) => {
        const addressSetting = getSetting(settings, 'shop.address', {}) || {}
        const markup = getSetting(settings, 'integrations.partstech.markup_tiers', [])
        const partsTechMarkup = markup && markup.length ? JSON.stringify(markup, null, 2) : ''

        return {
          ...prev,
          profile: {
            ...prev.profile,
            name: getSetting(settings, 'shop.name', ''),
            email: getSetting(settings, 'shop.email', ''),
            phone: getSetting(settings, 'shop.phone', ''),
            logoUrl: getSetting(settings, 'shop.logo_url', ''),
            address: {
              street: addressSetting?.street ?? '',
              city: addressSetting?.city ?? '',
              state: addressSetting?.state ?? '',
              postal_code: addressSetting?.postal_code ?? '',
              country: addressSetting?.country ?? '',
            },
          },
          pricing: {
            taxRate: Number(getSetting(settings, 'pricing.tax_rate', 0)),
            laborRate: Number(getSetting(settings, 'pricing.labor_rate', 0)),
            callOutFee: Number(getSetting(settings, 'pricing.call_out_fee', 0)),
            mileageRate: Number(getSetting(settings, 'pricing.mileage_rate', 0)),
          },
          storageFees: {
            dailyStorage: Number(getSetting(settings, 'storage.daily_fee', 0)),
            gateFee: Number(getSetting(settings, 'storage.gate_fee', 0)),
            impoundFee: Number(getSetting(settings, 'impound.default_fee', 0)),
          },
          notifications: {
            fromName: getSetting(settings, 'notifications.mail.from_name', ''),
            fromAddress: getSetting(settings, 'notifications.mail.from_address', ''),
            smsNumber: getSetting(settings, 'notifications.sms.from_number', ''),
            twilioSid: getSetting(settings, 'integrations.twilio.sid', ''),
            twilioToken: getSetting(settings, 'integrations.twilio.token', ''),
          },
          smtp: {
            host: getSetting(settings, 'integrations.smtp.host', ''),
            port: Number(getSetting(settings, 'integrations.smtp.port', 587)),
            username: getSetting(settings, 'integrations.smtp.username', ''),
            password: getSetting(settings, 'integrations.smtp.password', ''),
            encryption: getSetting(settings, 'integrations.smtp.encryption', 'tls'),
          },
          payments: {
            successUrl: getSetting(settings, 'payments.urls.success', ''),
            cancelUrl: getSetting(settings, 'payments.urls.cancel', ''),
            stripePublic: getSetting(settings, 'integrations.stripe.public_key', ''),
            stripeSecret: getSetting(settings, 'integrations.stripe.secret_key', ''),
            stripeWebhook: getSetting(settings, 'integrations.stripe.webhook_secret', ''),
            squareToken: getSetting(settings, 'integrations.square.token', ''),
            squareSignature: getSetting(settings, 'integrations.square.webhook_signature_key', ''),
            paypalClientId: getSetting(settings, 'integrations.paypal.client_id', ''),
            paypalClientSecret: getSetting(settings, 'integrations.paypal.client_secret', ''),
            paypalWebhook: getSetting(settings, 'integrations.paypal.webhook_id', ''),
          },
          security: {
            recaptchaEnabled: !!getSetting(settings, 'integrations.recaptcha.enabled', false),
            recaptchaSiteKey: getSetting(settings, 'integrations.recaptcha.site_key', ''),
            recaptchaSecretKey: getSetting(settings, 'integrations.recaptcha.secret_key', ''),
          },
          integrations: {
            zohoClientId: getSetting(settings, 'integrations.zoho.client_id', ''),
            zohoClientSecret: getSetting(settings, 'integrations.zoho.client_secret', ''),
            zohoRefreshToken: getSetting(settings, 'integrations.zoho.refresh_token', ''),
            zohoOrgId: getSetting(settings, 'integrations.zoho.org_id', ''),
            partsTechBase: getSetting(settings, 'integrations.partstech.api_base', ''),
            partsTechKey: getSetting(settings, 'integrations.partstech.api_key', ''),
            partsTechMarkup,
          },
        }
      })
    } catch (fetchError) {
      setError(fetchError?.message || 'Unable to load settings.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    hydrate()
  }, [hydrate])

  const updateField = (path, value) => {
    setForm((prev) => {
      const next = { ...prev }
      let target = next
      for (let i = 0; i < path.length - 1; i += 1) {
        target[path[i]] = { ...target[path[i]] }
        target = target[path[i]]
      }
      target[path[path.length - 1]] = value
      return next
    })
  }

  const parseMarkup = useCallback(() => {
    if (!form.integrations.partsTechMarkup) {
      return []
    }

    try {
      const parsed = JSON.parse(form.integrations.partsTechMarkup)
      return Array.isArray(parsed) ? parsed : []
    } catch (parseError) {
      throw new Error('PartsTech markup tiers must be valid JSON.')
    }
  }, [form.integrations.partsTechMarkup])

  const payload = useMemo(() => {
    return {
      'shop.name': form.profile.name,
      'shop.email': form.profile.email,
      'shop.phone': form.profile.phone,
      'shop.logo_url': form.profile.logoUrl,
      'shop.address': { ...form.profile.address },
      'pricing.tax_rate': Number(form.pricing.taxRate) || 0,
      'pricing.labor_rate': Number(form.pricing.laborRate) || 0,
      'pricing.call_out_fee': Number(form.pricing.callOutFee) || 0,
      'pricing.mileage_rate': Number(form.pricing.mileageRate) || 0,
      'storage.daily_fee': Number(form.storageFees.dailyStorage) || 0,
      'storage.gate_fee': Number(form.storageFees.gateFee) || 0,
      'impound.default_fee': Number(form.storageFees.impoundFee) || 0,
      'notifications.mail.from_name': form.notifications.fromName,
      'notifications.mail.from_address': form.notifications.fromAddress,
      'notifications.sms.from_number': form.notifications.smsNumber,
      'integrations.twilio.sid': form.notifications.twilioSid,
      'integrations.twilio.token': form.notifications.twilioToken,
      'integrations.smtp.host': form.smtp.host,
      'integrations.smtp.port': Number(form.smtp.port) || 0,
      'integrations.smtp.username': form.smtp.username,
      'integrations.smtp.password': form.smtp.password,
      'integrations.smtp.encryption': form.smtp.encryption,
      'payments.urls.success': form.payments.successUrl,
      'payments.urls.cancel': form.payments.cancelUrl,
      'integrations.stripe.public_key': form.payments.stripePublic,
      'integrations.stripe.secret_key': form.payments.stripeSecret,
      'integrations.stripe.webhook_secret': form.payments.stripeWebhook,
      'integrations.square.token': form.payments.squareToken,
      'integrations.square.webhook_signature_key': form.payments.squareSignature,
      'integrations.paypal.client_id': form.payments.paypalClientId,
      'integrations.paypal.client_secret': form.payments.paypalClientSecret,
      'integrations.paypal.webhook_id': form.payments.paypalWebhook,
      'integrations.recaptcha.enabled': !!form.security.recaptchaEnabled,
      'integrations.recaptcha.site_key': form.security.recaptchaSiteKey,
      'integrations.recaptcha.secret_key': form.security.recaptchaSecretKey,
      'integrations.zoho.client_id': form.integrations.zohoClientId,
      'integrations.zoho.client_secret': form.integrations.zohoClientSecret,
      'integrations.zoho.refresh_token': form.integrations.zohoRefreshToken,
      'integrations.zoho.org_id': form.integrations.zohoOrgId,
      'integrations.partstech.api_base': form.integrations.partsTechBase,
      'integrations.partstech.api_key': form.integrations.partsTechKey,
    }
  }, [form])

  const handleSave = async () => {
    setSaving(true)
    setMessage('')
    setError('')

    let markupTiers = []
    try {
      markupTiers = parseMarkup()
    } catch (parseError) {
      setSaving(false)
      setError(parseError.message)
      return
    }

    try {
      await saveSettings({
        ...payload,
        'integrations.partstech.markup_tiers': markupTiers,
      })
      setMessage('Settings saved successfully.')
    } catch (saveError) {
      setError(saveError?.response?.data?.message || saveError?.message || 'Failed to save settings.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Settings overview</h1>
        <p className="mt-1 text-sm text-gray-500">
          Jump into a category to update your shop settings.
        </p>
      </div>

      {message ? <Alert variant="success" className="mb-4">{message}</Alert> : null}
      {error ? <Alert variant="danger" className="mb-4">{error}</Alert> : null}

      {loading ? <div className="text-gray-500">Loading settings...</div> : (
        <div className="space-y-6">
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Card>
              <h2 className="text-lg font-semibold text-gray-900 mb-4">Shop Profile</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Shop Name</label>
                  <Input
                    value={form.profile.name}
                    placeholder="Demo Auto Shop"
                    className="mt-1"
                    onChange={(event) => updateField(['profile', 'name'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Notification Email</label>
                  <Input
                    value={form.profile.email}
                    placeholder="noreply@example.com"
                    className="mt-1"
                    onChange={(event) => updateField(['profile', 'email'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Phone</label>
                  <Input
                    value={form.profile.phone}
                    placeholder="+1 (555) 123-4567"
                    className="mt-1"
                    onChange={(event) => updateField(['profile', 'phone'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Logo URL</label>
                  <Input
                    value={form.profile.logoUrl}
                    placeholder="https://cdn.example.com/logo.png"
                    className="mt-1"
                    onChange={(event) => updateField(['profile', 'logoUrl'], event.target.value)}
                  />
                </div>
              </div>
              <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Street</label>
                  <Input
                    value={form.profile.address.street}
                    placeholder="123 Main St"
                    className="mt-1"
                    onChange={(event) => updateField(['profile', 'address', 'street'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">City</label>
                  <Input
                    value={form.profile.address.city}
                    placeholder="Anytown"
                    className="mt-1"
                    onChange={(event) => updateField(['profile', 'address', 'city'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">State/Province</label>
                  <Input
                    value={form.profile.address.state}
                    placeholder="CA"
                    className="mt-1"
                    onChange={(event) => updateField(['profile', 'address', 'state'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Postal Code</label>
                  <Input
                    value={form.profile.address.postal_code}
                    placeholder="90210"
                    className="mt-1"
                    onChange={(event) => updateField(['profile', 'address', 'postal_code'], event.target.value)}
                  />
                </div>
                <div className="md:col-span-2">
                  <label className="block text-sm font-medium text-gray-700">Country</label>
                  <Input
                    value={form.profile.address.country}
                    placeholder="United States"
                    className="mt-1"
                    onChange={(event) => updateField(['profile', 'address', 'country'], event.target.value)}
                  />
                </div>
              </div>
            </Card>

            <Card>
              <h2 className="text-lg font-semibold text-gray-900 mb-4">Terms &amp; Documents</h2>
              <p className="text-sm text-gray-500">
                Terms and conditions are now managed in the Terms settings page.
              </p>
            </Card>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Card>
              <h2 className="text-lg font-semibold text-gray-900 mb-4">Pricing Defaults</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Tax Rate (%)</label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.pricing.taxRate}
                    className="mt-1"
                    onChange={(event) => updateField(['pricing', 'taxRate'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Labor Rate (per hour)</label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.pricing.laborRate}
                    className="mt-1"
                    onChange={(event) => updateField(['pricing', 'laborRate'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Call-out Fee</label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.pricing.callOutFee}
                    className="mt-1"
                    onChange={(event) => updateField(['pricing', 'callOutFee'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Mileage Rate</label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.pricing.mileageRate}
                    className="mt-1"
                    onChange={(event) => updateField(['pricing', 'mileageRate'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Storage Daily Fee</label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.storageFees.dailyStorage}
                    className="mt-1"
                    onChange={(event) => updateField(['storageFees', 'dailyStorage'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Storage Gate Fee</label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.storageFees.gateFee}
                    className="mt-1"
                    onChange={(event) => updateField(['storageFees', 'gateFee'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Impound Fee</label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.storageFees.impoundFee}
                    className="mt-1"
                    onChange={(event) => updateField(['storageFees', 'impoundFee'], event.target.value)}
                  />
                </div>
              </div>
            </Card>
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
      )}
    </div>
  )
}
