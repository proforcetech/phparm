import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import Textarea from '../../components/ui/Textarea'
import storageService from '../../../services/storage.service'
import { useToast } from '../../stores/toast.jsx'

const statusVariant = {
  draft: 'secondary',
  ready: 'warning',
  scheduled: 'warning',
  sent: 'success',
  void: 'secondary',
}

const templateOptions = [
  { value: 'storage.notice.notice_of_claim', label: 'Notice of Claim' },
  { value: 'storage.notice.lien_notice', label: 'Lien Notice' },
]

const templateTokens = [
  '{{shop_name}}',
  '{{shop_address}}',
  '{{shop_phone}}',
  '{{case_number}}',
  '{{notice_date}}',
  '{{status}}',
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
  '{{fees_billable_days}}',
  '{{fees_daily_rate}}',
  '{{fees_daily_amount}}',
  '{{fees_gate_fee}}',
  '{{fees_total}}',
  '{{notice_type}}',
  '{{due_date}}',
]

export default function NoticeGeneration() {
  const { success, error } = useToast()
  const [form, setForm] = useState({
    case_number: '',
    notice_type: 'Notice of Claim',
    due_date: '',
  })
  const [creating, setCreating] = useState(false)
  const [notices, setNotices] = useState([])
  const [noticesState, setNoticesState] = useState({
    loading: true,
    error: '',
  })
  const [sendingNoticeId, setSendingNoticeId] = useState(null)
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

  const columns = useMemo(() => ([
    { key: 'case_number', label: 'Case #' },
    { key: 'owner_name', label: 'Owner' },
    { key: 'vehicle_summary', label: 'Vehicle' },
    { key: 'days_held', label: 'Days Held' },
    { key: 'notice_type', label: 'Notice Type' },
    { key: 'notice_date', label: 'Notice Date' },
    { key: 'due_date', label: 'Due Date' },
    { key: 'status', label: 'Status' },
  ]), [])

  const generateNotice = async (event) => {
    event.preventDefault()
    if (!form.case_number) {
      error('Case number is required.')
      return
    }

    setCreating(true)
    try {
      const payload = {
        case_number: form.case_number,
        notice_type: form.notice_type,
        due_date: form.due_date || null,
      }
      const response = await storageService.createNotice(payload)
      success(`Generated ${form.notice_type} for ${form.case_number}`)
      setForm((prev) => ({ ...prev, case_number: '', due_date: '' }))
      await loadNotices()

      if (response?.notice?.id) {
        await previewNotice(response.notice.id)
      }
    } catch (err) {
      console.error(err)
      error(err?.response?.data?.error || 'Unable to generate notice.')
    } finally {
      setCreating(false)
    }
  }

  const loadNotices = async () => {
    setNoticesState((prev) => ({ ...prev, loading: true, error: '' }))
    try {
      const response = await storageService.listNotices()
      setNotices(response?.data ?? [])
    } catch (err) {
      console.error(err)
      setNoticesState((prev) => ({ ...prev, error: 'Unable to load lien notices.' }))
      error('Unable to load lien notices.')
    } finally {
      setNoticesState((prev) => ({ ...prev, loading: false }))
    }
  }

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
      setTemplateState((prev) => ({ ...prev, error: 'Unable to load notice templates.' }))
      error('Unable to load notice templates.')
    } finally {
      setTemplateState((prev) => ({ ...prev, loading: false }))
    }
  }

  useEffect(() => {
    loadTemplates()
    loadNotices()
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

      success('Notice templates updated.')
    } catch (err) {
      console.error(err)
      setTemplateState((prev) => ({ ...prev, error: 'Unable to save notice templates.' }))
      error('Unable to save notice templates.')
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

  const handleFormPreview = () => {
    const mapping = {
      'Notice of Claim': 'storage.notice.notice_of_claim',
      'Lien Notice': 'storage.notice.lien_notice',
    }
    const templateKey = mapping[form.notice_type]
    if (!templateKey) {
      error('Preview is not available for this notice type yet.')
      return
    }
    previewTemplate(templateKey)
  }

  const previewNotice = async (noticeId) => {
    try {
      const blob = await storageService.previewNotice(noticeId)
      const url = URL.createObjectURL(blob)
      if (previewUrl) {
        URL.revokeObjectURL(previewUrl)
      }
      setPreviewUrl(url)
      setPreviewOpen(true)
    } catch (err) {
      console.error(err)
      error('Unable to preview notice PDF.')
    }
  }

  const markNoticeSent = async (noticeId) => {
    setSendingNoticeId(noticeId)
    try {
      await storageService.sendNotice(noticeId)
      success('Notice marked as sent.')
      await loadNotices()
    } catch (err) {
      console.error(err)
      error('Unable to mark notice as sent.')
    } finally {
      setSendingNoticeId(null)
    }
  }

  const formattedNotices = useMemo(() => notices.map((notice) => {
    const vehicleSummary = [notice.vehicle_year, notice.vehicle_make, notice.vehicle_model]
      .filter(Boolean)
      .join(' ')
    return {
      ...notice,
      owner_name: notice.owner_name || '—',
      vehicle_summary: vehicleSummary || '—',
      days_held: notice.days_held ?? '—',
      due_date: notice.due_date || '—',
    }
  }), [notices])

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Notice Generation</h1>
          <p className="text-sm text-gray-500">Create Notice of Claim and lien document packages.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to="/cp/storage/impound-intake">
            <Button variant="secondary">Impound Intake</Button>
          </Link>
          <Link to="/cp/storage/auction-management">
            <Button variant="secondary">Auction Management</Button>
          </Link>
          <Link to="/cp/storage/spot-checks">
            <Button variant="secondary">Inventory Spot-Checks</Button>
          </Link>
          <Link to="/cp/storage/ledger">
            <Button variant="secondary">Fee Ledger</Button>
          </Link>
          <Link to="/cp/storage/release-checklist">
            <Button variant="secondary">Release Checklist</Button>
          </Link>
        </div>
      </div>

      <Card>
        <form className="grid gap-4 md:grid-cols-3" onSubmit={generateNotice}>
          <Input
            label="Case Number"
            modelValue={form.case_number}
            placeholder="IMP-2024-017"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, case_number: value }))}
          />
          <Select
            label="Notice Type"
            modelValue={form.notice_type}
            options={[
              { value: 'Notice of Claim', label: 'Notice of Claim' },
              { value: 'Lien Notice', label: 'Lien Notice' },
              { value: 'Final Demand', label: 'Final Demand' },
            ]}
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, notice_type: value }))}
          />
          <Input
            label="Response Due"
            type="date"
            modelValue={form.due_date}
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, due_date: value }))}
          />
          <div className="md:col-span-3 flex justify-end gap-2">
            <Button variant="outline" type="button" onClick={handleFormPreview}>Preview PDF</Button>
            <Button type="submit" loading={creating} disabled={creating}>Generate PDF</Button>
          </div>
        </form>
      </Card>

      <Card>
        <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-lg font-semibold text-gray-900">Notice Templates</h2>
            <p className="text-sm text-gray-500">Edit PDF layouts stored in settings.</p>
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
            rows={18}
            helperText={templateState.loading ? 'Loading template...' : 'Use the tokens to inject case data.'}
            modelValue={templates[activeTemplateKey] ?? ''}
            onUpdateModelValue={(value) =>
              setTemplates((prev) => ({ ...prev, [activeTemplateKey]: value }))
            }
          />
        </div>
      </Card>

      <Card>
        <div className="mb-4">
          <h2 className="text-lg font-semibold text-gray-900">Recent Notices</h2>
          <p className="text-sm text-gray-500">Track delivery status for outgoing lien notices.</p>
        </div>
        {noticesState.error ? (
          <p className="mb-3 text-sm text-red-600">{noticesState.error}</p>
        ) : null}
        <Table
          columns={columns}
          data={formattedNotices}
          loading={noticesState.loading}
          hoverable={false}
          renderEmpty={<p className="text-sm text-gray-500">No notices flagged for lien review yet.</p>}
          cellRenderers={{
            status: ({ row }) => (
              <Badge variant={statusVariant[row.status] || 'default'}>
                {row.status}
              </Badge>
            ),
          }}
          renderActions={(row) => (
            <div className="flex justify-end gap-2">
              <Button
                variant="outline"
                size="xs"
                type="button"
                onClick={() => previewNotice(row.id)}
              >
                Preview
              </Button>
              {row.status !== 'sent' ? (
                <Button
                  variant="secondary"
                  size="xs"
                  type="button"
                  loading={sendingNoticeId === row.id}
                  disabled={sendingNoticeId === row.id}
                  onClick={() => markNoticeSent(row.id)}
                >
                  Mark Sent
                </Button>
              ) : null}
            </div>
          )}
        />
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
