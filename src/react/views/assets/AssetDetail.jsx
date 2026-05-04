import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import assetsService from '../../../services/assets.service'
import { useToast } from '../../stores/toast.jsx'

const TABS = [
  { key: 'overview', label: 'Overview' },
  { key: 'qr', label: 'QR' },
  { key: 'documents', label: 'Documents' },
  { key: 'links', label: 'Links' },
]

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'pending', label: 'Pending' },
  { value: 'decommissioned', label: 'Decommissioned' },
]

const RELATION_OPTIONS = [
  { value: 'parent_of', label: 'Parent of' },
  { value: 'child_of', label: 'Child of' },
  { value: 'depends_on', label: 'Depends on' },
  { value: 'related_to', label: 'Related to' },
]

function statusVariant(status) {
  switch ((status || '').toLowerCase()) {
    case 'active':
      return 'success'
    case 'decommissioned':
      return 'danger'
    case 'inactive':
      return 'default'
    case 'pending':
      return 'warning'
    default:
      return 'secondary'
  }
}

function unwrapList(res) {
  if (Array.isArray(res)) return res
  if (Array.isArray(res?.data)) return res.data
  if (Array.isArray(res?.items)) return res.items
  return []
}

function unwrapObject(res) {
  if (res?.data && typeof res.data === 'object' && !Array.isArray(res.data)) return res.data
  return res
}

export default function AssetDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [tab, setTab] = useState('overview')
  const [asset, setAsset] = useState(null)
  const [loading, setLoading] = useState(true)

  // Edit form
  const [editing, setEditing] = useState(false)
  const [editBusy, setEditBusy] = useState(false)
  const [form, setForm] = useState({
    name: '',
    identifier: '',
    install_date: '',
    status: 'active',
    location_notes: '',
  })

  // Delete
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleteBusy, setDeleteBusy] = useState(false)

  // QR
  const [qrUrl, setQrUrl] = useState('')
  const [qrBusy, setQrBusy] = useState(false)
  const qrUrlRef = useRef('')

  // Documents
  const [documents, setDocuments] = useState([])
  const [docsLoading, setDocsLoading] = useState(false)
  const [uploadOpen, setUploadOpen] = useState(false)
  const [uploadBusy, setUploadBusy] = useState(false)
  const [uploadFile, setUploadFile] = useState(null)
  const [uploadTitle, setUploadTitle] = useState('')

  // Links
  const [links, setLinks] = useState([])
  const [linksLoading, setLinksLoading] = useState(false)
  const [linkOpen, setLinkOpen] = useState(false)
  const [linkBusy, setLinkBusy] = useState(false)
  const [linkForm, setLinkForm] = useState({ related_asset_id: '', relation_type: 'related_to' })

  const revokeQr = useCallback(() => {
    if (qrUrlRef.current) {
      URL.revokeObjectURL(qrUrlRef.current)
      qrUrlRef.current = ''
      setQrUrl('')
    }
  }, [])

  const loadAsset = useCallback(async () => {
    setLoading(true)
    try {
      const res = await assetsService.get(id)
      const obj = unwrapObject(res)
      setAsset(obj)
      setForm({
        name: obj?.name || '',
        identifier: obj?.identifier || obj?.serial_number || '',
        install_date: obj?.install_date || obj?.installed_at || '',
        status: obj?.status || 'active',
        location_notes: obj?.location_notes || '',
      })
    } catch {
      error('Failed to load asset')
      navigate('/cp/assets')
    } finally {
      setLoading(false)
    }
  }, [id, error, navigate])

  useEffect(() => {
    loadAsset()
  }, [loadAsset])

  // Reset transient state and revoke any active blob URL when the asset id changes
  useEffect(() => {
    return () => {
      revokeQr()
    }
  }, [id, revokeQr])

  // Final unmount cleanup
  useEffect(() => () => revokeQr(), [revokeQr])

  const loadDocuments = useCallback(async () => {
    setDocsLoading(true)
    try {
      const res = await assetsService.listDocuments(id)
      setDocuments(unwrapList(res))
    } catch {
      setDocuments([])
      error('Failed to load documents')
    } finally {
      setDocsLoading(false)
    }
  }, [id, error])

  const loadLinks = useCallback(async () => {
    setLinksLoading(true)
    try {
      const res = await assetsService.listLinks(id)
      setLinks(unwrapList(res))
    } catch {
      setLinks([])
      error('Failed to load links')
    } finally {
      setLinksLoading(false)
    }
  }, [id, error])

  useEffect(() => {
    if (tab === 'documents') loadDocuments()
    if (tab === 'links') loadLinks()
  }, [tab, loadDocuments, loadLinks])

  const submitEdit = async () => {
    setEditBusy(true)
    try {
      await assetsService.update(id, {
        name: form.name || undefined,
        identifier: form.identifier || undefined,
        install_date: form.install_date || undefined,
        status: form.status || undefined,
        location_notes: form.location_notes || undefined,
      })
      success('Asset updated')
      setEditing(false)
      loadAsset()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to update asset')
    } finally {
      setEditBusy(false)
    }
  }

  const submitDelete = async () => {
    setDeleteBusy(true)
    try {
      await assetsService.delete(id)
      success('Asset deleted')
      navigate('/cp/assets')
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to delete asset')
    } finally {
      setDeleteBusy(false)
    }
  }

  const generateQr = async () => {
    setQrBusy(true)
    try {
      const res = await assetsService.qrPng(id)
      revokeQr()
      const blob = res?.data instanceof Blob ? res.data : new Blob([res?.data], { type: 'image/png' })
      const url = URL.createObjectURL(blob)
      qrUrlRef.current = url
      setQrUrl(url)
    } catch {
      error('Failed to generate QR')
    } finally {
      setQrBusy(false)
    }
  }

  const submitUpload = async () => {
    if (!uploadFile) {
      error('Choose a file to upload')
      return
    }
    setUploadBusy(true)
    try {
      const fd = new FormData()
      fd.append('file', uploadFile)
      if (uploadTitle.trim()) fd.append('title', uploadTitle.trim())
      await assetsService.uploadDocument(id, fd)
      success('Document uploaded')
      setUploadOpen(false)
      setUploadFile(null)
      setUploadTitle('')
      loadDocuments()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to upload document')
    } finally {
      setUploadBusy(false)
    }
  }

  const removeDocument = async (docId) => {
    if (!window.confirm('Delete this document?')) return
    try {
      await assetsService.deleteDocument(docId)
      success('Document deleted')
      loadDocuments()
    } catch {
      error('Failed to delete document')
    }
  }

  const submitLink = async () => {
    if (!linkForm.related_asset_id || !linkForm.relation_type) {
      error('Both related asset and relation type are required')
      return
    }
    setLinkBusy(true)
    try {
      await assetsService.createLink(id, {
        related_asset_id: linkForm.related_asset_id,
        relation_type: linkForm.relation_type,
      })
      success('Link added')
      setLinkOpen(false)
      setLinkForm({ related_asset_id: '', relation_type: 'related_to' })
      loadLinks()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to add link')
    } finally {
      setLinkBusy(false)
    }
  }

  const removeLink = async (linkId) => {
    if (!window.confirm('Remove this link?')) return
    try {
      await assetsService.deleteLink(linkId)
      success('Link removed')
      loadLinks()
    } catch {
      error('Failed to remove link')
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-96">
        <Loading text="Loading asset..." />
      </div>
    )
  }

  if (!asset) {
    return (
      <div className="text-center py-12">
        <h3 className="text-lg font-medium text-gray-900">Asset not found</h3>
        <div className="mt-4">
          <Link to="/cp/assets">
            <Button>Back to Assets</Button>
          </Link>
        </div>
      </div>
    )
  }

  const displayName = asset.name || asset.identifier || `Asset #${asset.id}`
  const typeName = asset.asset_type_name || asset.type_name || (asset.asset_type_id ? `#${asset.asset_type_id}` : '—')
  const siteName = asset.site_name || (asset.site_id ? `Site #${asset.site_id}` : '—')
  const installDisplay = asset.install_date || asset.installed_at || '—'

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="flex items-center gap-4">
          <Link to="/cp/assets" className="text-gray-500 hover:text-gray-700">
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M15 19l-7-7 7-7"
              />
            </svg>
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{displayName}</h1>
            <p className="mt-1 text-sm text-gray-500">
              {typeName} <span className="text-gray-300">|</span> {siteName}
            </p>
          </div>
        </div>
        <div className="flex gap-2">
          <Badge variant={statusVariant(asset.status)}>{asset.status || 'unknown'}</Badge>
        </div>
      </div>

      <div className="border-b border-gray-200">
        <nav className="-mb-px flex gap-6" role="tablist">
          {TABS.map((t) => (
            <button
              key={t.key}
              role="tab"
              type="button"
              aria-selected={tab === t.key}
              onClick={() => setTab(t.key)}
              className={
                tab === t.key
                  ? 'border-b-2 border-primary-500 text-primary-600 px-1 py-3 text-sm font-medium'
                  : 'border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 px-1 py-3 text-sm font-medium'
              }
            >
              {t.label}
            </button>
          ))}
        </nav>
      </div>

      {tab === 'overview' && (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <Card className="lg:col-span-2">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900">Details</h3>
              {!editing ? (
                <div className="flex gap-2">
                  <Button size="sm" variant="secondary" onClick={() => setEditing(true)}>
                    Edit
                  </Button>
                  <Button size="sm" variant="danger" onClick={() => setDeleteOpen(true)}>
                    Delete
                  </Button>
                </div>
              ) : null}
            </div>

            {editing ? (
              <div className="space-y-3">
                <Input
                  label="Name"
                  value={form.name}
                  onUpdateModelValue={(v) => setForm((f) => ({ ...f, name: v }))}
                />
                <Input
                  label="Identifier / Serial"
                  value={form.identifier}
                  onUpdateModelValue={(v) => setForm((f) => ({ ...f, identifier: v }))}
                />
                <Input
                  label="Install date"
                  type="date"
                  value={form.install_date}
                  onUpdateModelValue={(v) => setForm((f) => ({ ...f, install_date: v }))}
                />
                <Select
                  label="Status"
                  value={form.status}
                  placeholder=""
                  options={STATUS_OPTIONS}
                  onUpdateModelValue={(v) => setForm((f) => ({ ...f, status: v }))}
                />
                <Textarea
                  label="Location notes"
                  value={form.location_notes}
                  onUpdateModelValue={(v) => setForm((f) => ({ ...f, location_notes: v }))}
                  rows={3}
                />
                <div className="flex justify-end gap-2 pt-2">
                  <Button variant="ghost" onClick={() => setEditing(false)}>
                    Cancel
                  </Button>
                  <Button loading={editBusy} onClick={submitEdit}>
                    Save
                  </Button>
                </div>
              </div>
            ) : (
              <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <dt className="text-sm font-medium text-gray-500">Name</dt>
                  <dd className="mt-1 text-sm text-gray-900">{asset.name || '—'}</dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Identifier</dt>
                  <dd className="mt-1 text-sm text-gray-900 font-mono">
                    {asset.identifier || asset.serial_number || '—'}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Type</dt>
                  <dd className="mt-1 text-sm text-gray-900">{typeName}</dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Site</dt>
                  <dd className="mt-1 text-sm text-gray-900">
                    {asset.site_id ? (
                      <Link
                        to={`/cp/crm/sites/${asset.site_id}`}
                        className="text-primary-600 hover:text-primary-500"
                      >
                        {siteName}
                      </Link>
                    ) : (
                      '—'
                    )}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Install date</dt>
                  <dd className="mt-1 text-sm text-gray-900">{installDisplay}</dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Status</dt>
                  <dd className="mt-1">
                    <Badge variant={statusVariant(asset.status)}>{asset.status || 'unknown'}</Badge>
                  </dd>
                </div>
                {asset.location_notes ? (
                  <div className="sm:col-span-2">
                    <dt className="text-sm font-medium text-gray-500">Location notes</dt>
                    <dd className="mt-1 text-sm text-gray-900 whitespace-pre-wrap">
                      {asset.location_notes}
                    </dd>
                  </div>
                ) : null}
              </dl>
            )}
          </Card>

          <Card>
            <h3 className="text-lg font-medium text-gray-900 mb-4">Lifecycle</h3>
            <div className="space-y-3 text-sm">
              <div>
                <dt className="text-gray-500">Last PM</dt>
                <dd className="text-gray-900">{asset.last_pm_at || '—'}</dd>
              </div>
              <div>
                <dt className="text-gray-500">Next PM due</dt>
                <dd className="text-gray-900">{asset.next_pm_due_at || '—'}</dd>
              </div>
              <div>
                <dt className="text-gray-500">Created</dt>
                <dd className="text-gray-900">
                  {asset.created_at ? new Date(asset.created_at).toLocaleString() : '—'}
                </dd>
              </div>
            </div>
          </Card>
        </div>
      )}

      {tab === 'qr' && (
        <Card>
          <div className="space-y-4">
            <div className="flex flex-wrap gap-2">
              <Button onClick={generateQr} loading={qrBusy}>
                {qrUrl ? 'Regenerate QR' : 'Generate / View QR'}
              </Button>
              {qrUrl ? (
                <a href={qrUrl} download={`asset-${id}-qr.png`}>
                  <Button variant="secondary">Download</Button>
                </a>
              ) : null}
            </div>

            {qrUrl ? (
              <div className="border rounded p-4 inline-block bg-white">
                <img src={qrUrl} alt="Asset QR code" className="h-64 w-64 object-contain" />
              </div>
            ) : (
              <p className="text-sm text-gray-500">
                Click Generate to create a printable QR for this asset.
              </p>
            )}
          </div>
        </Card>
      )}

      {tab === 'documents' && (
        <Card padding={false}>
          <div className="px-6 py-4 flex justify-between items-center border-b">
            <h3 className="text-lg font-medium text-gray-900">Documents</h3>
            <Button onClick={() => setUploadOpen(true)}>Upload</Button>
          </div>
          {docsLoading ? (
            <div className="py-12 flex justify-center">
              <Loading />
            </div>
          ) : documents.length === 0 ? (
            <div className="text-center py-12 px-6">
              <p className="text-sm text-gray-500">No documents attached.</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                      Filename
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                      Uploaded
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {documents.map((d) => (
                    <tr key={d.id}>
                      <td className="px-4 py-3 text-sm text-gray-900">
                        {d.title || d.filename || d.original_name || `Document #${d.id}`}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">
                        {d.uploaded_at || d.created_at || '—'}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">
                        {d.uploaded_by_name || d.uploaded_by || '—'}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-red-600 hover:text-red-700"
                          onClick={() => removeDocument(d.id)}
                        >
                          Delete
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {tab === 'links' && (
        <Card padding={false}>
          <div className="px-6 py-4 flex justify-between items-center border-b">
            <h3 className="text-lg font-medium text-gray-900">Linked Assets</h3>
            <Button onClick={() => setLinkOpen(true)}>Add Link</Button>
          </div>
          {linksLoading ? (
            <div className="py-12 flex justify-center">
              <Loading />
            </div>
          ) : links.length === 0 ? (
            <div className="text-center py-12 px-6">
              <p className="text-sm text-gray-500">No related assets linked.</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                      Related Asset
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                      Relation
                    </th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {links.map((l) => {
                    const relatedId = l.related_asset_id || l.target_id || l.asset_id
                    const relatedName =
                      l.related_asset_name || l.target_name || (relatedId ? `Asset #${relatedId}` : '—')
                    return (
                      <tr key={l.id}>
                        <td className="px-4 py-3 text-sm">
                          {relatedId ? (
                            <Link
                              to={`/cp/assets/${relatedId}`}
                              className="text-primary-600 hover:text-primary-500 font-medium"
                            >
                              {relatedName}
                            </Link>
                          ) : (
                            relatedName
                          )}
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-700">
                          {l.relation_type || '—'}
                        </td>
                        <td className="px-4 py-3 text-right">
                          <Button
                            size="sm"
                            variant="ghost"
                            className="text-red-600 hover:text-red-700"
                            onClick={() => removeLink(l.id)}
                          >
                            Remove
                          </Button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      <Modal open={deleteOpen} title="Delete Asset" onClose={() => setDeleteOpen(false)}>
        <p className="text-sm text-gray-600 mb-4">
          Delete <strong>{displayName}</strong>? This action cannot be undone.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteOpen(false)}>
            Cancel
          </Button>
          <Button variant="danger" loading={deleteBusy} onClick={submitDelete}>
            Delete
          </Button>
        </div>
      </Modal>

      <Modal open={uploadOpen} title="Upload Document" onClose={() => setUploadOpen(false)}>
        <div className="space-y-3">
          <Input
            label="Title (optional)"
            value={uploadTitle}
            onUpdateModelValue={setUploadTitle}
            placeholder="e.g. Install manual"
          />
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">File</label>
            <input
              type="file"
              onChange={(e) => setUploadFile(e.target.files?.[0] || null)}
              className="block w-full text-sm text-gray-700 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100"
            />
            {uploadFile ? (
              <p className="mt-1 text-xs text-gray-500">
                {uploadFile.name} ({Math.round(uploadFile.size / 1024)} KB)
              </p>
            ) : null}
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" onClick={() => setUploadOpen(false)}>
              Cancel
            </Button>
            <Button loading={uploadBusy} onClick={submitUpload}>
              Upload
            </Button>
          </div>
        </div>
      </Modal>

      <Modal open={linkOpen} title="Add Linked Asset" onClose={() => setLinkOpen(false)}>
        <div className="space-y-3">
          <Input
            label="Related asset ID"
            required
            value={linkForm.related_asset_id}
            onUpdateModelValue={(v) => setLinkForm((f) => ({ ...f, related_asset_id: v }))}
            placeholder="Numeric asset id"
            helperText="TODO: needs cross-asset picker (no search-by-name endpoint exposed for inline use)"
          />
          <Select
            label="Relation type"
            required
            value={linkForm.relation_type}
            placeholder=""
            options={RELATION_OPTIONS}
            onUpdateModelValue={(v) => setLinkForm((f) => ({ ...f, relation_type: v }))}
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" onClick={() => setLinkOpen(false)}>
              Cancel
            </Button>
            <Button loading={linkBusy} onClick={submitLink}>
              Add Link
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
