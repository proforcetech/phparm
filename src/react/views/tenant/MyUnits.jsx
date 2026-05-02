import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import { tenantPortalService } from '../../../services/propertyManagement.service'
import { useToast } from '../../stores/toast'

const formatBillingResponsibility = (value) => {
  if (!value) return null
  if (value === 'tenant') return { label: 'You', variant: 'info' }
  if (value === 'landlord') return { label: 'Landlord', variant: 'success' }
  if (value === 'split') return { label: 'Split (per category)', variant: 'warning' }
  return { label: value, variant: 'default' }
}

export default function MyUnits() {
  const [loading, setLoading] = useState(true)
  const [tenant, setTenant] = useState(null)
  const [units, setUnits] = useState([])
  const [error, setError] = useState(null)
  const { error: toastError } = useToast()

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    tenantPortalService.me()
      .then((data) => {
        if (cancelled) return
        setTenant(data.tenant ?? null)
        setUnits(data.units ?? [])
      })
      .catch((err) => {
        if (cancelled) return
        const message = err.response?.data?.error || 'Failed to load tenant profile'
        setError(message)
        toastError(message)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => { cancelled = true }
  }, [toastError])

  if (loading) return <Loading />

  if (error) {
    return (
      <Alert variant="error">
        {error}. If you believe you should have tenant access, contact the
        property manager.
      </Alert>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">My Units</h1>
          {tenant ? (
            <p className="mt-1 text-sm text-gray-500">
              Signed in as {tenant.display_name} ({tenant.entity_type}).
            </p>
          ) : null}
        </div>
        <Link to="/tenant/requests/new">
          <Button>Submit Maintenance Request</Button>
        </Link>
      </div>

      {units.length === 0 ? (
        <Card>
          <p className="text-sm text-gray-600">
            You don't have any active leases on file. If you've recently
            moved in, ask your property manager to record the lease.
          </p>
        </Card>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {units.map((unit) => {
            const billing = formatBillingResponsibility(unit.lease?.billing_responsibility)
            return (
              <Card key={unit.id}>
                <div className="flex items-start justify-between mb-3">
                  <div>
                    <h3 className="text-lg font-medium text-gray-900">
                      {unit.code || `Unit #${unit.id}`}
                    </h3>
                    {unit.name ? (
                      <p className="text-sm text-gray-500">{unit.name}</p>
                    ) : null}
                  </div>
                  <Badge variant={unit.status === 'occupied' ? 'success' : 'default'}>
                    {unit.status || 'unknown'}
                  </Badge>
                </div>
                <dl className="grid grid-cols-2 gap-3 text-sm">
                  {unit.unit_type ? (
                    <div>
                      <dt className="text-gray-500">Type</dt>
                      <dd className="text-gray-900">{unit.unit_type}</dd>
                    </div>
                  ) : null}
                  {unit.floor ? (
                    <div>
                      <dt className="text-gray-500">Floor</dt>
                      <dd className="text-gray-900">{unit.floor}</dd>
                    </div>
                  ) : null}
                  {unit.lease?.start_date ? (
                    <div>
                      <dt className="text-gray-500">Lease Start</dt>
                      <dd className="text-gray-900">{unit.lease.start_date}</dd>
                    </div>
                  ) : null}
                  {unit.lease?.end_date ? (
                    <div>
                      <dt className="text-gray-500">Lease End</dt>
                      <dd className="text-gray-900">{unit.lease.end_date}</dd>
                    </div>
                  ) : null}
                </dl>
                {billing ? (
                  <div className="mt-4 pt-3 border-t border-gray-200">
                    <span className="text-xs text-gray-500 mr-2">Maintenance billed to:</span>
                    <Badge variant={billing.variant}>{billing.label}</Badge>
                  </div>
                ) : null}
              </Card>
            )
          })}
        </div>
      )}
    </div>
  )
}
