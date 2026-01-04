import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'

const initialDocuments = [
  {
    id: 1,
    label: 'Tow authorization form',
    required: true,
    path: '/uploads/release/tow-authorization.pdf',
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
  },
]

export default function ReleaseChecklist() {
  const [documents, setDocuments] = useState(initialDocuments)

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
                {doc.path ? <Badge variant="success">Uploaded</Badge> : <Badge variant="warning">Pending</Badge>}
              </div>
            </div>
          ))}
        </div>
        <div className="mt-6 flex justify-end">
          <Button disabled={!checklistReady}>Release Vehicle</Button>
        </div>
      </Card>
    </div>
  )
}
