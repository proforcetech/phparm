import { useEffect, useMemo, useState } from 'react'

import Button from '../ui/Button'
import Modal from '../ui/Modal'

const buildCsvTemplate = (headers, sampleRows = []) => {
  const lines = [headers.join(',')]
  sampleRows.forEach((row) => {
    lines.push(headers.map((header) => row[header] ?? '').join(','))
  })
  return lines.join('\n')
}

export default function CsvUploadModal({
  open,
  title,
  description,
  template,
  onClose,
  onUpload,
  confirmLabel = 'Import',
}) {
  const [selectedFile, setSelectedFile] = useState(null)
  const [dryRun, setDryRun] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')

  const templateCsv = useMemo(() => {
    if (!template?.headers?.length) return ''
    return buildCsvTemplate(template.headers, template.sampleRows)
  }, [template])

  const templateUrl = useMemo(() => {
    if (!templateCsv) return ''
    return URL.createObjectURL(new Blob([templateCsv], { type: 'text/csv' }))
  }, [templateCsv])

  useEffect(() => () => {
    if (templateUrl) {
      URL.revokeObjectURL(templateUrl)
    }
  }, [templateUrl])

  useEffect(() => {
    if (!open) {
      setSelectedFile(null)
      setDryRun(true)
      setSubmitting(false)
      setResult(null)
      setError('')
    }
  }, [open])

  const handleFileSelect = (event) => {
    setSelectedFile(event.target.files?.[0] || null)
    setResult(null)
    setError('')
  }

  const handleUpload = async () => {
    if (!selectedFile || submitting) return
    setSubmitting(true)
    setError('')
    setResult(null)

    try {
      const uploadResult = await onUpload(selectedFile, dryRun)
      setResult(uploadResult)
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to import CSV')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      content={(
        <div className="space-y-4">
          {description ? <p className="text-sm text-gray-600">{description}</p> : null}

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">CSV File</label>
            <input
              type="file"
              accept=".csv"
              onChange={handleFileSelect}
              className="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
            />
            {templateUrl ? (
              <div className="mt-2">
                <a
                  href={templateUrl}
                  download={template?.fileName || 'template.csv'}
                  className="text-sm text-primary-600 hover:text-primary-500"
                >
                  Download CSV template
                </a>
              </div>
            ) : null}
            {template?.note ? <p className="mt-2 text-xs text-gray-500">{template.note}</p> : null}
          </div>

          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              checked={dryRun}
              onChange={(event) => setDryRun(event.target.checked)}
              className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
            />
            Run dry validation only (no data will be saved).
          </label>

          {error ? (
            <div className="rounded-md bg-red-50 p-3">
              <p className="text-sm text-red-800">{error}</p>
            </div>
          ) : null}

          {result ? (
            <div className="rounded-md bg-gray-50 p-3 space-y-2">
              <p className="text-sm text-gray-700 font-medium">Import summary</p>
              <div className="text-xs text-gray-600 space-y-1">
                <p>Created: {result.created ?? 0}</p>
                <p>Updated: {result.updated ?? 0}</p>
                <p>Failed: {result.failed ?? 0}</p>
                {result.dry_run ? <p>Dry run: Yes</p> : null}
              </div>
              {result.errors?.length ? (
                <div className="text-xs text-red-600 space-y-1 max-h-40 overflow-y-auto">
                  {result.errors.map((message, index) => (
                    <p key={`${message}-${index}`}>{message}</p>
                  ))}
                </div>
              ) : null}
            </div>
          ) : null}
        </div>
      )}
      footer={(
        <div className="flex gap-3 justify-end">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button onClick={handleUpload} loading={submitting} disabled={!selectedFile}>
            {dryRun ? 'Validate CSV' : confirmLabel}
          </Button>
        </div>
      )}
    />
  )
}
