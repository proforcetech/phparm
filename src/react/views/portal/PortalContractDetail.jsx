import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import Card from '../../components/ui/Card'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import Button from '../../components/ui/Button'
import { portalService } from '../../../services/portal/portal.service'

const formatMoney = (cents) => {
  if (cents == null || isNaN(Number(cents))) return '—'
  return `$${(Number(cents) / 100).toFixed(2)}`
}

const formatDate = (s) => (s ? new Date(s).toLocaleDateString() : '—')

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

export default function PortalContractDetail() {
  const { id } = useParams()
  const [contract, setContract] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)
    portalService.getContract(id)
      .then((c) => { if (!cancelled) setContract(c) })
      .catch((err) => { if (!cancelled) setError(err.response?.data?.message || 'Unable to load contract.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [id])

  if (loading) {
    return (
      <Card>
        <div className="py-10 flex justify-center"><Loading text="Loading contract…" /></div>
      </Card>
    )
  }
  if (error) return <Alert variant="error" closable={false}>{error}</Alert>
  if (!contract) return <Alert variant="warning" closable={false}>Contract not found.</Alert>

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

      {(contract.status === 'draft' || contract.status === 'pending_signature') && (
        <Card>
          <p className="text-sm text-gray-700">
            This contract is awaiting your signature. Use the Approvals screen to accept or reject.
          </p>
          <div className="mt-3">
            <Link to="/p/approvals">
              <Button variant="primary" size="sm">Go to Approvals</Button>
            </Link>
          </div>
        </Card>
      )}
    </div>
  )
}
