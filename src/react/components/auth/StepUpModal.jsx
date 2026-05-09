import { useEffect, useRef, useState } from 'react'

import Button from '../ui/Button'
import Modal from '../ui/Modal'
import stepUpService from '../../../services/step-up.service'

export default function StepUpModal({ open, message, onVerified, onCancel }) {
  const [code, setCode] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)
  const inputRef = useRef(null)

  useEffect(() => {
    if (open) {
      setCode('')
      setError(null)
      const handle = setTimeout(() => inputRef.current?.focus(), 50)
      return () => clearTimeout(handle)
    }
  }, [open])

  const submit = async (event) => {
    event?.preventDefault?.()
    const trimmed = code.trim()
    if (!/^\d{6}$/.test(trimmed)) {
      setError('Enter the 6-digit code from your authenticator app.')
      return
    }

    setSubmitting(true)
    setError(null)
    try {
      await stepUpService.verify(trimmed)
      onVerified?.()
    } catch (err) {
      const responseError = err?.response?.data?.error
      const responseMessage = err?.response?.data?.message
      if (responseError === 'totp_not_enrolled') {
        setError(responseMessage || 'TOTP is not enrolled on this account. Set up two-factor first.')
      } else if (responseError === 'invalid_code') {
        setError('That code did not match. Try the next one your app shows.')
      } else {
        setError(responseMessage || 'Unable to verify. Try again.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open={open}
      title="Confirm with your authenticator"
      size="sm"
      closable={false}
      closeOnBackdrop={false}
      onClose={onCancel}
      footer={(
        <div className="flex justify-end gap-3">
          <Button variant="secondary" onClick={onCancel} disabled={submitting}>
            Cancel
          </Button>
          <Button onClick={submit} loading={submitting}>
            Verify
          </Button>
        </div>
      )}
    >
      <form onSubmit={submit} className="space-y-4">
        <p className="text-sm text-gray-600">
          {message || 'This action requires a fresh authenticator code. Enter the 6-digit code from your TOTP app to continue.'}
        </p>
        <div>
          <label htmlFor="step-up-code" className="block text-sm font-medium text-gray-700">
            Authenticator code
          </label>
          <input
            id="step-up-code"
            ref={inputRef}
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            pattern="[0-9]*"
            maxLength={6}
            value={code}
            onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))}
            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-center text-2xl tracking-widest font-mono shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
            placeholder="000000"
            disabled={submitting}
          />
        </div>
        {error ? (
          <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
            {error}
          </div>
        ) : null}
        <p className="text-xs text-gray-500">
          The code stays valid for five minutes so you don&apos;t get re-prompted mid-task.
        </p>
        <button type="submit" hidden aria-hidden="true" />
      </form>
    </Modal>
  )
}
