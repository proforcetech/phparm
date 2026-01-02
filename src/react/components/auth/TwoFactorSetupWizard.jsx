import { useState } from 'react'

import Button from '../ui/Button'
import Input from '../ui/Input'
import Alert from '../ui/Alert'
import Card from '../ui/Card'

export default function TwoFactorSetupWizard({
  issuer = 'Shop Portal',
  accountLabel,
  onComplete,
  onCancel,
  loading = false,
  error,
}) {
  const [code, setCode] = useState('')

  const handleSubmit = (event) => {
    event.preventDefault()
    if (onComplete) {
      onComplete(code)
    }
  }

  return (
    <Card>
      <div className="space-y-4">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Set up two-factor authentication</h2>
          <p className="text-sm text-gray-500">
            Add {issuer} to your authenticator app and confirm the 6-digit code to finish setup.
          </p>
          {accountLabel ? (
            <p className="text-xs text-gray-400">Account: {accountLabel}</p>
          ) : null}
        </div>

        {error ? (
          <Alert variant="error" message={error} />
        ) : null}

        <form className="space-y-3" onSubmit={handleSubmit}>
          <Input
            label="Verification code"
            placeholder="123456"
            value={code}
            onChange={(event) => setCode(event.target.value)}
          />

          <div className="flex items-center gap-3">
            <Button type="submit" loading={loading} disabled={!code}>
              Verify &amp; enable
            </Button>
            {onCancel ? (
              <Button variant="ghost" type="button" onClick={onCancel}>
                Cancel
              </Button>
            ) : null}
          </div>
        </form>
      </div>
    </Card>
  )
}
