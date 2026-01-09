import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import { useToast } from '../../stores/toast.jsx'

const initialDocuments = [
  {
    id: 1,
    label: 'Tow authorization form',
    required: true,
    path: '/uploads/release/tow-authorization.pdf',
    templateKey: 'storage.notice.tow_authorization',
  },
  {
    id: 2,
    label: 'Owner ID verification',
    required: true,
    path: '',
  },
  {
    id: 3,
    label: 'Payment receipt',
    required: true,
    path: '',
  },
  {
    id: 4,
    label: 'Lien notice acknowledgment',
    required: false,
    path: '/uploads/release/lien-acknowledgment.pdf',
    templateKey: 'storage.notice.lien_ack',
  },
]

const templateOptions = [
  { value: 'storage.notice.tow_authorization', label: 'Tow Authorization' },
  { value: 'storage.notice.lien_ack', label: 'Lien Acknowledgment' },
]

const templateTokens = [
  '{{shop_name}}',
  '{{shop_address}}',
  '{{shop_phone}}',
  '{{case_number}}',
  '{{notice_date}}',
  '{{owner_name}}',
  '{{owner_address}}',
  '{{owner_city}}',
  '{{owner_state}}',
  '{{owner_zip}}',
  '{{owner_phone}}',
  '{{vehicle_year}}',
  '{{vehicle_make}}',
  '{{vehicle_model}}',
  '{{vehicle_vin}}',
  '{{vehicle_license_plate}}',
  '{{intake_location}}',
  '{{tow_provider}}',
  '{{fees_total}}',
]

export default function ReleaseChecklist() {
  const { success, error } = useToast()
  const [documents, setDocuments] = useState(initialDocuments)
  const [templates, setTemplates] = useState(() => (
    templateOptions.reduce((acc, option) => ({ ...acc, [option.value]: '' }), {})
  ))
  const [activeTemplateKey, setActiveTemplateKey] = useState(templateOptions[0].value)
  const [templateState, setTemplateState] = useState({
    loading: true,
    saving: false,
    error: '',
  })
  const [previewUrl, setPreviewUrl] = useState('')
  const [previewOpen, setPreviewOpen] = useState(false)

  const updateDocument = (id, file) => {
    setDocuments((prev) => prev.map((doc) => (
      doc.id === id
        ? { ...doc, path: file ? `/uploads/release/${file.name}` : doc.path, file }
        : doc
    )))
  }

  const checklistReady = useMemo(() => {
    return documents.every((doc) => !doc.required || doc.path)
  }, [documents])

  const loadTemplates = async () => {
    setTemplateState((prev) => ({ ...prev, loading: true, error: '' }))
    try {
      const responses = await Promise.all(
        templateOptions.map((option) =>
          fetch(`/api/settings/${encodeURIComponent(option.value)}`)
        )
      )

      const values = await Promise.all(
        responses.map(async (response) => {
          if (!response.ok) {
            throw new Error('Failed to load templates.')
          }
          const data = await response.json()
          return data?.value ?? ''
        })
      )

      setTemplates((prev) => {
        const updated = { ...prev }
        templateOptions.forEach((option, index) => {
          updated[option.value] = values[index]
        })
        return updated
      })
    } catch (err) {
      console.error(err)
      setTemplateState((prev) => ({ ...prev, error: 'Unable to load release templates.' }))
      error('Unable to load release templates.')
    } finally {
      setTemplateState((prev) => ({ ...prev, loading: false }))
    }
  }

  useEffect(() => {
    loadTemplates()
  }, [])

  useEffect(() => () => {
    if (previewUrl) {
      URL.revokeObjectURL(previewUrl)
    }
  }, [previewUrl])

  const saveTemplates = async () => {
    setTemplateState((prev) => ({ ...prev, saving: true, error: '' }))
    try {
      const payload = templateOptions.reduce((acc, option) => {
        acc[option.value] = templates[option.value] ?? ''
        return acc
      }, {})

      const response = await fetch('/api/settings', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })

      if (!response.ok) {
        throw new Error('Failed to save templates.')
      }

      success('Release templates updated.')
    } catch (err) {
      console.error(err)
      setTemplateState((prev) => ({ ...prev, error: 'Unable to save release templates.' }))
      error('Unable to save release templates.')
    } finally {
      setTemplateState((prev) => ({ ...prev, saving: false }))
    }
  }

  const closePreview = () => {
    if (previewUrl) {
      URL.revokeObjectURL(previewUrl)
    }
    setPreviewUrl('')
    setPreviewOpen(false)
  }

  const previewTemplate = async (templateKey) => {
    try {
      const response = await fetch('/api/storage/templates/preview', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          template_key: templateKey,
          template: templates[templateKey] ?? '',
        }),
      })

      if (!response.ok) {
        throw new Error('Unable to preview PDF.')
      }

      const blob = await response.blob()
      const url = URL.createObjectURL(blob)
      if (previewUrl) {
        URL.revokeObjectURL(previewUrl)
      }
      setPreviewUrl(url)
      setPreviewOpen(true)
    } catch (err) {
      console.error(err)
      error('Unable to preview PDF.')
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Vehicle Release Checklist</h1>
          <p className="text-sm text-gray-500">Confirm required documentation before releasing vehicles.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to="/cp/storage/impound-intake">
            <Button variant="secondary">Impound Intake</Button>
          </Link>
          <Link to="/cp/storage/auction-management">
            <Button variant="secondary">Auction Management</Button>
          <Link to="/cp/storage/spot-checks">
            <Button variant="secondary">Inventory Spot-Checks</Button>
          </Link>
          <Link to="/cp/storage/ledger">
            <Button variant="secondary">Fee Ledger</Button>
          </Link>
          <Link to="/cp/storage/notices">
            <Button variant="secondary">Notice Generation</Button>
          </Link>
        </div>
      </div>

      <Card>
        <div className="mb-4 flex items-center justify-between">
          <div>
            <h2 className="text-lg font-semibold text-gray-900">Required Documents</h2>
            <p className="text-sm text-gray-500">Uploads are stored in <code>/public/uploads</code>.</p>
          </div>
          <Badge variant={checklistReady ? 'success' : 'warning'}>
            {checklistReady ? 'Ready to release' : 'Missing documents'}
          </Badge>
        </div>
        <div className="space-y-4">
          {documents.map((doc) => (
            <div key={doc.id} className="flex flex-col gap-2 rounded-lg border border-gray-200 p-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p className="text-sm font-medium text-gray-900">
                  {doc.label}
                  {doc.required ? <span className="text-red-500"> *</span> : null}
                </p>
                {doc.path ? (
                  <a className="text-sm text-primary-600 hover:underline" href={doc.path} target="_blank" rel="noreferrer">
                    {doc.path}
                  </a>
                ) : (
                  <p className="text-sm text-gray-500">No document uploaded yet.</p>
                )}
              </div>
              <div className="flex items-center gap-3">
                <input
                  type="file"
                  onChange={(event) => updateDocument(doc.id, event.target.files?.[0])}
                  className="text-sm"
                />
                {doc.templateKey ? (
                  <Button variant="secondary" type="button" onClick={() => previewTemplate(doc.templateKey)}>
                    Preview PDF
                  </Button>
                ) : null}
                {doc.path ? <Badge variant="success">Uploaded</Badge> : <Badge variant="warning">Pending</Badge>}
              </div>
            </div>
          ))}
        </div>
        <div className="mt-6 flex justify-end">
          <Button disabled={!checklistReady}>Release Vehicle</Button>
        </div>
      </Card>

      <Card>
        <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-lg font-semibold text-gray-900">Release Templates</h2>
            <p className="text-sm text-gray-500">Edit tow authorization and acknowledgment PDFs.</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" type="button" onClick={loadTemplates} disabled={templateState.loading}>
              Refresh
            </Button>
            <Button type="button" onClick={() => previewTemplate(activeTemplateKey)}>
              Preview PDF
            </Button>
            <Button type="button" onClick={saveTemplates} disabled={templateState.saving}>
              {templateState.saving ? 'Saving...' : 'Save Templates'}
            </Button>
          </div>
        </div>
        {templateState.error ? (
          <p className="text-sm text-red-600 mb-3">{templateState.error}</p>
        ) : null}
        <div className="grid gap-4 lg:grid-cols-[240px,1fr]">
          <div className="space-y-4">
            <Select
              label="Template"
              modelValue={activeTemplateKey}
              options={templateOptions}
              onUpdateModelValue={setActiveTemplateKey}
            />
            <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600">
              <p className="font-semibold text-gray-700">Available tokens</p>
              <div className="mt-2 grid grid-cols-2 gap-1">
                {templateTokens.map((token) => (
                  <span key={token} className="rounded bg-white px-2 py-1 font-mono text-[11px] text-gray-600">
                    {token}
                  </span>
                ))}
              </div>
            </div>
          </div>
          <Textarea
            label="Template HTML"
            rows={16}
            helperText={templateState.loading ? 'Loading template...' : 'Use the tokens to inject release data.'}
            modelValue={templates[activeTemplateKey] ?? ''}
            onUpdateModelValue={(value) =>
              setTemplates((prev) => ({ ...prev, [activeTemplateKey]: value }))
            }
          />
        </div>
      </Card>

      <Modal
        open={previewOpen}
        title="PDF Preview"
        size="xl"
        onClose={closePreview}
      >
        {previewUrl ? (
          <iframe title="PDF Preview" src={previewUrl} className="h-[70vh] w-full rounded border border-gray-200" />
        ) : (
          <p className="text-sm text-gray-500">Generating preview...</p>
        )}
      </Modal>
    </div>
  )
}
