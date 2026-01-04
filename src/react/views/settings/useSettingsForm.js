import { useCallback, useEffect, useMemo, useState } from 'react'

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
  terms: { estimates: '', invoices: '' },
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
  rejectionReasons: '',
}

const getSetting = (settings, key, fallback = null) => settings?.[key]?.value ?? fallback

const formatRejectionReasons = (reasons) => (Array.isArray(reasons) ? reasons.join('\n') : '')

const parseRejectionReasons = (value) => {
  if (!value) {
    return []
  }

  return value
    .split('\n')
    .map((entry) => entry.trim())
    .filter(Boolean)
}

export default function useSettingsForm() {
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
        const rejectionReasons = formatRejectionReasons(
          getSetting(settings, 'estimates.rejection_reasons', [])
        )

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
          terms: {
            estimates: getSetting(settings, 'documents.terms.estimates', ''),
            invoices: getSetting(settings, 'documents.terms.invoices', ''),
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
          rejectionReasons,
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
      'documents.terms.estimates': form.terms.estimates,
      'documents.terms.invoices': form.terms.invoices,
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
        'estimates.rejection_reasons': parseRejectionReasons(form.rejectionReasons),
      })
      setMessage('Settings saved successfully.')
    } catch (saveError) {
      setError(saveError?.response?.data?.message || saveError?.message || 'Failed to save settings.')
    } finally {
      setSaving(false)
    }
  }

  return {
    error,
    form,
    handleSave,
    loading,
    message,
    saving,
    updateField,
  }
}
