import { useState } from 'react'

import CsvUploadModal from '../../components/import/CsvUploadModal'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import { uploadImportCsv } from '../../../services/import.service'

const vendorTemplate = {
  fileName: 'vendor-import-template.csv',
  headers: ['name', 'description', 'is_parts_supplier'],
  sampleRows: [
    {
      name: 'ACME Parts Co.',
      description: 'Preferred brake supplier',
      is_parts_supplier: 'true',
    },
  ],
  note: 'Use true/false for the is_parts_supplier column.',
}

export default function VendorList() {
  const [showImportModal, setShowImportModal] = useState(false)

  const handleVendorImport = async (file, dryRun) => {
    return uploadImportCsv('vendors', file, { dryRun })
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Vendors</h1>
          <p className="mt-1 text-sm text-gray-500">Maintain your preferred vendor list for purchasing.</p>
        </div>
        <Button variant="secondary" onClick={() => setShowImportModal(true)}>
          <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
          </svg>
          Import CSV
        </Button>
      </div>

      <Card>
        <div className="space-y-2">
          <p className="text-sm text-gray-600">Vendor management is coming soon in the React app.</p>
          <p className="text-sm text-gray-600">Use the CSV import to seed or update your vendor list now.</p>
        </div>
      </Card>

      <CsvUploadModal
        open={showImportModal}
        onClose={() => setShowImportModal(false)}
        title="Import Vendors CSV"
        description="Upload a CSV file to create or update vendor records."
        template={vendorTemplate}
        confirmLabel="Import Vendors"
        onUpload={handleVendorImport}
      />
    </div>
  )
}
