import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import Textarea from '../../components/ui/Textarea'
import { warrantyService } from '../../../services/warranty.service'

const STATUS_LABELS = {
  defective: 'Defective',
  rma_requested: 'RMA Requested',
  shipped: 'Shipped to Vendor',
  credit_received: 'Credit Received',
  open: 'Open',
  in_review: 'In Review',
  resolved: 'Resolved',
  rejected: 'Rejected',
}

const statusClass = (status) => {
  switch (status) {
    case 'credit_received':
      return 'bg-green-100 text-green-800'
    case 'shipped':
      return 'bg-blue-100 text-blue-800'
    case 'rma_requested':
      return 'bg-yellow-100 text-yellow-800'
    case 'defective':
      return 'bg-red-100 text-red-800'
    default:
      return 'bg-gray-100 text-gray-700'
  }
}

const formatDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

export default function WarrantyClaimDetail() {
  const navigate = useNavigate()
  const { id } = useParams()
  const claimId = Number(id)

  const [claim, setClaim] = useState({
    subject: '',
    description: '',
    status: '',
    invoice_id: null,
    vehicle_id: null,
    messages: [],
  })
  const [loading, setLoading] = useState(false)
  const [replying, setReplying] = useState(false)
  const [reply, setReply] = useState('')

  const loadClaim = useCallback(async () => {
    if (!claimId) return
    setLoading(true)
    try {
      const response = await warrantyService.getCustomerClaim(claimId)
      setClaim(response)
    } finally {
      setLoading(false)
    }
  }, [claimId])

  useEffect(() => {
    loadClaim()
  }, [loadClaim])

  const submitReply = async (event) => {
    event.preventDefault()
    if (!reply) return
    setReplying(true)
    try {
      const response = await warrantyService.replyToClaim(claimId, reply)
      setClaim(response)
      setReply('')
    } finally {
      setReplying(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Claim #{id}</h1>
          <p className="mt-1 text-sm text-gray-500">Review the claim details and conversation.</p>
        </div>
        <Button variant="ghost" onClick={() => navigate('/portal/warranty-claims')}>Back to claims</Button>
      </div>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading claim..." />
          </div>
        ) : (
          <div>
            <div className="flex items-center justify-between mb-4">
              <div>
                <h2 className="text-lg font-semibold text-gray-900">{claim.subject}</h2>
                <p className="text-sm text-gray-500">
                  Invoice: {claim.invoice_id || '—'} · Vehicle: {claim.vehicle_id || '—'}
                </p>
              </div>
              <span className={`px-3 py-1 text-xs rounded-full ${statusClass(claim.status)}`}>
                {STATUS_LABELS[claim.status] || claim.status}
              </span>
            </div>

            <div className="bg-gray-50 border rounded-md p-4 mb-6">
              <p className="text-sm text-gray-700 whitespace-pre-line">{claim.description}</p>
            </div>

            <h3 className="text-sm font-semibold text-gray-900 mb-3">Messages</h3>
            <div className="space-y-3">
              {claim.messages.map((message) => (
                <div
                  key={message.id}
                  className={`p-3 rounded-lg border ${
                    message.actor_type === 'customer' ? 'bg-blue-50 border-blue-100' : 'bg-gray-50 border-gray-200'
                  }`}
                >
                  <div className="flex items-center justify-between text-xs text-gray-500 mb-1">
                    <span className="font-medium text-gray-700">
                      {message.actor_type === 'customer' ? 'You' : 'Shop'}
                    </span>
                    <span>{formatDate(message.created_at)}</span>
                  </div>
                  <p className="text-sm text-gray-800 whitespace-pre-line">{message.message}</p>
                </div>
              ))}
            </div>

            <div className="mt-6">
              <h3 className="text-sm font-semibold text-gray-900 mb-2">Add a reply</h3>
              <form className="space-y-3" onSubmit={submitReply}>
                <Textarea
                  modelValue={reply}
                  label="Message"
                  placeholder="Share more details or updates"
                  required
                  onUpdateModelValue={setReply}
                />
                <div className="flex justify-end">
                  <Button variant="primary" type="submit" disabled={replying}>
                    {replying ? 'Sending...' : 'Send Reply'}
                  </Button>
                </div>
              </form>
            </div>
          </div>
        )}
      </Card>
    </div>
  )
}
