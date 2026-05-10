import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import Card from '../../components/ui/Card'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Textarea from '../../components/ui/Textarea'
import SignaturePad from '../../components/ui/SignaturePad'
import { portalService } from '../../../services/portal/portal.service'
import { usePortalAuth } from '../../stores/portalAuth'
import { PORTAL_PERMISSION } from '../../../services/portal/permissions'

const formatMoney = (cents) => {
  if (cents == null || isNaN(Number(cents))) return '—'
  return `$${(Number(cents) / 100).toFixed(2)}`
}

const formatDate = (s) => (s ? new Date(s).toLocaleDateString() : '—')
const formatDateTime = (s) => (s ? new Date(s).toLocaleString() : '—')

const statusBadge = (status) => {
  switch (status) {
    case 'active': return 'bg-green-100 text-green-800'
    case 'pending_signature': return 'bg-amber-100 text-amber-800'
    case 'draft': return 'bg-gray-100 text-gray-700'
    case 'expired': return 'bg-yellow-100 text-yellow-800'
    case 'cancelled': return 'bg-red-100 text-red-800'
    default: return 'bg-gray-100 text-gray-700'
  }
}

const CONSENT_TEXT =
  'I agree that my electronic signature is the legal equivalent of my ' +
  'handwritten signature, and that I have read and accept this contract.'

export default function PortalContractDetail() {
  const { id } = useParams()
  const { user, can } = usePortalAuth()
  const canSign = can(PORTAL_PERMISSION.SIGN_CONTRACTS)

  const [contract, setContract] = useState(null)
  const [signatures, setSignatures] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [signerName, setSignerName] = useState('')
  const [signerTitle, setSignerTitle] = useState('')
  const [signatureData, setSignatureData] = useState('')
  const [comment, setComment] = useState('')
  const [legalConsent, setLegalConsent] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState('')
  const [submitSuccess, setSubmitSuccess] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const c = await portalService.getContract(id)
      setContract(c)
      if (canSign) {
        try {
          const sigs = await portalService.listContractSignatures(id)
          setSignatures(Array.isArray(sigs) ? sigs : [])
        } catch {
          setSignatures([])
        }
      }
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load contract.')
    } finally {
      setLoading(false)
    }
  }, [id, canSign])

  useEffect(() => {
    load()
  }, [load])

  // Pre-fill the signer name from the portal user once known.
  useEffect(() => {
    if (!signerName && user?.name) setSignerName(user.name)
  }, [user, signerName])

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
    setSubmitSuccess('')
    try {
      await portalService.signContract(id, {
        signer_name: signerName.trim(),
        signer_title: signerTitle.trim() || null,
        signature_data: signatureData,
        comment: comment.trim() || null,
        legal_consent: true,
        consent_text: CONSENT_TEXT,
      })
      setSubmitSuccess('Signature recorded.')
      setSignatureData('')
      setComment('')
      setLegalConsent(false)
      load()
    } catch (err) {
      setSubmitError(
        err.response?.data?.error
          || err.response?.data?.message
          || 'Unable to record your signature.',
      )
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) {
    return (
      <Card>
        <div className="py-10 flex justify-center"><Loading text="Loading contract…" /></div>
      </Card>
    )
  }
  if (error) return <Alert variant="error" closable={false}>{error}</Alert>
  if (!contract) return <Alert variant="warning" closable={false}>Contract not found.</Alert>

  const signable = ['draft', 'pending_signature', 'active'].includes(contract.status)
  const blocked = ['cancelled', 'renewed', 'expired'].includes(contract.status)

  return (
    <div className="space-y-6">
      <div>
        <Link to="/p/contracts" className="text-sm hover:underline" style={{ color: 'var(--portal-primary, #2563eb)' }}>
          ← Back to contracts
        </Link>
      </div>

      <Card>
        <div className="flex items-start justify-between gap-4 flex-wrap">
          <div>
            <h1 className="text-2xl font-semibold">{contract.title || contract.contract_number}</h1>
            <p className="text-sm text-gray-500 mt-1">
              {contract.contract_number} · {contract.contract_type}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <span className={`text-xs px-2 py-0.5 rounded-full ${statusBadge(contract.status)}`}>
              {contract.status}
            </span>
            {contract.kind === 'renewal' && (
              <span className="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-800">
                Renewal
              </span>
            )}
          </div>
        </div>

        {contract.description && (
          <p className="mt-4 text-sm text-gray-700 whitespace-pre-line">{contract.description}</p>
        )}

        <dl className="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
          <div>
            <dt className="text-gray-500">Term</dt>
            <dd className="font-medium">{formatDate(contract.start_date)} – {formatDate(contract.end_date)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Billing</dt>
            <dd className="font-medium">{formatMoney(contract.billing_amount_cents)} {contract.billing_frequency}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Auto renew</dt>
            <dd className="font-medium">{contract.auto_renew ? 'Yes' : 'No'}</dd>
          </div>
          {contract.renewal_term_months && (
            <div>
              <dt className="text-gray-500">Renewal term</dt>
              <dd className="font-medium">{contract.renewal_term_months} months</dd>
            </div>
          )}
          <div>
            <dt className="text-gray-500">Renewal notice</dt>
            <dd className="font-medium">{contract.renewal_notice_days} days</dd>
          </div>
          {contract.signed_at && (
            <div>
              <dt className="text-gray-500">Signed</dt>
              <dd className="font-medium">{formatDate(contract.signed_at)}</dd>
            </div>
          )}
          {contract.cancelled_at && (
            <div>
              <dt className="text-gray-500">Cancelled</dt>
              <dd className="font-medium">{formatDate(contract.cancelled_at)}</dd>
            </div>
          )}
        </dl>

        {contract.cancellation_reason && (
          <Alert variant="warning" closable={false} className="mt-4">
            <strong>Cancellation reason:</strong> {contract.cancellation_reason}
          </Alert>
        )}
      </Card>

      {contract.terms_markdown && (
        <Card>
          <h2 className="text-lg font-semibold mb-3">Terms</h2>
          <pre className="text-sm whitespace-pre-wrap font-sans text-gray-800">
            {contract.terms_markdown}
          </pre>
        </Card>
      )}

      {canSign && signable && !blocked && (
        <Card>
          <h2 className="text-lg font-semibold mb-1">Sign this contract</h2>
          <p className="text-sm text-gray-500 mb-4">
            Your signature will be captured along with a forensic hash of the
            contract content and your IP address for audit purposes.
          </p>
          <div className="space-y-4">
            {submitError && <Alert variant="error" closable={false}>{submitError}</Alert>}
            {submitSuccess && <Alert variant="success" closable={false}>{submitSuccess}</Alert>}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <Input
                label="Full name"
                modelValue={signerName}
                onUpdateModelValue={setSignerName}
                required
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

      {canSign && signatures.length > 0 && (
        <Card>
          <h2 className="text-lg font-semibold mb-3">Signature history</h2>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-sm">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-3 py-2 text-left font-medium text-gray-500">Signer</th>
                  <th className="px-3 py-2 text-left font-medium text-gray-500">Title</th>
                  <th className="px-3 py-2 text-left font-medium text-gray-500">Signed at</th>
                  <th className="px-3 py-2 text-left font-medium text-gray-500">Consent</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 bg-white">
                {signatures.map((sig) => (
                  <tr key={sig.id}>
                    <td className="px-3 py-2 text-gray-900">{sig.signer_name || '—'}</td>
                    <td className="px-3 py-2 text-gray-500">{sig.signer_title || '—'}</td>
                    <td className="px-3 py-2 text-gray-500">{formatDateTime(sig.signed_at)}</td>
                    <td className="px-3 py-2 text-gray-500">{sig.legal_consent ? 'Yes' : 'No'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {!canSign && (contract.status === 'draft' || contract.status === 'pending_signature') && (
        <Card>
          <p className="text-sm text-gray-700">
            This contract is awaiting signature. Your portal account does not have
            the <span className="font-mono">sign.contracts</span> permission — please
            ask a portal administrator on your team to grant access or sign on your behalf.
          </p>
          <div className="mt-3">
            <Link to="/p/approvals">
              <Button variant="primary" size="sm">Go to Approvals</Button>
            </Link>
          </div>
        </Card>
      )}

      {blocked && (
        <Alert variant="warning" closable={false}>
          This contract is in &ldquo;{contract.status}&rdquo; status and cannot be signed.
        </Alert>
      )}
    </div>
  )
}
