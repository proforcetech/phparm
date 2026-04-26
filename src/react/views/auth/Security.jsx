import { useEffect, useRef, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import { authService } from '../../../services/auth.service'
import { useAuthStore } from '../../stores/auth.jsx'
import { useToast } from '../../stores/toast.jsx'

const LOW_CODE_THRESHOLD = 2

export default function Security() {
  const toast = useToast()
  const { user } = useAuthStore()
  const [loading, setLoading] = useState(true)
  const [count, setCount] = useState(0)
  const [enabled, setEnabled] = useState(false)
  const [showRegenModal, setShowRegenModal] = useState(false)
  const [verificationCode, setVerificationCode] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)
  const [newCodes, setNewCodes] = useState([])
  const [codesCopied, setCodesCopied] = useState(false)
  const copiedTimeoutRef = useRef(null)

  const loadCount = async () => {
    setLoading(true)
    try {
      const data = await authService.getRecoveryCodeCount()
      setCount(data.count ?? 0)
      setEnabled(Boolean(data.enabled))
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to load security status')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadCount()
    return () => {
      if (copiedTimeoutRef.current) clearTimeout(copiedTimeoutRef.current)
    }
  }, [])

  const openRegen = () => {
    setVerificationCode('')
    setError(null)
    setNewCodes([])
    setShowRegenModal(true)
  }

  const submitRegen = async () => {
    if (verificationCode.length !== 6) return
    setSubmitting(true)
    setError(null)
    try {
      const data = await authService.regenerateRecoveryCodes(verificationCode)
      setNewCodes(data.recovery_codes || [])
      toast.success('Recovery codes regenerated')
      loadCount()
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to regenerate recovery codes')
      setVerificationCode('')
    } finally {
      setSubmitting(false)
    }
  }

  const closeRegen = () => {
    setShowRegenModal(false)
    setNewCodes([])
    setVerificationCode('')
    setError(null)
  }

  const copyCodes = async () => {
    const text = newCodes.map((c, i) => `${i + 1}. ${c}`).join('\n')
    await navigator.clipboard.writeText(text)
    setCodesCopied(true)
    if (copiedTimeoutRef.current) clearTimeout(copiedTimeoutRef.current)
    copiedTimeoutRef.current = setTimeout(() => setCodesCopied(false), 2000)
  }

  if (loading) {
    return (
      <div className="p-6">
        <Loading />
      </div>
    )
  }

  const isLow = enabled && count <= LOW_CODE_THRESHOLD

  return (
    <div className="space-y-6 p-6">
      <div>
        <h1 className="text-2xl font-semibold text-gray-900">Security</h1>
        <p className="mt-1 text-sm text-gray-600">
          Manage your two-factor authentication and recovery codes.
        </p>
      </div>

      <Card>
        <div className="space-y-4 p-6">
          <div className="flex items-start justify-between">
            <div>
              <h2 className="text-lg font-medium text-gray-900">Two-Factor Authentication</h2>
              <p className="mt-1 text-sm text-gray-600">
                Protects your account with a second authentication factor.
              </p>
            </div>
            {user?.two_factor_enabled ? (
              <Badge variant="success">Enabled</Badge>
            ) : (
              <Badge variant="secondary">Disabled</Badge>
            )}
          </div>

          {user?.two_factor_type ? (
            <div className="text-sm text-gray-700">
              <span className="font-medium">Method:</span>{' '}
              {user.two_factor_type === 'totp' ? 'Authenticator app (TOTP)' : user.two_factor_type}
            </div>
          ) : null}
        </div>
      </Card>

      {enabled ? (
        <Card>
          <div className="space-y-4 p-6">
            <div className="flex items-start justify-between">
              <div>
                <h2 className="text-lg font-medium text-gray-900">Recovery Codes</h2>
                <p className="mt-1 text-sm text-gray-600">
                  One-time codes you can use to sign in if you lose access to your authenticator app.
                </p>
              </div>
              <Badge variant={isLow ? 'danger' : 'primary'}>
                {count} remaining
              </Badge>
            </div>

            {count === 0 ? (
              <Alert variant="warning">
                <p className="font-medium">No recovery codes on file.</p>
                <p className="mt-1 text-sm">
                  If you lose access to your authenticator, you will be locked out. Regenerate a
                  new set of codes now.
                </p>
              </Alert>
            ) : isLow ? (
              <Alert variant="warning">
                Only {count} recovery {count === 1 ? 'code' : 'codes'} remaining. Regenerate to get
                a fresh set of 8.
              </Alert>
            ) : null}

            <div className="flex gap-2">
              <Button onClick={openRegen}>Regenerate Recovery Codes</Button>
            </div>

            <p className="text-xs text-gray-500">
              Regenerating invalidates your existing codes. You will need a current authenticator
              code to confirm.
            </p>
          </div>
        </Card>
      ) : (
        <Card>
          <div className="p-6 text-sm text-gray-600">
            Two-factor authentication is not enabled. Recovery codes apply once 2FA is set up.
          </div>
        </Card>
      )}

      <Modal
        open={showRegenModal}
        title={newCodes.length ? 'Save Your New Recovery Codes' : 'Regenerate Recovery Codes'}
        onClose={closeRegen}
        footer={
          <div className="flex justify-end gap-2">
            {newCodes.length === 0 ? (
              <>
                <Button variant="outline" onClick={closeRegen}>Cancel</Button>
                <Button
                  onClick={submitRegen}
                  loading={submitting}
                  disabled={verificationCode.length !== 6}
                >
                  Confirm
                </Button>
              </>
            ) : (
              <Button onClick={closeRegen}>Done</Button>
            )}
          </div>
        }
      >
        {newCodes.length === 0 ? (
          <div className="space-y-4">
            <p className="text-sm text-gray-600">
              Enter the current 6-digit code from your authenticator app to confirm.
            </p>
            <Input
              type="text"
              inputMode="numeric"
              maxLength={6}
              placeholder="000000"
              value={verificationCode}
              onChange={(e) => setVerificationCode(e.target.value.replace(/\D/g, ''))}
              className="text-center text-2xl tracking-widest"
              autoFocus
            />
            {error ? (
              <Alert variant="danger">{error}</Alert>
            ) : null}
          </div>
        ) : (
          <div className="space-y-4">
            <Alert variant="warning">
              <p className="font-medium">Save these codes now.</p>
              <p className="mt-1 text-sm">Each code works once. They will not be shown again.</p>
            </Alert>
            <div className="rounded border border-yellow-300 bg-yellow-50 p-4">
              <div className="grid grid-cols-2 gap-2 font-mono text-sm">
                {newCodes.map((code, idx) => (
                  <div key={`${code}-${idx}`} className="text-gray-900">
                    {idx + 1}. {code}
                  </div>
                ))}
              </div>
            </div>
            <Button variant="outline" onClick={copyCodes} className="w-full">
              {codesCopied ? 'Copied!' : 'Copy All Codes'}
            </Button>
          </div>
        )}
      </Modal>
    </div>
  )
}
