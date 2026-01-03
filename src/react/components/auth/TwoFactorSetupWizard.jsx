import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import { authService } from '../../../services/auth.service'
import { useAuthStore } from '../../stores/auth.jsx'

export default function TwoFactorSetupWizard() {
  const navigate = useNavigate()
  const authStore = useAuthStore()
  const [qrCodeUrl, setQrCodeUrl] = useState('')
  const [secret, setSecret] = useState('')
  const [verificationCode, setVerificationCode] = useState('')
  const [recoveryCodes, setRecoveryCodes] = useState([])
  const [showRecoveryCodes, setShowRecoveryCodes] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [copied, setCopied] = useState(false)
  const [codesCopied, setCodesCopied] = useState(false)
  const copiedTimeoutRef = useRef(null)
  const codesTimeoutRef = useRef(null)

  const qrCodeImageUrl = useMemo(() => {
    if (!qrCodeUrl) return ''
    return `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(
      qrCodeUrl
    )}`
  }, [qrCodeUrl])

  useEffect(() => {
    let isMounted = true

    const fetchSetup = async () => {
      try {
        setLoading(true)
        const data = await authService.initiateTwoFactorSetup()
        if (!isMounted) return
        setQrCodeUrl(data.qr_code_url)
        setSecret(data.secret)
      } catch (err) {
        if (!isMounted) return
        setError(err.response?.data?.message || 'Failed to initialize 2FA setup')
      } finally {
        if (isMounted) {
          setLoading(false)
        }
      }
    }

    fetchSetup()

    return () => {
      isMounted = false
      if (copiedTimeoutRef.current) {
        clearTimeout(copiedTimeoutRef.current)
      }
      if (codesTimeoutRef.current) {
        clearTimeout(codesTimeoutRef.current)
      }
    }
  }, [])

  const completeSetup = async () => {
    if (verificationCode.length !== 6) {
      return
    }

    setLoading(true)
    setError(null)

    try {
      const data = await authService.completeTwoFactorSetup(verificationCode)
      setRecoveryCodes(data.recovery_codes || [])
      setShowRecoveryCodes(true)

      if (data.user) {
        localStorage.setItem('user', JSON.stringify(data.user))
        authStore.checkAuth()
      }
    } catch (err) {
      setError(err.response?.data?.message || 'Invalid verification code. Please try again.')
      setVerificationCode('')
    } finally {
      setLoading(false)
    }
  }

  const copySecret = async () => {
    await navigator.clipboard.writeText(secret)
    setCopied(true)
    if (copiedTimeoutRef.current) {
      clearTimeout(copiedTimeoutRef.current)
    }
    copiedTimeoutRef.current = setTimeout(() => {
      setCopied(false)
    }, 2000)
  }

  const copyRecoveryCodes = async () => {
    const codes = recoveryCodes.map((code, index) => `${index + 1}. ${code}`).join('\n')
    await navigator.clipboard.writeText(codes)
    setCodesCopied(true)
    if (codesTimeoutRef.current) {
      clearTimeout(codesTimeoutRef.current)
    }
    codesTimeoutRef.current = setTimeout(() => {
      setCodesCopied(false)
    }, 2000)
  }

  const finishSetup = () => {
    if (authStore.isCustomer) {
      navigate('/portal')
    } else {
      navigate('/cp/dashboard')
    }
  }

  return (
    <div className="fixed inset-0 flex h-full w-full items-center justify-center overflow-y-auto bg-gray-600 bg-opacity-50">
      <div className="relative mx-4 w-full max-w-2xl rounded-lg bg-white shadow-xl">
        <div className="rounded-t-lg bg-primary-600 px-6 py-4 text-white">
          <h3 className="text-lg font-semibold">Two-Factor Authentication Setup Required</h3>
          <p className="mt-1 text-sm text-primary-100">
            Your administrator has required you to set up two-factor authentication
          </p>
        </div>

        <div className="p-6">
          {!showRecoveryCodes ? (
            <div className="space-y-4">
              <div className="text-center">
                <h4 className="mb-2 text-lg font-medium text-gray-900">Scan the QR Code</h4>
                <p className="mb-4 text-sm text-gray-600">
                  Use an authenticator app like Google Authenticator, Authy, or 1Password to scan
                  this QR code
                </p>
              </div>

              {qrCodeImageUrl ? (
                <div className="flex justify-center rounded-lg bg-gray-50 p-6">
                  <img
                    src={qrCodeImageUrl}
                    alt="2FA QR Code"
                    className="border-4 border-white shadow-lg"
                  />
                </div>
              ) : null}

              <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
                <p className="mb-2 text-sm font-medium text-blue-900">Can't scan the QR code?</p>
                <p className="mb-2 text-xs text-blue-700">
                  Enter this code manually in your authenticator app:
                </p>
                <div className="flex items-center justify-between rounded border border-blue-300 bg-white px-3 py-2">
                  <code className="text-sm font-mono text-gray-900">{secret}</code>
                  <button
                    type="button"
                    onClick={copySecret}
                    className="ml-2 text-sm font-medium text-blue-600 hover:text-blue-800"
                  >
                    {copied ? 'Copied!' : 'Copy'}
                  </button>
                </div>
              </div>

              <div className="mt-6">
                <label
                  htmlFor="verification-code"
                  className="mb-2 block text-sm font-medium text-gray-700"
                >
                  Enter the 6-digit code from your authenticator app
                </label>
                <input
                  id="verification-code"
                  value={verificationCode}
                  onChange={(event) => setVerificationCode(event.target.value)}
                  type="text"
                  inputMode="numeric"
                  maxLength={6}
                  placeholder="000000"
                  className="block w-full rounded-md border border-gray-300 px-3 py-2 text-center text-2xl tracking-widest shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
                  disabled={loading}
                  onKeyUp={(event) => {
                    if (event.key === 'Enter') {
                      completeSetup()
                    }
                  }}
                />
              </div>

              {error ? (
                <div className="rounded-lg border border-red-200 bg-red-50 p-4">
                  <p className="text-sm text-red-800">{error}</p>
                </div>
              ) : null}

              <div className="mt-6 flex justify-end space-x-3 border-t pt-6">
                <button
                  type="button"
                  onClick={completeSetup}
                  disabled={loading || verificationCode.length !== 6}
                  className="rounded-md bg-primary-600 px-4 py-2 text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {loading ? 'Verifying...' : 'Verify and Enable 2FA'}
                </button>
              </div>
            </div>
          ) : (
            <div className="space-y-4">
              <div className="mb-4 text-center">
                <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
                  <svg className="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth="2"
                      d="M5 13l4 4L19 7"
                    ></path>
                  </svg>
                </div>
                <h4 className="text-lg font-medium text-gray-900">2FA Successfully Enabled!</h4>
                <p className="mt-2 text-sm text-gray-600">
                  Save these recovery codes in a safe place. You can use them to access your
                  account if you lose your authenticator device.
                </p>
              </div>

              <div className="rounded-lg border-2 border-yellow-400 bg-yellow-50 p-4">
                <p className="mb-3 text-sm font-semibold text-yellow-900">
                  ⚠️ Recovery Codes - Save These Now!
                </p>
                <div className="rounded border border-yellow-300 bg-white p-4">
                  <div className="grid grid-cols-2 gap-2 font-mono text-sm">
                    {recoveryCodes.map((code, index) => (
                      <div key={`${code}-${index}`} className="text-gray-900">
                        {index + 1}. {code}
                      </div>
                    ))}
                  </div>
                </div>
                <button
                  type="button"
                  onClick={copyRecoveryCodes}
                  className="mt-3 w-full rounded-md bg-yellow-600 px-4 py-2 text-white hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-yellow-500"
                >
                  {codesCopied ? 'Copied!' : 'Copy All Recovery Codes'}
                </button>
              </div>

              <div className="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
                <p className="mb-1 font-medium">Important:</p>
                <ul className="list-inside list-disc space-y-1">
                  <li>Each recovery code can only be used once</li>
                  <li>Store them in a secure password manager or print them</li>
                  <li>You won't be able to see these codes again</li>
                </ul>
              </div>

              <div className="mt-6 flex justify-end border-t pt-6">
                <button
                  type="button"
                  onClick={finishSetup}
                  className="rounded-md bg-primary-600 px-6 py-2 text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500"
                >
                  Continue to Dashboard
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
