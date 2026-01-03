import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import customerService from '../../../services/customer.service'
import { useToast } from '../../stores/toast.jsx'

export default function CustomerDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [customer, setCustomer] = useState(null)
  const [vehicles, setVehicles] = useState([])
  const [loading, setLoading] = useState(true)
  const [deleteModal, setDeleteModal] = useState(false)
  const [deleting, setDeleting] = useState(false)

  const loadCustomer = useCallback(async () => {
    setLoading(true)
    try {
      const [customerData, vehicleData] = await Promise.all([
        customerService.getCustomer(id),
        customerService.getCustomerVehicles(id).catch(() => []),
      ])
      setCustomer(customerData)
      setVehicles(Array.isArray(vehicleData) ? vehicleData : vehicleData?.data || [])
    } catch {
      error('Failed to load customer')
      navigate('/cp/customers')
    } finally {
      setLoading(false)
    }
  }, [id, error, navigate])

  useEffect(() => {
    loadCustomer()
  }, [loadCustomer])

  const handleDelete = async () => {
    setDeleting(true)
    try {
      await customerService.deleteCustomer(id)
      success('Customer deleted successfully')
      navigate('/cp/customers')
    } catch {
      error('Failed to delete customer')
    } finally {
      setDeleting(false)
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-96">
        <Loading text="Loading customer..." />
      </div>
    )
  }

  if (!customer) {
    return (
      <div className="text-center py-12">
        <h3 className="text-lg font-medium text-gray-900">Customer not found</h3>
        <p className="mt-2 text-gray-500">The customer you&apos;re looking for doesn&apos;t exist.</p>
        <div className="mt-4">
          <Link to="/cp/customers">
            <Button>Back to Customers</Button>
          </Link>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="flex items-center gap-4">
          <Link to="/cp/customers" className="text-gray-500 hover:text-gray-700">
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{customer.name}</h1>
            <p className="mt-1 text-sm text-gray-500">Customer Details</p>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => navigate(`/cp/customers/${id}/edit`)}>
            Edit
          </Button>
          <Button variant="danger" onClick={() => setDeleteModal(true)}>
            Delete
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <Card className="lg:col-span-2">
          <h3 className="text-lg font-medium text-gray-900 mb-4">Contact Information</h3>
          <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <dt className="text-sm font-medium text-gray-500">Email</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {customer.email ? (
                  <a href={`mailto:${customer.email}`} className="text-primary-600 hover:text-primary-500">
                    {customer.email}
                  </a>
                ) : '—'}
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Phone</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {customer.phone ? (
                  <a href={`tel:${customer.phone}`} className="text-primary-600 hover:text-primary-500">
                    {customer.phone}
                  </a>
                ) : '—'}
              </dd>
            </div>
            <div className="sm:col-span-2">
              <dt className="text-sm font-medium text-gray-500">Address</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {customer.address || customer.city || customer.state || customer.zip ? (
                  <>
                    {customer.address && <div>{customer.address}</div>}
                    {(customer.city || customer.state || customer.zip) && (
                      <div>
                        {customer.city}{customer.city && customer.state ? ', ' : ''}{customer.state} {customer.zip}
                      </div>
                    )}
                  </>
                ) : '—'}
              </dd>
            </div>
            {customer.notes ? (
              <div className="sm:col-span-2">
                <dt className="text-sm font-medium text-gray-500">Notes</dt>
                <dd className="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{customer.notes}</dd>
              </div>
            ) : null}
          </dl>
        </Card>

        <Card>
          <h3 className="text-lg font-medium text-gray-900 mb-4">Status</h3>
          <div className="space-y-4">
            <div>
              <dt className="text-sm font-medium text-gray-500">Account Status</dt>
              <dd className="mt-1">
                <Badge variant={customer.active !== false ? 'success' : 'default'}>
                  {customer.active !== false ? 'Active' : 'Inactive'}
                </Badge>
              </dd>
            </div>
            {customer.created_at ? (
              <div>
                <dt className="text-sm font-medium text-gray-500">Created</dt>
                <dd className="mt-1 text-sm text-gray-900">
                  {new Date(customer.created_at).toLocaleDateString()}
                </dd>
              </div>
            ) : null}
          </div>
        </Card>
      </div>

      <Card>
        <div className="flex justify-between items-center mb-4">
          <h3 className="text-lg font-medium text-gray-900">Vehicles</h3>
          <Button size="sm" onClick={() => navigate(`/cp/vehicles/create?customer=${id}`)}>
            Add Vehicle
          </Button>
        </div>
        {vehicles.length === 0 ? (
          <p className="text-gray-500 text-sm py-4">No vehicles registered for this customer.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vehicle</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">VIN</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">License</th>
                  <th className="px-4 py-3"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {vehicles.map((vehicle) => (
                  <tr key={vehicle.id}>
                    <td className="px-4 py-3 text-sm">
                      <Link to={`/cp/vehicles/${vehicle.id}`} className="text-primary-600 hover:text-primary-500 font-medium">
                        {vehicle.year} {vehicle.make} {vehicle.model}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-500">{vehicle.vin || '—'}</td>
                    <td className="px-4 py-3 text-sm text-gray-500">{vehicle.license_plate || '—'}</td>
                    <td className="px-4 py-3 text-right">
                      <Button size="sm" variant="ghost" onClick={() => navigate(`/cp/vehicles/${vehicle.id}`)}>
                        View
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={deleteModal}
        title="Delete Customer"
        onClose={() => setDeleteModal(false)}
      >
        <p className="text-sm text-gray-600 mb-4">
          Are you sure you want to delete <strong>{customer.name}</strong>? This action cannot be undone.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteModal(false)}>
            Cancel
          </Button>
          <Button variant="danger" loading={deleting} onClick={handleDelete}>
            Delete
          </Button>
        </div>
      </Modal>
    </div>
  )
}
