import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Textarea from '../../components/ui/Textarea'

export function ShopProfileForm({ form, updateField }) {
  return (
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
  )
}

export function TermsForm({ form, updateField }) {
  return (
    <Card>
      <h2 className="text-lg font-semibold text-gray-900 mb-4">Terms &amp; Documents</h2>
      <div className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-gray-700">Estimate Terms</label>
          <Textarea
            value={form.terms.estimates}
            rows={6}
            placeholder="Terms shown on estimates"
            className="mt-1"
            onChange={(event) => updateField(['terms', 'estimates'], event.target.value)}
          />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700">Invoice Terms</label>
          <Textarea
            value={form.terms.invoices}
            rows={6}
            placeholder="Terms shown on invoices"
            className="mt-1"
            onChange={(event) => updateField(['terms', 'invoices'], event.target.value)}
          />
        </div>
      </div>
    </Card>
  )
}

export function PricingForm({ form, updateField }) {
  return (
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
  )
}

export function NotificationsForm({ form, updateField }) {
  return (
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
  )
}

export function PaymentsForm({ form, updateField }) {
  return (
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
  )
}

export function IntegrationsForm({ form, updateField }) {
  return (
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
  )
}

export function RejectionReasonsForm({ form, updateField }) {
  return (
    <Card>
      <h2 className="text-lg font-semibold text-gray-900 mb-4">Rejection Reasons</h2>
      <div>
        <label className="block text-sm font-medium text-gray-700">Customer-facing reasons</label>
        <Textarea
          value={form.rejectionReasons}
          rows={8}
          placeholder="Price too high\nFound a better deal elsewhere\nOther"
          className="mt-1"
          onChange={(event) => updateField(['rejectionReasons'], event.target.value)}
        />
        <p className="mt-2 text-xs text-gray-500">Enter one reason per line.</p>
      </div>
    </Card>
  )
}
