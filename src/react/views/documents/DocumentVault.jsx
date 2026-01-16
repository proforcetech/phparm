import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import documentVaultService from '../../../services/document-vault.service'

const DOCUMENT_TYPES = [
  { value: 'contract', label: 'Contract' },
  { value: 'certification', label: 'Certification' },
  { value: 'license', label: 'License' },
  { value: 'policy', label: 'Policy' },
  { value: 'other', label: 'Other' },
]

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'expiring', label: 'Expiring soon' },
  { value: 'expired', label: 'Expired' },
]

const formatDate = (value) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return value
  }
  return date.toLocaleDateString()
}

const statusMetadata = (doc, expiringDays) => {
  const status = doc.expiry_status
  if (status === 'expired') {
    return { label: 'Expired', variant: 'danger' }
  }
  if (status === 'expiring') {
    return { label: `Expiring in ${expiringDays} days`, variant: 'warning' }
  }
  if (!doc.expiration_date) {
    return { label: 'No expiry', variant: 'secondary' }
  }
  return { label: 'Active', variant: 'success' }
}

const createEmptyForm = () => ({
  title: '',
  document_type: '',
  category: '',
  issuing_authority: '',
  document_number: '',
  issued_date: '',
  expiration_date: '',
  notes: '',
})

export default function DocumentVault() {
  const [documents, setDocuments] = useState([])
  const [alerts, setAlerts] = useState(null)
  const [filters, setFilters] = useState({ search: '', type: '', status: '' })
  const [expiringDays, setExpiringDays] = useState(30)
  const [form, setForm] = useState(createEmptyForm)
  const [file, setFile] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const [success, setSuccess] = useState(null)

  const loadDocuments = useCallback(async (overrideFilters = filters, windowOverride = expiringDays) => {
    try {
      setLoading(true)
      setError(null)
      const data = await documentVaultService.list({
        search: overrideFilters.search,
        type: overrideFilters.type,
        status: overrideFilters.status,
        expiring_days: windowOverride,
      })
      const normalized = Array.isArray(data?.data) ? data.data : []
      setDocuments(normalized)

      if (data?.meta?.expiring_days) {
        setExpiringDays(data.meta.expiring_days)
      }
    } catch (err) {
      console.error('Failed to load document vault', err)
      setError(err.response?.data?.error || 'Unable to load documents. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [expiringDays, filters])

  const loadAlerts = useCallback(async (windowOverride = expiringDays) => {
    try {
      const data = await documentVaultService.alerts({ expiring_days: windowOverride })
      setAlerts(data?.data || null)
      if (data?.meta?.expiring_days) {
        setExpiringDays(data.meta.expiring_days)
      }
    } catch (err) {
      console.error('Failed to load document alerts', err)
    }
  }, [expiringDays])

  useEffect(() => {
    loadDocuments(filters, expiringDays)
    loadAlerts(expiringDays)
  }, [])

  const handleSubmit = async (event) => {
    event.preventDefault()
    setSuccess(null)

    if (!form.title || !form.document_type) {
      setError('Title and document type are required.')
      return
    }

    if (!file) {
      setError('Please attach a document file before uploading.')
      return
    }

    try {
      setSaving(true)
      setError(null)
      await documentVaultService.create(form, file)
      setSuccess('Document uploaded successfully.')
      setForm(createEmptyForm())
      setFile(null)
      await loadDocuments(filters, expiringDays)
      await loadAlerts(expiringDays)
    } catch (err) {
      console.error('Document upload failed', err)
      setError(err.response?.data?.error || 'Unable to upload document. Please try again.')
    } finally {
      setSaving(false)
    }
  }

  const handleFilterChange = (field, value) => {
    setFilters((prev) => ({ ...prev, [field]: value }))
  }

  const applyFilters = () => {
    loadDocuments(filters, expiringDays)
  }

  const handleDelete = async (doc) => {
    const confirmed = window.confirm(`Delete ${doc.title}? This cannot be undone.`)
    if (!confirmed) return

    try {
      await documentVaultService.remove(doc.id)
      await loadDocuments(filters, expiringDays)
      await loadAlerts(expiringDays)
    } catch (err) {
      console.error('Failed to delete document', err)
      setError(err.response?.data?.error || 'Unable to delete the document.')
    }
  }

  const documentRows = useMemo(() => {
    return documents.map((doc) => ({
      ...doc,
      status: statusMetadata(doc, expiringDays),
    }))
  }, [documents, expiringDays])

  const expiredCount = Number(alerts?.expired_count || 0)
  const expiringCount = Number(alerts?.expiring_count || 0)
  const trackedCount = Number(alerts?.tracked_count || 0)
  const totalCount = Number(alerts?.total_count || documents.length)

  return (
    <div>
      <div className="mb-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Document Vault</h1>
          <p className="mt-1 text-sm text-gray-500">Store contracts and certifications with expiration tracking.</p>
        </div>
      </div>

      {(expiredCount > 0 || expiringCount > 0) ? (
        <Alert variant="warning" className="mb-6">
          {expiredCount > 0 ? `${expiredCount} document(s) are expired. ` : ''}
          {expiringCount > 0 ? `${expiringCount} document(s) expire within ${expiringDays} days.` : ''}
        </Alert>
      ) : null}

      <Card className="mb-6">
        <div className="grid gap-4 md:grid-cols-4">
          <div className="rounded-lg border border-gray-100 bg-gray-50 p-4">
            <p className="text-sm font-medium text-gray-600">Total documents</p>
            <p className="mt-2 text-3xl font-semibold text-gray-900">{totalCount}</p>
          </div>
          <div className="rounded-lg border border-blue-100 bg-blue-50 p-4">
            <p className="text-sm font-medium text-blue-700">Tracked expirations</p>
            <p className="mt-2 text-3xl font-semibold text-blue-900">{trackedCount}</p>
            <p className="text-xs text-blue-700">Documents with an expiration date</p>
          </div>
          <div className="rounded-lg border border-amber-100 bg-amber-50 p-4">
            <p className="text-sm font-medium text-amber-700">Expiring soon</p>
            <p className="mt-2 text-3xl font-semibold text-amber-900">{expiringCount}</p>
            <p className="text-xs text-amber-700">Within {expiringDays} days</p>
          </div>
          <div className="rounded-lg border border-red-100 bg-red-50 p-4">
            <p className="text-sm font-medium text-red-700">Expired</p>
            <p className="mt-2 text-3xl font-semibold text-red-900">{expiredCount}</p>
            <p className="text-xs text-red-700">Needs renewal</p>
          </div>
        </div>
      </Card>

      <Card className="mb-6">
        <h2 className="text-lg font-semibold text-gray-900">Upload new document</h2>
        <p className="text-sm text-gray-500">Track expiration dates for contracts, certifications, and compliance files.</p>

        <form className="mt-4 space-y-4" onSubmit={handleSubmit}>
          <div className="grid gap-4 md:grid-cols-2">
            <Input
              label="Title"
              required
              modelValue={form.title}
              placeholder="e.g., ASE Master Certification"
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, title: value }))}
            />
            <Select
              label="Document type"
              required
              placeholder="Select type"
              options={DOCUMENT_TYPES}
              modelValue={form.document_type}
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, document_type: value }))}
            />
            <Input
              label="Category"
              modelValue={form.category}
              placeholder="e.g., WreckMaster, Insurance"
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, category: value }))}
            />
            <Input
              label="Issuing authority"
              modelValue={form.issuing_authority}
              placeholder="Issuer or agency"
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, issuing_authority: value }))}
            />
            <Input
              label="Document number"
              modelValue={form.document_number}
              placeholder="Certificate or policy number"
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, document_number: value }))}
            />
            <Input
              type="date"
              label="Issued date"
              modelValue={form.issued_date}
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, issued_date: value }))}
            />
            <Input
              type="date"
              label="Expiration date"
              modelValue={form.expiration_date}
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, expiration_date: value }))}
            />
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Document file<span className="text-red-500">*</span></label>
              <input
                type="file"
                className="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                onChange={(event) => setFile(event.target.files?.[0] || null)}
              />
              {file ? <p className="mt-1 text-xs text-gray-500">Selected: {file.name}</p> : null}
            </div>
          </div>

          <Textarea
            label="Notes"
            rows={3}
            modelValue={form.notes}
            placeholder="Optional notes or renewal instructions"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, notes: value }))}
          />

          {error ? <Alert variant="danger">{error}</Alert> : null}
          {success ? <Alert variant="success">{success}</Alert> : null}

          <div className="flex justify-end">
            <Button type="submit" loading={saving} disabled={saving}>Upload document</Button>
          </div>
        </form>
      </Card>

      <Card>
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h2 className="text-lg font-semibold text-gray-900">Document inventory</h2>
            <p className="text-sm text-gray-500">Monitor expirations and renewals.</p>
          </div>
          <div className="flex flex-col gap-3 md:flex-row md:items-center">
            <input
              type="text"
              value={filters.search}
              placeholder="Search by title, category, or number"
              onChange={(event) => handleFilterChange('search', event.target.value)}
              className="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 md:w-72"
            />
            <Select
              placeholder="All types"
              options={[{ value: '', label: 'All types' }, ...DOCUMENT_TYPES]}
              modelValue={filters.type}
              onUpdateModelValue={(value) => handleFilterChange('type', value)}
            />
            <Select
              options={STATUS_OPTIONS}
              modelValue={filters.status}
              onUpdateModelValue={(value) => handleFilterChange('status', value)}
            />
            <Button variant="primary" onClick={applyFilters}>Apply</Button>
          </div>
        </div>

        {loading ? (
          <div className="py-8 flex justify-center">
            <Loading text="Loading documents..." />
          </div>
        ) : documentRows.length === 0 ? (
          <div className="py-8 text-center text-gray-500">No documents found. Upload your first file above.</div>
        ) : (
          <div className="mt-6 overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Issued</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expires</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">File</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Uploaded by</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {documentRows.map((doc) => (
                  <tr key={doc.id} className={doc.expiry_status === 'expired' ? 'bg-red-50/40' : ''}>
                    <td className="px-4 py-3 text-sm font-medium text-gray-900">
                      <div>{doc.title}</div>
                      {doc.category ? <div className="text-xs text-gray-500">{doc.category}</div> : null}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">{doc.document_type || '—'}</td>
                    <td className="px-4 py-3 text-sm text-gray-600">{formatDate(doc.issued_date)}</td>
                    <td className="px-4 py-3 text-sm text-gray-600">{formatDate(doc.expiration_date)}</td>
                    <td className="px-4 py-3 text-sm">
                      <Badge variant={doc.status.variant}>{doc.status.label}</Badge>
                    </td>
                    <td className="px-4 py-3 text-sm">
                      {doc.file_path ? (
                        <a className="text-primary-600 hover:text-primary-700" href={doc.file_path} target="_blank" rel="noreferrer">
                          View file
                        </a>
                      ) : '—'}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">{doc.uploaded_by_name || '—'}</td>
                    <td className="px-4 py-3 text-sm text-right">
                      <Button variant="danger" size="sm" onClick={() => handleDelete(doc)}>Delete</Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
