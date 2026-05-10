import { useCallback, useEffect, useState } from 'react'
import { useLocation, useParams } from 'react-router-dom'

import contractsService from '../../../services/contracts.service'
import Card from '../../components/ui/Card'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Textarea from '../../components/ui/Textarea'
import SignaturePad from '../../components/ui/SignaturePad'

/**
 * Unauthenticated contract signing page.
 *
 * Two URL shapes resolve here:
 *   - /c/:shortCode               (the short URL embedded in emails)
 *   - /contract/view?token=…      (the secure long-token URL)
 *
 * The page fetches the contract via the matching public endpoint, then
 * captures the signer's name + drawn signature + legal-consent
 * acknowledgement and POSTs to /api/public/contract/sign.
 */
const formatDate = (s) => (s ? new Date(s).toLocaleDateString() : '')

const formatMoney = (cents) =>
  cents == null ? '—' : `$${(cents / 100).toFixed(2)}`

const CONSENT_TEXT =
  'I agree that my electronic signature is the legal equivalent of my ' +
  'handwritten signature, and that I have read and accept this contract.'

export default function PublicContractSign() {
  const { shortCode: routeShortCode } = useParams()
  const location = useLocation()
  const queryToken = new URLSearchParams(location.search).get('token') || ''

  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [contract, setContract] = useState(null)
  const [link, setLink] = useState(null)

  const [signerName, setSignerName] = useState('')
  const [signerEmail, setSignerEmail] = useState('')
  const [signerTitle, setSignerTitle] = useState('')
  const [signatureData, setSignatureData] = useState('')
  const [legalConsent, setLegalConsent] = useState(false)
  const [comment, setComment] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState('')
  const [submitted, setSubmitted] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError('')
    try {
      const result = routeShortCode
        ? await contractsService.publicByCode(routeShortCode)
        : await contractsService.publicView(queryToken)
      const payload = result?.data ?? result
      setContract(payload?.contract ?? null)
      setLink(payload?.link ?? null)
    } catch (err) {
      setLoadError(
        err.response?.data?.error
          || err.response?.data?.message
          || 'Unable to load the contract for signing.',
      )
    } finally {
      setLoading(false)
    }
  }, [routeShortCode, queryToken])

  useEffect(() => { load() }, [load])

  const submit = async () => {
    if (!signerName.trim()) {
      setSubmitError('Please enter your name.')
      return
    }
    if (!signatureData) {
      setSubmitError('Please draw your signature.')
      return
    }
    if (!legalConsent) {
      setSubmitError('Please acknowledge the legal consent statement.')
      return
    }
    setSubmitting(true)
    setSubmitError('')
    try {
      const payload = {
        signer_name: signerName.trim(),
        signer_email: signerEmail.trim() || null,
        signer_title: signerTitle.trim() || null,
        signature_data: signatureData,
        legal_consent: true,
        consent_text: CONSENT_TEXT,
        comment: comment.trim() || null,
      }
      if (routeShortCode) payload.short_code = routeShortCode
      else payload.token = queryToken
      await contractsService.publicSign(payload)
      setSubmitted(true)
    } catch (err) {
      setSubmitError(
        err.response?.data?.error
          || err.response?.data?.message
          || 'Unable to record your signature. Please try again.',
      )
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <Loading text="Loading contract…" />
      </div>
    )
  }

  if (loadError) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 p-6">
        <Card className="max-w-md w-full">
          <Alert variant="error" closable={false}>{loadError}</Alert>
          <p className="mt-4 text-sm text-gray-600">
            If this link was emailed to you and is no longer working, please
            contact the sender for a fresh signing link.
          </p>
        </Card>
      </div>
    )
  }

  if (submitted) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 p-6">
        <Card className="max-w-md w-full text-center space-y-3">
          <h1 className="text-2xl font-semibold">Thank you</h1>
          <p className="text-sm text-gray-600">
            Your signature for contract <span className="font-mono">{contract?.contract_number}</span>
            {' '}has been recorded. A copy has been sent to your email if one was provided.
          </p>
        </Card>
      </div>
    )
  }

  const alreadySigned = !!contract?.signed_at
  const cannotSign = ['cancelled', 'renewed', 'expired'].includes(contract?.status)

  return (
    <div className="min-h-screen bg-gray-50 py-10 px-4">
      <div className="max-w-3xl mx-auto space-y-6">
        <header>
          <h1 className="text-2xl font-semibold">Sign contract</h1>
          {link?.expires_at && (
            <p className="text-xs text-gray-500 mt-1">
              This link expires {formatDate(link.expires_at)}.
            </p>
          )}
        </header>

        <Card>
          <dl className="grid grid-cols-1 sm:grid-cols-2 gap-y-3 gap-x-6 text-sm">
            <div>
              <dt className="text-gray-500">Contract number</dt>
              <dd className="font-mono">{contract?.contract_number || '—'}</dd>
            </div>
            <div>
              <dt className="text-gray-500">Status</dt>
              <dd className="capitalize">{contract?.status?.replace(/_/g, ' ') || '—'}</dd>
            </div>
            <div className="sm:col-span-2">
              <dt className="text-gray-500">Title</dt>
              <dd className="font-medium">{contract?.title || '—'}</dd>
            </div>
            {contract?.description && (
              <div className="sm:col-span-2">
                <dt className="text-gray-500">Description</dt>
                <dd className="whitespace-pre-wrap">{contract.description}</dd>
              </div>
            )}
            <div>
              <dt className="text-gray-500">Term</dt>
              <dd>
                {formatDate(contract?.start_date)}
                {contract?.end_date ? ` – ${formatDate(contract.end_date)}` : ''}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500">Billing</dt>
              <dd>
                {contract?.billing_frequency
                  ? `${formatMoney(contract.billing_amount_cents)} / ${contract.billing_frequency}`
                  : '—'}
              </dd>
            </div>
          </dl>
        </Card>

        {contract?.terms_markdown && (
          <Card title="Terms">
            <pre className="text-sm whitespace-pre-wrap font-sans text-gray-800">
              {contract.terms_markdown}
            </pre>
          </Card>
        )}

        {alreadySigned && (
          <Alert variant="info" closable={false}>
            This contract has already been signed on {formatDate(contract.signed_at)}.
            You can still co-sign below if you are an additional party.
          </Alert>
        )}
        {cannotSign && (
          <Alert variant="warning" closable={false}>
            This contract is in &ldquo;{contract.status}&rdquo; status and cannot be signed.
          </Alert>
        )}

        {!cannotSign && (
          <Card title="Your signature">
            <div className="space-y-4">
              {submitError && <Alert variant="error" closable={false}>{submitError}</Alert>}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Input
                  label="Full name"
                  modelValue={signerName}
                  onUpdateModelValue={setSignerName}
                  required
                  disabled={submitting}
                />
                <Input
                  label="Email (optional)"
                  type="email"
                  modelValue={signerEmail}
                  onUpdateModelValue={setSignerEmail}
                  disabled={submitting}
                />
                <Input
                  label="Title (optional)"
                  modelValue={signerTitle}
                  onUpdateModelValue={setSignerTitle}
                  disabled={submitting}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Draw your signature
                </label>
                <SignaturePad onChange={setSignatureData} disabled={submitting} />
              </div>
              <Textarea
                label="Comment (optional)"
                modelValue={comment}
                onUpdateModelValue={setComment}
                rows={2}
                disabled={submitting}
              />
              <label className="flex items-start gap-2 text-sm text-gray-700">
                <input
                  type="checkbox"
                  className="mt-0.5"
                  checked={legalConsent}
                  onChange={(e) => setLegalConsent(e.target.checked)}
                  disabled={submitting}
                />
                <span>{CONSENT_TEXT}</span>
              </label>
              <div className="flex justify-end">
                <Button
                  onClick={submit}
                  loading={submitting}
                  disabled={submitting || !signerName.trim() || !signatureData || !legalConsent}
                >
                  Sign contract
                </Button>
              </div>
            </div>
          </Card>
        )}
      </div>
    </div>
  )
}
