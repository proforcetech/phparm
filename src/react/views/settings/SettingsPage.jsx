import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
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
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Settings</h1>
          <p className="mt-1 text-sm text-gray-500">
            Manage shop profile, terms, pricing defaults, and integrations.
          </p>
        </div>
        <Button loading={saving} onClick={handleSave}>Save Settings</Button>
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
              </div>
            </Card>

            <Card>
              <h2 className="text-lg font-semibold text-gray-900 mb-4">Notifications &amp; Mail</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">From Name</label>
                  <Input
                    value={form.notifications.fromName}
                    placeholder="Demo Auto Shop"
                    className="mt-1"
                    onChange={(event) => updateField(['notifications', 'fromName'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">From Email</label>
                  <Input
                    value={form.notifications.fromAddress}
                    placeholder="noreply@example.com"
                    className="mt-1"
                    onChange={(event) => updateField(['notifications', 'fromAddress'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">SMS From Number</label>
                  <Input
                    value={form.notifications.smsNumber}
                    placeholder="+15551234567"
                    className="mt-1"
                    onChange={(event) => updateField(['notifications', 'smsNumber'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Twilio SID</label>
                  <Input
                    value={form.notifications.twilioSid}
                    placeholder="ACXXXXXXXXXXXXXXXX"
                    className="mt-1"
                    onChange={(event) => updateField(['notifications', 'twilioSid'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Twilio Token</label>
                  <Input
                    value={form.notifications.twilioToken}
                    placeholder="••••••••"
                    className="mt-1"
                    onChange={(event) => updateField(['notifications', 'twilioToken'], event.target.value)}
                  />
                </div>
              </div>
              <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">SMTP Host</label>
                  <Input
                    value={form.smtp.host}
                    placeholder="smtp.mailgun.org"
                    className="mt-1"
                    onChange={(event) => updateField(['smtp', 'host'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">SMTP Port</label>
                  <Input
                    type="number"
                    min="1"
                    value={form.smtp.port}
                    className="mt-1"
                    onChange={(event) => updateField(['smtp', 'port'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">SMTP Username</label>
                  <Input
                    value={form.smtp.username}
                    placeholder="user"
                    className="mt-1"
                    onChange={(event) => updateField(['smtp', 'username'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">SMTP Password</label>
                  <Input
                    value={form.smtp.password}
                    placeholder="••••••••"
                    className="mt-1"
                    onChange={(event) => updateField(['smtp', 'password'], event.target.value)}
                  />
                </div>
                <div className="md:col-span-2">
                  <label className="block text-sm font-medium text-gray-700">SMTP Encryption</label>
                  <Input
                    value={form.smtp.encryption}
                    placeholder="tls"
                    className="mt-1"
                    onChange={(event) => updateField(['smtp', 'encryption'], event.target.value)}
                  />
                </div>
              </div>
            </Card>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Card>
              <h2 className="text-lg font-semibold text-gray-900 mb-4">Payments</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="md:col-span-2">
                  <label className="block text-sm font-medium text-gray-700">Success URL</label>
                  <Input
                    value={form.payments.successUrl}
                    placeholder="https://app.example.com/payment/success"
                    className="mt-1"
                    onChange={(event) => updateField(['payments', 'successUrl'], event.target.value)}
                  />
                </div>
                <div className="md:col-span-2">
                  <label className="block text-sm font-medium text-gray-700">Cancel URL</label>
                  <Input
                    value={form.payments.cancelUrl}
                    placeholder="https://app.example.com/payment/cancel"
                    className="mt-1"
                    onChange={(event) => updateField(['payments', 'cancelUrl'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Stripe Publishable Key</label>
                  <Input
                    value={form.payments.stripePublic}
                    placeholder="pk_live_"
                    className="mt-1"
                    onChange={(event) => updateField(['payments', 'stripePublic'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Stripe Secret Key</label>
                  <Input
                    value={form.payments.stripeSecret}
                    placeholder="sk_live_"
                    className="mt-1"
                    onChange={(event) => updateField(['payments', 'stripeSecret'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Stripe Webhook Secret</label>
                  <Input
                    value={form.payments.stripeWebhook}
                    placeholder="whsec_"
                    className="mt-1"
                    onChange={(event) => updateField(['payments', 'stripeWebhook'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Square Token</label>
                  <Input
                    value={form.payments.squareToken}
                    placeholder="sq0atp-"
                    className="mt-1"
                    onChange={(event) => updateField(['payments', 'squareToken'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Square Webhook Signature Key</label>
                  <Input
                    value={form.payments.squareSignature}
                    placeholder="sig_key"
                    className="mt-1"
                    onChange={(event) => updateField(['payments', 'squareSignature'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">PayPal Client ID</label>
                  <Input
                    value={form.payments.paypalClientId}
                    placeholder="paypal client id"
                    className="mt-1"
                    onChange={(event) => updateField(['payments', 'paypalClientId'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">PayPal Client Secret</label>
                  <Input
                    value={form.payments.paypalClientSecret}
                    placeholder="paypal secret"
                    className="mt-1"
                    onChange={(event) => updateField(['payments', 'paypalClientSecret'], event.target.value)}
                  />
                </div>
                <div className="md:col-span-2">
                  <label className="block text-sm font-medium text-gray-700">PayPal Webhook ID</label>
                  <Input
                    value={form.payments.paypalWebhook}
                    placeholder="WH-XXXX"
                    className="mt-1"
                    onChange={(event) => updateField(['payments', 'paypalWebhook'], event.target.value)}
                  />
                </div>
              </div>
            </Card>

            <Card>
              <h2 className="text-lg font-semibold text-gray-900 mb-4">Integrations</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="flex items-center space-x-2 md:col-span-2">
                  <input
                    id="recaptcha-enabled"
                    type="checkbox"
                    checked={form.security.recaptchaEnabled}
                    className="h-4 w-4 text-indigo-600 border-gray-300 rounded"
                    onChange={(event) => updateField(['security', 'recaptchaEnabled'], event.target.checked)}
                  />
                  <label htmlFor="recaptcha-enabled" className="block text-sm font-medium text-gray-700">
                    Enable reCAPTCHA
                  </label>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">reCAPTCHA Site Key</label>
                  <Input
                    value={form.security.recaptchaSiteKey}
                    placeholder="site key"
                    className="mt-1"
                    onChange={(event) => updateField(['security', 'recaptchaSiteKey'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">reCAPTCHA Secret Key</label>
                  <Input
                    value={form.security.recaptchaSecretKey}
                    placeholder="secret key"
                    className="mt-1"
                    onChange={(event) => updateField(['security', 'recaptchaSecretKey'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Zoho Client ID</label>
                  <Input
                    value={form.integrations.zohoClientId}
                    placeholder="Zoho client id"
                    className="mt-1"
                    onChange={(event) => updateField(['integrations', 'zohoClientId'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Zoho Client Secret</label>
                  <Input
                    value={form.integrations.zohoClientSecret}
                    placeholder="Zoho client secret"
                    className="mt-1"
                    onChange={(event) => updateField(['integrations', 'zohoClientSecret'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Zoho Refresh Token</label>
                  <Input
                    value={form.integrations.zohoRefreshToken}
                    placeholder="Zoho refresh token"
                    className="mt-1"
                    onChange={(event) => updateField(['integrations', 'zohoRefreshToken'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Zoho Org ID</label>
                  <Input
                    value={form.integrations.zohoOrgId}
                    placeholder="Zoho org id"
                    className="mt-1"
                    onChange={(event) => updateField(['integrations', 'zohoOrgId'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">PartsTech API Base</label>
                  <Input
                    value={form.integrations.partsTechBase}
                    placeholder="https://api.partstech.com"
                    className="mt-1"
                    onChange={(event) => updateField(['integrations', 'partsTechBase'], event.target.value)}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">PartsTech API Key</label>
                  <Input
                    value={form.integrations.partsTechKey}
                    placeholder="PartsTech key"
                    className="mt-1"
                    onChange={(event) => updateField(['integrations', 'partsTechKey'], event.target.value)}
                  />
                </div>
              </div>
              <div className="mt-4">
                <label className="block text-sm font-medium text-gray-700">PartsTech Markup Tiers (JSON)</label>
                <Textarea
                  value={form.integrations.partsTechMarkup}
                  rows={3}
                  placeholder='[{"threshold":0,"markup":0.2}]'
                  className="mt-1"
                  onChange={(event) => updateField(['integrations', 'partsTechMarkup'], event.target.value)}
                />
              </div>
            </Card>
          </div>
        </div>
      )}
    </div>
  )
}
