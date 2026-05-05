import { useCallback, useEffect, useMemo, useState } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import vendorPortalService from '../../../services/vendor-portal.service'

/**
 * Phase 18 / C1 — public vendor self-service portal.
 *
 * Mounted OUTSIDE the staff app shell at /vendor-portal/:token (or
 * /vendor-portal?token=...). The token is the entire credential — staff
 * issued it and handed it to the vendor by side channel. We attach it to
 * vendorPortalService and never call the shared `api` axios instance.
 *
 * UX is intentionally minimal — meant to work for a vendor's shipping desk:
 *   - top bar shows which vendor is logged in
 *   - PO list with status badges + quick acknowledge action
 *   - tap-to-open detail panel: line table with per-line "mark shipped"
 *     (tracking + carrier), document upload, document list
 */

const PO_STATUS_VARIANT = {
  draft: 'secondary',
  sent: 'info',
  partial: 'warning',
  received: 'success',
  closed: 'success',
  cancelled: 'danger',
}

const LINE_STATUS_VARIANT = {
  pending: 'warning',
  partial: 'info',
  received: 'success',
  cancelled: 'danger',
}

const KIND_OPTIONS = [
  { value: 'tracking', label: 'Tracking' },
  { value: 'packing_slip', label: 'Packing slip' },
  { value: 'invoice', label: 'Invoice' },
  { value: 'other', label: 'Other' },
]

function formatCurrency(cents, currency = 'USD') {
  if (cents == null) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format((cents || 0) / 100)
  } catch {
    return `${(cents / 100).toFixed(2)} ${currency}`
  }
}

function formatDateTime(s) {
  if (!s) return '—'
  const d = new Date(String(s).replace(' ', 'T'))
  return Number.isNaN(d.valueOf()) ? s : d.toLocaleString()
}

function formatDate(s) {
  if (!s) return '—'
  const d = new Date(String(s).replace(' ', 'T'))
  return Number.isNaN(d.valueOf()) ? s : d.toLocaleDateString()
}

export default function VendorPortal() {
  const { token: routeToken } = useParams()
  const [search] = useSearchParams()
  const queryToken = search.get('token')
  const token = (routeToken || queryToken || '').trim()

  const [me, setMe] = useState(null)
  const [authError, setAuthError] = useState('')
  const [loadingMe, setLoadingMe] = useState(true)

  const [pos, setPos] = useState([])
  const [loadingPos, setLoadingPos] = useState(false)
  const [statusFilter, setStatusFilter] = useState('')
  const [error, setError] = useState('')

  const [openId, setOpenId] = useState(null)
  const [detail, setDetail] = useState(null)
  const [openLoading, setOpenLoading] = useState(false)
  const [actionBusy, setActionBusy] = useState('')

  // Per-line ship-form state (lineId -> { tracking, carrier })
  const [shipDraft, setShipDraft] = useState({})

  // Document upload form
  const [uploadFile, setUploadFile] = useState(null)
  const [uploadKind, setUploadKind] = useState('tracking')
  const [uploadTracking, setUploadTracking] = useState('')
  const [uploadCarrier, setUploadCarrier] = useState('')
  const [uploadLineId, setUploadLineId] = useState('')
  const [uploadNotes, setUploadNotes] = useState('')

  useEffect(() => {
    if (!token) {
      setAuthError('No token provided. Use the link your buyer sent you.')
      setLoadingMe(false)
      return
    }
    vendorPortalService.setToken(token)
    setLoadingMe(true)
    vendorPortalService
      .me()
      .then((res) => setMe(res?.data ?? null))
      .catch((e) => setAuthError(
        e?.response?.data?.message
        || (e?.response?.status === 401 ? 'Token is invalid or expired.' : '')
        || e?.message
        || 'Could not load portal.'
      ))
      .finally(() => setLoadingMe(false))
  }, [token])

  const loadPos = useCallback(() => {
    if (!me) return
    setLoadingPos(true)
    vendorPortalService
      .listPos(statusFilter || undefined)
      .then((res) => setPos(res?.data ?? []))
      .catch((e) => setError(
        e?.response?.data?.message || e?.message || 'Could not load purchase orders.'
      ))
      .finally(() => setLoadingPos(false))
  }, [me, statusFilter])

  useEffect(() => {
    loadPos()
  }, [loadPos])

  const openPoDetail = useCallback((id) => {
    setOpenId(id)
    setOpenLoading(true)
    vendorPortalService
      .getPo(id)
      .then((res) => {
        setDetail(res?.data ?? null)
        setShipDraft({})
      })
      .catch((e) => setError(
        e?.response?.data?.message || e?.message || 'Could not load purchase order.'
      ))
      .finally(() => setOpenLoading(false))
  }, [])

  const closeDetail = () => {
    setOpenId(null)
    setDetail(null)
    setShipDraft({})
    setUploadFile(null)
    setUploadKind('tracking')
    setUploadTracking('')
    setUploadCarrier('')
    setUploadLineId('')
    setUploadNotes('')
  }

  const refreshOpen = useCallback(async () => {
    if (!openId) return
    try {
      const res = await vendorPortalService.getPo(openId)
      setDetail(res?.data ?? null)
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Refresh failed.')
    }
  }, [openId])

  const acknowledge = async () => {
    if (!detail?.po) return
    setActionBusy('ack')
    try {
      await vendorPortalService.acknowledge(detail.po.id)
      await refreshOpen()
      loadPos()
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Acknowledge failed.')
    } finally {
      setActionBusy('')
    }
  }

  const setLineDraft = (lineId, patch) => {
    setShipDraft((prev) => ({
      ...prev,
      [lineId]: { ...(prev[lineId] || {}), ...patch },
    }))
  }

  const markLineShipped = async (line) => {
    setActionBusy('ship-' + line.id)
    try {
      const draft = shipDraft[line.id] || {}
      await vendorPortalService.markLineShipped(line.id, {
        tracking_number: draft.tracking || line.vendor_tracking_number || '',
        carrier: draft.carrier || line.vendor_carrier || '',
      })
      await refreshOpen()
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Mark shipped failed.')
    } finally {
      setActionBusy('')
    }
  }

  const submitUpload = async (e) => {
    e?.preventDefault?.()
    if (!detail?.po || !uploadFile) return
    setActionBusy('upload')
    try {
      await vendorPortalService.uploadDocument(detail.po.id, uploadFile, {
        kind: uploadKind,
        tracking_number: uploadTracking || undefined,
        carrier: uploadCarrier || undefined,
        purchase_order_line_id: uploadLineId || undefined,
        notes: uploadNotes || undefined,
      })
      setUploadFile(null)
      setUploadTracking('')
      setUploadCarrier('')
      setUploadLineId('')
      setUploadNotes('')
      await refreshOpen()
    } catch (e2) {
      setError(e2?.response?.data?.message || e2?.message || 'Upload failed.')
    } finally {
      setActionBusy('')
    }
  }

  const removeDocument = async (docId) => {
    if (!confirm('Delete this document?')) return
    setActionBusy('doc-' + docId)
    try {
      await vendorPortalService.deleteDocument(docId)
      await refreshOpen()
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Delete failed.')
    } finally {
      setActionBusy('')
    }
  }

  const lineUploadOptions = useMemo(() => {
    if (!detail?.lines) return []
    return [
      { value: '', label: 'Whole shipment / order' },
      ...detail.lines.map((l) => ({
        value: String(l.id),
        label: `Line ${l.line_number}: ${l.description}`,
      })),
    ]
  }, [detail])

  if (loadingMe) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 p-4">
        <Loading />
      </div>
    )
  }

  if (authError || !me) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 p-4">
        <Card className="max-w-md w-full">
          <div className="p-6 space-y-3">
            <h1 className="text-xl font-semibold">Vendor portal</h1>
            <Alert variant="danger">{authError || 'Could not load portal.'}</Alert>
            <p className="text-sm text-gray-500">
              Ask your buyer to send you a fresh portal link.
            </p>
          </div>
        </Card>
      </div>
    )
  }

  const vendor = me.vendor

  return (
    <div className="min-h-screen bg-gray-50">
      <header className="bg-white border-b">
        <div className="max-w-4xl mx-auto p-4 flex items-center justify-between">
          <div>
            <div className="text-xs text-gray-500 uppercase tracking-wide">Vendor portal</div>
            <h1 className="text-lg font-semibold">{vendor.name}</h1>
            {vendor.primary_contact_name && (
              <div className="text-sm text-gray-500">{vendor.primary_contact_name}</div>
            )}
          </div>
          <Badge variant="success">Active</Badge>
        </div>
      </header>

      <main className="max-w-4xl mx-auto p-4 space-y-4">
        {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

        <Card padding={false}>
          <div className="p-4 flex items-center justify-between gap-2 flex-wrap">
            <div>
              <h2 className="text-sm font-semibold">Your purchase orders</h2>
              <p className="text-xs text-gray-500">
                Tap any row to view lines, mark shipments, and upload tracking.
              </p>
            </div>
            <div className="flex items-center gap-2">
              <select
                className="border rounded px-2 py-1 text-sm"
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
              >
                <option value="">All</option>
                <option value="sent">Sent</option>
                <option value="partial">Partially received</option>
                <option value="received">Received</option>
                <option value="closed">Closed</option>
                <option value="cancelled">Cancelled</option>
              </select>
              <Button variant="secondary" onClick={loadPos}>Refresh</Button>
            </div>
          </div>
          {loadingPos ? (
            <div className="p-6 text-center"><Loading /></div>
          ) : pos.length === 0 ? (
            <div className="p-6 text-center text-gray-500">No purchase orders to show.</div>
          ) : (
            <ul className="divide-y">
              {pos.map((po) => (
                <li key={po.id}>
                  <button
                    type="button"
                    onClick={() => openPoDetail(po.id)}
                    className="w-full text-left p-4 hover:bg-gray-50 flex items-center justify-between gap-3"
                  >
                    <div className="min-w-0">
                      <div className="text-sm font-semibold font-mono">{po.po_number}</div>
                      <div className="text-xs text-gray-500">
                        Ordered {formatDate(po.ordered_at)}
                        {po.expected_at ? ` · expected ${formatDate(po.expected_at)}` : ''}
                      </div>
                      <div className="text-xs text-gray-400 mt-1">
                        {formatCurrency(po.total_cents, po.currency)}
                        {po.vendor_acknowledged_at ? ' · acknowledged' : ''}
                      </div>
                    </div>
                    <Badge variant={PO_STATUS_VARIANT[po.status] || 'secondary'}>
                      {po.status.replace('_', ' ')}
                    </Badge>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </main>

      <Modal
        open={openId !== null}
        onClose={closeDetail}
        title={detail?.po ? `PO ${detail.po.po_number}` : 'Purchase order'}
        size="xl"
      >
        {openLoading || !detail?.po ? (
          <div className="p-6 text-center"><Loading /></div>
        ) : (
          <div className="space-y-4">
            <div className="flex items-center gap-2 flex-wrap">
              <Badge variant={PO_STATUS_VARIANT[detail.po.status] || 'secondary'}>
                {detail.po.status.replace('_', ' ')}
              </Badge>
              <span className="text-xs text-gray-500">
                Ordered {formatDate(detail.po.ordered_at)}
              </span>
              {detail.po.expected_at && (
                <span className="text-xs text-gray-500">
                  · Expected {formatDate(detail.po.expected_at)}
                </span>
              )}
              <span className="text-xs text-gray-500">
                · Total {formatCurrency(detail.po.total_cents, detail.po.currency)}
              </span>
              {detail.po.is_consigned && (
                <Badge variant="info">Consigned</Badge>
              )}
            </div>

            {detail.po.notes && (
              <div className="border rounded p-2 bg-gray-50">
                <div className="text-xs uppercase text-gray-500 tracking-wide mb-1">Notes from buyer</div>
                <div className="text-sm whitespace-pre-wrap">{detail.po.notes}</div>
              </div>
            )}

            {!detail.po.vendor_acknowledged_at
              && (detail.po.status === 'sent' || detail.po.status === 'partial') && (
              <Alert variant="info">
                <div className="flex items-center justify-between gap-2 flex-wrap">
                  <span>Acknowledge receipt of this PO so the buyer knows you've seen it.</span>
                  <Button onClick={acknowledge} disabled={actionBusy === 'ack'}>
                    {actionBusy === 'ack' ? 'Acknowledging…' : 'Acknowledge PO'}
                  </Button>
                </div>
              </Alert>
            )}
            {detail.po.vendor_acknowledged_at && (
              <div className="text-xs text-green-700">
                ✓ Acknowledged {formatDateTime(detail.po.vendor_acknowledged_at)}
              </div>
            )}

            <div>
              <div className="text-xs uppercase text-gray-500 tracking-wide mb-2">
                Line items ({detail.lines.length})
              </div>
              <div className="overflow-x-auto border rounded">
                <table className="min-w-full text-sm">
                  <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                      <th className="text-left p-2">#</th>
                      <th className="text-left p-2">Description</th>
                      <th className="text-right p-2">Ordered</th>
                      <th className="text-right p-2">Received</th>
                      <th className="text-right p-2">Unit</th>
                      <th className="text-left p-2">Status</th>
                      <th className="text-left p-2">Shipping</th>
                    </tr>
                  </thead>
                  <tbody>
                    {detail.lines.map((l) => {
                      const draft = shipDraft[l.id] || {}
                      const canShip = (detail.po.status === 'sent' || detail.po.status === 'partial')
                        && l.status !== 'cancelled'
                        && l.status !== 'received'
                      return (
                        <tr key={l.id} className="border-t align-top">
                          <td className="p-2 text-gray-500">{l.line_number}</td>
                          <td className="p-2">
                            <div className="font-medium">{l.description}</div>
                            {l.sku && <div className="text-xs text-gray-500">SKU: {l.sku}</div>}
                            {l.notes && <div className="text-xs text-gray-500 mt-1">{l.notes}</div>}
                          </td>
                          <td className="p-2 text-right">{l.quantity_ordered}</td>
                          <td className="p-2 text-right">{l.quantity_received}</td>
                          <td className="p-2 text-right">
                            {formatCurrency(l.unit_cost_cents, detail.po.currency)}
                          </td>
                          <td className="p-2">
                            <Badge variant={LINE_STATUS_VARIANT[l.status] || 'secondary'}>
                              {l.status}
                            </Badge>
                          </td>
                          <td className="p-2 min-w-[280px]">
                            {l.vendor_shipped_at ? (
                              <div className="text-xs text-gray-600">
                                <div>✓ Shipped {formatDateTime(l.vendor_shipped_at)}</div>
                                {l.vendor_carrier && <div>{l.vendor_carrier}</div>}
                                {l.vendor_tracking_number && (
                                  <div className="font-mono break-all">{l.vendor_tracking_number}</div>
                                )}
                              </div>
                            ) : canShip ? (
                              <div className="space-y-1">
                                <Input
                                  placeholder="Tracking #"
                                  value={draft.tracking ?? ''}
                                  onChange={(e) => setLineDraft(l.id, { tracking: e.target.value })}
                                />
                                <Input
                                  placeholder="Carrier (UPS, FedEx, …)"
                                  value={draft.carrier ?? ''}
                                  onChange={(e) => setLineDraft(l.id, { carrier: e.target.value })}
                                />
                                <Button
                                  size="sm"
                                  disabled={actionBusy === 'ship-' + l.id}
                                  onClick={() => markLineShipped(l)}
                                >
                                  {actionBusy === 'ship-' + l.id ? 'Saving…' : 'Mark shipped'}
                                </Button>
                              </div>
                            ) : (
                              <span className="text-xs text-gray-400">—</span>
                            )}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            <div>
              <div className="text-xs uppercase text-gray-500 tracking-wide mb-2">
                Documents ({detail.documents.length})
              </div>
              {detail.documents.length === 0 ? (
                <p className="text-xs text-gray-400">Nothing uploaded yet.</p>
              ) : (
                <ul className="space-y-2">
                  {detail.documents.map((d) => (
                    <li key={d.id} className="flex items-start justify-between gap-3 text-sm border rounded p-2">
                      <div className="min-w-0">
                        <div className="font-medium capitalize">{d.kind.replace('_', ' ')}</div>
                        {d.original_name && (
                          <div className="text-xs text-gray-500 truncate">{d.original_name}</div>
                        )}
                        {(d.tracking_number || d.carrier) && (
                          <div className="text-xs text-gray-600 mt-1">
                            {d.carrier || ''} {d.tracking_number ? `· ${d.tracking_number}` : ''}
                          </div>
                        )}
                        {d.notes && (
                          <div className="text-xs text-gray-600 mt-1 whitespace-pre-wrap">{d.notes}</div>
                        )}
                        <div className="text-[11px] text-gray-400 mt-1">
                          {formatDateTime(d.uploaded_at)}
                        </div>
                      </div>
                      {d.uploaded_via_token_id && (
                        <Button
                          variant="ghost"
                          disabled={actionBusy === 'doc-' + d.id}
                          onClick={() => removeDocument(d.id)}
                        >
                          Remove
                        </Button>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <form onSubmit={submitUpload} className="border-t pt-4 space-y-2">
              <div className="text-xs uppercase text-gray-500 tracking-wide">Upload document</div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <Select
                  label="Kind"
                  value={uploadKind}
                  onChange={(e) => setUploadKind(e?.target?.value ?? e)}
                  options={KIND_OPTIONS}
                />
                <Select
                  label="Applies to"
                  value={uploadLineId}
                  onChange={(e) => setUploadLineId(e?.target?.value ?? e)}
                  options={lineUploadOptions}
                />
                <Input
                  label="Tracking # (optional)"
                  value={uploadTracking}
                  onChange={(e) => setUploadTracking(e.target.value)}
                />
                <Input
                  label="Carrier (optional)"
                  value={uploadCarrier}
                  onChange={(e) => setUploadCarrier(e.target.value)}
                />
              </div>
              <Textarea
                label="Notes (optional)"
                rows={2}
                value={uploadNotes}
                onChange={(e) => setUploadNotes(e.target.value)}
              />
              <div className="flex flex-wrap gap-2 items-center">
                <input
                  type="file"
                  accept="image/*,application/pdf"
                  onChange={(e) => setUploadFile(e.target.files?.[0] || null)}
                  className="text-sm"
                />
                <Button type="submit" disabled={!uploadFile || actionBusy === 'upload'}>
                  {actionBusy === 'upload' ? 'Uploading…' : 'Upload'}
                </Button>
              </div>
              <p className="text-[11px] text-gray-400">
                JPG / PNG / PDF up to 10MB.
              </p>
            </form>
          </div>
        )}
      </Modal>
    </div>
  )
}
