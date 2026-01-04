import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import Table from '../../components/ui/Table'
import { deleteVehicleMaster, listVehicleMaster, uploadVehicleMasterCsv } from '../../../services/vehicle-master.service'
import { useToast } from '../../stores/toast'

const columns = [
  { key: 'year', label: 'Year' },
  { key: 'make', label: 'Make' },
  { key: 'model', label: 'Model' },
  { key: 'engine', label: 'Engine' },
  { key: 'transmission', label: 'Transmission' },
  { key: 'drive', label: 'Drive' },
  { key: 'trim', label: 'Trim' },
]

export default function VehicleMasterList() {
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [loading, setLoading] = useState(false)
  const [vehicles, setVehicles] = useState([])
  const [filters, setFilters] = useState({ year: '', make: '', model: '', term: '' })

  const [showUploadModal, setShowUploadModal] = useState(false)
  const [selectedFile, setSelectedFile] = useState(null)
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState('')
  const [uploadSuccess, setUploadSuccess] = useState('')

  const [vehicleToDelete, setVehicleToDelete] = useState(null)
  const [deleting, setDeleting] = useState(false)

  const loadVehicles = useCallback(async () => {
    setLoading(true)
    const params = {}
    Object.entries(filters).forEach(([key, value]) => {
      if (value) params[key] = value
    })

    try {
      const data = await listVehicleMaster(params)
      setVehicles(Array.isArray(data) ? data : [])
    } finally {
      setLoading(false)
    }
  }, [filters])

  useEffect(() => {
    loadVehicles()
  }, [loadVehicles])

  const handleFileSelect = (event) => {
    const file = event.target.files?.[0] || null
    setSelectedFile(file)
    setUploadError('')
    setUploadSuccess('')
  }

  const uploadCsv = async () => {
    if (!selectedFile) return
    setUploading(true)
    setUploadError('')
    setUploadSuccess('')

    try {
      await uploadVehicleMasterCsv(selectedFile)
      setUploadSuccess('CSV uploaded successfully!')
      success('Vehicle CSV uploaded successfully')
      setSelectedFile(null)
      loadVehicles()
    } catch (uploadErr) {
      setUploadError(uploadErr.response?.data?.message || 'Failed to upload CSV')
      error('Failed to upload CSV')
    } finally {
      setUploading(false)
    }
  }

  const confirmDelete = (row) => {
    setVehicleToDelete(row)
  }

  const deleteVehicle = async () => {
    if (!vehicleToDelete) return
    setDeleting(true)
    try {
      await deleteVehicleMaster(vehicleToDelete.id)
      success('Vehicle deleted successfully')
      setVehicleToDelete(null)
      loadVehicles()
    } catch (deleteErr) {
      error(deleteErr.response?.data?.message || 'Failed to delete vehicle')
    } finally {
      setDeleting(false)
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Vehicle Database</h1>
          <p className="mt-1 text-sm text-gray-500">Manage vehicle specifications in the master database</p>
        </div>
        <div className="flex gap-3">
          <Button variant="secondary" onClick={() => setShowUploadModal(true)}>
            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
            Upload CSV
          </Button>
          <Button onClick={() => navigate('/cp/vehicle-master/create')}>
            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
            </svg>
            Add Vehicle
          </Button>
        </div>
      </div>

      <Card>
        <div className="flex flex-col gap-4">
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Year</label>
              <Input
                value={filters.year}
                type="number"
                placeholder="2024"
                onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, year: value }))}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Make</label>
              <Input
                value={filters.make}
                placeholder="Ford"
                onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, make: value }))}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Model</label>
              <Input
                value={filters.model}
                placeholder="F-150"
                onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, model: value }))}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Search term</label>
              <Input
                value={filters.term}
                placeholder="Engine, transmission..."
                onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, term: value }))}
              />
            </div>
          </div>

          <Table
            columns={columns}
            data={vehicles}
            loading={loading}
            hoverable
            cellRenderers={{
              year: ({ value }) => <span className="font-semibold">{value}</span>,
              engine: ({ value }) => <span className="text-sm text-gray-700">{value || 'N/A'}</span>,
              transmission: ({ value }) => <Badge variant="secondary">{value || 'Unknown'}</Badge>,
              drive: ({ value }) => <span className="text-sm">{value || '—'}</span>,
            }}
            renderActions={(row) => (
              <div className="flex gap-2 justify-end">
                <Button size="sm" variant="secondary" onClick={() => navigate(`/cp/vehicle-master/${row.id}/edit`)}>
                  Edit
                </Button>
                <Button size="sm" variant="danger" onClick={() => confirmDelete(row)} loading={deleting && vehicleToDelete?.id === row.id}>
                  Delete
                </Button>
              </div>
            )}
            renderEmpty={<p className="text-sm text-gray-500">No vehicles found for the current filters.</p>}
          />
        </div>
      </Card>

      <Modal
        open={showUploadModal}
        onClose={() => setShowUploadModal(false)}
        title="Upload Vehicle CSV"
        content={(
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">CSV File</label>
              <input
                type="file"
                accept=".csv"
                onChange={handleFileSelect}
                className="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
              />
              <p className="mt-2 text-xs text-gray-500">
                CSV should have columns: year, make, model, engine, transmission, drive, trim (optional)
              </p>
            </div>

            {uploadError ? (
              <div className="rounded-md bg-red-50 p-3">
                <p className="text-sm text-red-800">{uploadError}</p>
              </div>
            ) : null}

            {uploadSuccess ? (
              <div className="rounded-md bg-green-50 p-3">
                <p className="text-sm text-green-800">{uploadSuccess}</p>
              </div>
            ) : null}
          </div>
        )}
        footer={(
          <div className="flex gap-3 justify-end">
            <Button variant="secondary" onClick={() => setShowUploadModal(false)}>Cancel</Button>
            <Button onClick={uploadCsv} loading={uploading} disabled={!selectedFile}>Upload</Button>
          </div>
        )}
      />

      <Modal
        open={Boolean(vehicleToDelete)}
        onClose={() => setVehicleToDelete(null)}
        title="Delete Vehicle"
        content={(
          <div>
            <p className="text-sm text-gray-700">Are you sure you want to delete this vehicle?</p>
            {vehicleToDelete ? (
              <div className="mt-3 rounded-md bg-gray-50 p-3">
                <p className="text-sm font-medium">{vehicleToDelete.year} {vehicleToDelete.make} {vehicleToDelete.model}</p>
                <p className="text-xs text-gray-600 mt-1">{vehicleToDelete.engine} • {vehicleToDelete.transmission} • {vehicleToDelete.drive}</p>
              </div>
            ) : null}
            <p className="mt-3 text-xs text-red-600">This action cannot be undone.</p>
          </div>
        )}
        footer={(
          <div className="flex gap-3 justify-end">
            <Button variant="secondary" onClick={() => setVehicleToDelete(null)}>Cancel</Button>
            <Button variant="danger" onClick={deleteVehicle} loading={deleting}>Delete</Button>
          </div>
        )}
      />
    </div>
  )
}
