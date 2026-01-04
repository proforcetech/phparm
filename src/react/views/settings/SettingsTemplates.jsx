import { useCallback, useEffect, useMemo, useState } from 'react'
import ReactQuill from 'react-quill-new'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'

import 'react-quill-new/dist/quill.snow.css'

const emptyDraft = {
  subject: '',
  body: '',
  channel: 'email',
}

export default function SettingsTemplates() {
  const [templates, setTemplates] = useState([])
  const [selectedKey, setSelectedKey] = useState('')
  const [draft, setDraft] = useState(emptyDraft)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  const quillModules = useMemo(
    () => ({
      toolbar: [
        [{ header: [1, 2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['blockquote', 'code-block'],
        ['link'],
        ['clean'],
      ],
    }),
    [],
  )

  const quillFormats = useMemo(
    () => ['header', 'bold', 'italic', 'underline', 'strike', 'list', 'bullet', 'blockquote', 'code-block', 'link'],
    [],
  )

  const selectTemplate = useCallback((template) => {
    setSelectedKey(template.template_key)
    setDraft({
      subject: template.subject ?? '',
      body: template.body ?? '',
      channel: template.channel ?? 'email',
    })
    setSuccess('')
    setError('')
  }, [])

  useEffect(() => {
    const loadTemplates = async () => {
      setLoading(true)
      setError('')

      try {
        const response = await fetch('/api/notifications/templates')
        if (!response.ok) {
          throw new Error('Unable to load templates.')
        }
        const data = await response.json()
        const items = data?.templates ?? []
        setTemplates(items)
        if (items.length > 0) {
          selectTemplate(items[0])
        } else {
          setSelectedKey('')
          setDraft(emptyDraft)
        }
      } catch (fetchError) {
        setError(fetchError?.message || 'Unable to load templates.')
      } finally {
        setLoading(false)
      }
    }

    loadTemplates()
  }, [selectTemplate])

  const activeTemplate = useMemo(
    () => templates.find((template) => template.template_key === selectedKey),
    [selectedKey, templates],
  )

  const hasChanges = useMemo(() => {
    if (!activeTemplate) {
      return false
    }

    return (
      (draft.subject ?? '') !== (activeTemplate.subject ?? '') ||
      (draft.body ?? '') !== (activeTemplate.body ?? '') ||
      (draft.channel ?? 'email') !== (activeTemplate.channel ?? 'email')
    )
  }, [activeTemplate, draft.body, draft.channel, draft.subject])

  const handleSave = async () => {
    if (!selectedKey || saving) {
      return
    }

    setSaving(true)
    setError('')
    setSuccess('')

    try {
      const response = await fetch(`/api/notifications/templates/${selectedKey}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          subject: draft.subject,
          body: draft.body,
          channel: draft.channel,
        }),
      })

      if (!response.ok) {
        const payload = await response.json().catch(() => ({}))
        throw new Error(payload?.error || 'Unable to save template.')
      }

      const payload = await response.json()
      const updated = payload?.template
      if (updated) {
        setTemplates((prev) =>
          prev.map((template) =>
            template.template_key === updated.template_key
              ? { ...template, subject: updated.subject, body: updated.body, channel: updated.channel }
              : template,
          ),
        )
        setSuccess('Template saved successfully.')
      }
    } catch (saveError) {
      setError(saveError?.message || 'Unable to save template.')
    } finally {
      setSaving(false)
    }
  }

  const isEmptyState = !loading && templates.length === 0

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-2xl font-semibold text-gray-900">Notification templates</h2>
        <p className="mt-1 text-sm text-gray-600">
          Edit notification subjects and HTML bodies. Changes are saved as HTML and used immediately.
        </p>
      </div>

      {error ? (
        <Alert variant="danger" title="Template error" message={error} />
      ) : null}
      {success ? <Alert variant="success" title="Saved" message={success} /> : null}

      <Card>
        {loading ? (
          <Loading text="Loading templates..." />
        ) : (
          <div className="grid gap-6 lg:grid-cols-[260px_1fr]">
            <div className="space-y-2">
              <h3 className="text-sm font-semibold text-gray-700">Available templates</h3>
              {isEmptyState ? (
                <p className="text-sm text-gray-500">No notification templates found.</p>
              ) : (
                <div className="space-y-2">
                  {templates.map((template) => {
                    const isActive = template.template_key === selectedKey
                    return (
                      <button
                        key={template.template_key}
                        type="button"
                        onClick={() => selectTemplate(template)}
                        className={`w-full text-left rounded-lg border px-3 py-2 transition ${
                          isActive
                            ? 'border-primary-500 bg-primary-50 text-primary-700'
                            : 'border-gray-200 hover:border-primary-300 hover:bg-gray-50'
                        }`}
                      >
                        <div className="text-sm font-medium">{template.template_key}</div>
                        <div className="text-xs text-gray-500">{template.channel ?? 'email'}</div>
                      </button>
                    )
                  })}
                </div>
              )}
            </div>

            <div className="space-y-4">
              {selectedKey ? (
                <>
                  <div className="flex items-center justify-between">
                    <div>
                      <h3 className="text-lg font-semibold text-gray-900">{selectedKey}</h3>
                      <p className="text-sm text-gray-500">
                        Channel: <span className="font-medium text-gray-700">{draft.channel}</span>
                      </p>
                    </div>
                    <Button
                      variant="primary"
                      loading={saving}
                      disabled={!hasChanges || saving}
                      onClick={handleSave}
                    >
                      Save template
                    </Button>
                  </div>

                  <Input
                    label="Subject"
                    modelValue={draft.subject}
                    onUpdateModelValue={(value) =>
                      setDraft((prev) => ({
                        ...prev,
                        subject: value,
                      }))
                    }
                    placeholder="Enter the email subject"
                  />

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor="template-body">
                      Body
                    </label>
                    <div className="rounded-lg border border-gray-200">
                      <ReactQuill
                        id="template-body"
                        theme="snow"
                        value={draft.body}
                        onChange={(value) =>
                          setDraft((prev) => ({
                            ...prev,
                            body: value,
                          }))
                        }
                        modules={quillModules}
                        formats={quillFormats}
                        className="min-h-[280px] [&_.ql-editor]:min-h-[220px]"
                      />
                    </div>
                    <p className="mt-2 text-xs text-gray-500">
                      Use template variables like <span className="font-mono">{'{{customer_name}}'}</span> where
                      applicable.
                    </p>
                  </div>
                </>
              ) : (
                <div className="text-sm text-gray-500">Select a template to edit its content.</div>
              )}
            </div>
          </div>
        )}
      </Card>
    </div>
  )
}
