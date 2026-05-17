import { useEffect, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import estimateService from '../../../services/estimate.service'
import { useToast } from '../../stores/toast'

const TABS = [
  { value: 'link', label: 'Copy URL' },
  { value: 'email', label: 'Email' },
  { value: 'sms', label: 'SMS' },
]

export default function ShareEstimateModal({ open, estimate, onClose }) {
  const toast = useToast()
  const [tab, setTab] = useState('link')
  const [shareUrl, setShareUrl] = useState('')
  const [issuingLink, setIssuingLink] = useState(false)
  const [linkError, setLinkError] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [sending, setSending] = useState(false)
  const [sendError, setSendError] = useState('')

  useEffect(() => {
    if (!open || !estimate?.id) return
    let cancelled = false

    setTab('link')
    setShareUrl('')
    setLinkError('')
    setSendError('')
    setEmail(estimate.customer?.email || '')
    setPhone(estimate.customer?.phone || '')

    setIssuingLink(true)
    estimateService
      .getShareLink(estimate.id)
      .then((response) => {
        if (cancelled) return
        const data = response?.data ?? {}
        setShareUrl(data.secure_url || data.short_url || '')
      })
      .catch((error) => {
        if (cancelled) return
        setLinkError(error?.response?.data?.error || error?.response?.data?.message || 'Failed to issue share link.')
      })
      .finally(() => {
        if (!cancelled) setIssuingLink(false)
      })

    return () => {
      cancelled = true
    }
  }, [open, estimate?.id, estimate?.customer?.email, estimate?.customer?.phone])

  const copyUrl = async () => {
    if (!shareUrl) return
    try {
      await navigator.clipboard.writeText(shareUrl)
      toast.success?.('Link copied to clipboard.')
    } catch {
      toast.error?.('Could not access clipboard. Select and copy manually.')
    }
  }

  const sendEmail = async () => {
    if (!email.trim()) {
      setSendError('Email address is required.')
      return
    }
    setSending(true)
    setSendError('')
    try {
      await estimateService.shareViaEmail(estimate.id, email.trim())
      toast.success?.(`Estimate emailed to ${email.trim()}.`)
      onClose?.()
    } catch (error) {
      setSendError(error?.response?.data?.error || error?.response?.data?.message || 'Failed to send email.')
    } finally {
      setSending(false)
    }
  }

  const sendSms = async () => {
    if (!phone.trim()) {
      setSendError('Phone number is required.')
      return
    }
    setSending(true)
    setSendError('')
    try {
      await estimateService.shareViaSms(estimate.id, phone.trim())
      toast.success?.(`Estimate texted to ${phone.trim()}.`)
      onClose?.()
    } catch (error) {
      setSendError(error?.response?.data?.error || error?.response?.data?.message || 'Failed to send SMS.')
    } finally {
      setSending(false)
    }
  }

  return (
    <Modal
      open={open}
      onClose={() => (sending ? null : onClose?.())}
      title={`Share estimate ${estimate?.number ? `#${estimate.number}` : ''}`.trim()}
    >
      <div className="space-y-4">
        <div className="border-b border-gray-200">
          <nav className="-mb-px flex gap-4" aria-label="Share methods">
            {TABS.map((option) => (
              <button
                key={option.value}
                type="button"
                onClick={() => {
                  setTab(option.value)
                  setSendError('')
                }}
                className={`whitespace-nowrap py-2 px-1 border-b-2 text-sm font-medium ${
                  tab === option.value
                    ? 'border-primary-600 text-primary-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                }`}
              >
                {option.label}
              </button>
            ))}
          </nav>
        </div>

        {tab === 'link' ? (
          <div className="space-y-3">
            <p className="text-sm text-gray-600">
              Anyone with this secure link can view and respond to the estimate.
            </p>
            {linkError ? <Alert variant="danger">{linkError}</Alert> : null}
            <div className="flex gap-2">
              <Input
                value={issuingLink ? 'Issuing link…' : shareUrl}
                readOnly
                className="flex-1"
              />
              <Button
                type="button"
                onClick={copyUrl}
                disabled={!shareUrl || issuingLink}
              >
                Copy
              </Button>
            </div>
            {shareUrl ? (
              <a
                href={shareUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="text-xs text-primary-600 hover:text-primary-800"
              >
                Open in new tab
              </a>
            ) : null}
          </div>
        ) : null}

        {tab === 'email' ? (
          <div className="space-y-3">
            <p className="text-sm text-gray-600">
              Send the estimate link to a customer's email.
            </p>
            {sendError ? <Alert variant="danger">{sendError}</Alert> : null}
            <div>
              <label className="block text-sm font-medium text-gray-700">Email address</label>
              <Input
                value={email}
                type="email"
                placeholder="customer@example.com"
                className="mt-1"
                onUpdateModelValue={setEmail}
              />
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button variant="outline" type="button" onClick={onClose} disabled={sending}>
                Cancel
              </Button>
              <Button type="button" onClick={sendEmail} disabled={sending} loading={sending}>
                Send email
              </Button>
            </div>
          </div>
        ) : null}

        {tab === 'sms' ? (
          <div className="space-y-3">
            <p className="text-sm text-gray-600">
              Text the secure approval link to the customer's mobile.
            </p>
            {sendError ? <Alert variant="danger">{sendError}</Alert> : null}
            <div>
              <label className="block text-sm font-medium text-gray-700">Phone number</label>
              <Input
                value={phone}
                type="tel"
                placeholder="+1 555 555 5555"
                className="mt-1"
                onUpdateModelValue={setPhone}
              />
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button variant="outline" type="button" onClick={onClose} disabled={sending}>
                Cancel
              </Button>
              <Button type="button" onClick={sendSms} disabled={sending} loading={sending}>
                Send SMS
              </Button>
            </div>
          </div>
        ) : null}
      </div>
    </Modal>
  )
}
