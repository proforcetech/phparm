import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import CsvUploadModal from '../../components/import/CsvUploadModal'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import customerService from '../../../services/customer.service'
import { uploadImportCsv } from '../../../services/import.service'
import { useToast } from '../../stores/toast.jsx'

const perPage = 20
const customerTemplate = {
  fileName: 'customer-import-template.csv',
  headers: [
    'first_name',
    'last_name',
    'business_name',
    'email',
    'phone',
    'street',
    'city',
    'state',
    'postal_code',
    'country',
    'is_commercial',
    'tax_exempt',
    'notes',
    'external_reference',
  ],
  sampleRows: [
    {
      first_name: 'Jamie',
      last_name: 'Lee',
      business_name: 'Lee Automotive',
      email: 'jamie@example.com',
      phone: '555-0100',
      street: '123 Main St',
      city: 'Denver',
      state: 'CO',
      postal_code: '80202',
      country: 'USA',
      is_commercial: 'true',
      tax_exempt: 'false',
      notes: 'Preferred customer',
      external_reference: 'CUST-1001',
    },
  ],
  note: 'Provide a unique email or phone number for each customer.',
}

export default function CustomerList() {
  const navigate = useNavigate()
  const { success, error } = useToast()
  const [customers, setCustomers] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [deleteModal, setDeleteModal] = useState({ open: false, customer: null })
  const [deleting, setDeleting] = useState(false)
  const [showImportModal, setShowImportModal] = useState(false)

  const loadCustomers = useCallback(async () => {
    setLoading(true)
    try {
      const params = {
        limit: perPage,
        offset: (page - 1) * perPage,
      }
      if (search.trim()) {
        params.query = search.trim()
      }
      const response = await customerService.listCustomers(params)
      setCustomers(Array.isArray(response) ? response : response?.data || [])
      setTotal(response?.total || response?.length || 0)
    } catch {
      error('Failed to load customers')
      setCustomers([])
    } finally {
      setLoading(false)
    }
  }, [page, search, error])

  useEffect(() => {
    loadCustomers()
  }, [loadCustomers])

  const handleSearch = (e) => {
    e.preventDefault()
    setPage(1)
    loadCustomers()
  }

  const handleDelete = async () => {
    if (!deleteModal.customer) return
    setDeleting(true)
    try {
      await customerService.deleteCustomer(deleteModal.customer.id)
      success('Customer deleted successfully')
      setDeleteModal({ open: false, customer: null })
      loadCustomers()
    } catch {
      error('Failed to delete customer')
    } finally {
      setDeleting(false)
    }
  }

  const handleCustomerImport = async (file, dryRun) => {
    const result = await uploadImportCsv('customers', file, { dryRun })
    if (!dryRun) {
      await loadCustomers()
    }
    return result
  }

  const hasNext = customers.length === perPage
  const getCustomerDisplayName = (customer) => {
    const firstName = customer?.first_name?.trim() || ''
    const lastName = customer?.last_name?.trim() || ''
    const fullName = [firstName, lastName].filter(Boolean).join(' ').trim()
    const companyName = customer?.company_name?.trim() || customer?.business_name?.trim() || ''

    return customer?.name?.trim() || fullName || companyName || '—'
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Customers</h1>
          <p className="mt-1 text-sm text-gray-500">Manage your customer database</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => setShowImportModal(true)}>
            <svg className="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
            Import CSV
          </Button>
          <Button onClick={() => navigate('/cp/customers/create')}>
            <svg className="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Add Customer
          </Button>
        </div>
      </div>

      <Card>
        <form onSubmit={handleSearch} className="mb-4">
          <div className="flex gap-2">
            <Input
              value={search}
              onUpdateModelValue={setSearch}
              placeholder="Search by name, email, or phone..."
              className="flex-1"
            />
            <Button type="submit" variant="secondary">Search</Button>
          </div>
        </form>

        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading customers..." />
          </div>
        ) : customers.length === 0 ? (
          <div className="text-center py-12">
            <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <h3 className="mt-2 text-sm font-medium text-gray-900">No customers found</h3>
            <p className="mt-1 text-sm text-gray-500">Get started by adding a new customer.</p>
            <div className="mt-4">
              <Button onClick={() => navigate('/cp/customers/create')}>Add Customer</Button>
            </div>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {customers.map((customer) => (
                    <tr key={customer.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3">
                        <Link to={`/cp/customers/${customer.id}`} className="text-primary-600 hover:text-primary-500 font-medium">
                          {getCustomerDisplayName(customer)}
                        </Link>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">{customer.email || '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{customer.phone || '—'}</td>
                      <td className="px-4 py-3">
                        <Badge size="sm" variant={customer.active !== false ? 'success' : 'default'}>
                          {customer.active !== false ? 'Active' : 'Inactive'}
                        </Badge>
                      </td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex justify-end gap-2">
                          <Button size="sm" variant="ghost" onClick={() => navigate(`/cp/customers/${customer.id}/edit`)}>
                            Edit
                          </Button>
                          <Button size="sm" variant="ghost" className="text-red-600 hover:text-red-700" onClick={() => setDeleteModal({ open: true, customer })}>
                            Delete
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="flex justify-between items-center mt-4 pt-4 border-t">
              <span className="text-sm text-gray-500">
                Showing {(page - 1) * perPage + 1} - {(page - 1) * perPage + customers.length} {total ? `of ${total}` : ''}
              </span>
              <div className="flex gap-2">
                <Button variant="ghost" size="sm" disabled={page === 1} onClick={() => setPage(page - 1)}>
                  Previous
                </Button>
                <Button variant="ghost" size="sm" disabled={!hasNext} onClick={() => setPage(page + 1)}>
                  Next
                </Button>
              </div>
            </div>
          </>
        )}
      </Card>

      <Modal
        open={deleteModal.open}
        title="Delete Customer"
        onClose={() => setDeleteModal({ open: false, customer: null })}
      >
        <p className="text-sm text-gray-600 mb-4">
          Are you sure you want to delete <strong>{deleteModal.customer ? getCustomerDisplayName(deleteModal.customer) : 'this customer'}</strong>? This action cannot be undone.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteModal({ open: false, customer: null })}>
            Cancel
          </Button>
          <Button variant="danger" loading={deleting} onClick={handleDelete}>
            Delete
          </Button>
        </div>
      </Modal>

      <CsvUploadModal
        open={showImportModal}
        onClose={() => setShowImportModal(false)}
        title="Import Customers CSV"
        description="Upload a CSV file to create or update customer profiles."
        template={customerTemplate}
        confirmLabel="Import Customers"
        onUpload={handleCustomerImport}
      />
    </div>
  )
}
