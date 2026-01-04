import { useEffect, useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'

const formatPosition = (position) => {
  if (!position) return 'Awaiting location update'
  const lat = position.lat ?? position.latitude
  const lng = position.lng ?? position.longitude
  if (lat == null || lng == null) return 'Awaiting location update'
  return `${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)}`
}

const formatTimestamp = (value) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleString()
}

const TrackingView = () => {
  const { token } = useParams()
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [isLoading, setIsLoading] = useState(true)

  const fetchTracking = async () => {
    try {
      setError(null)
      const response = await fetch(`/track/${token}`)
      if (!response.ok) {
        const payload = await response.json().catch(() => null)
        throw new Error(payload?.error || 'Unable to load tracking details.')
      }
      const payload = await response.json()
      setData(payload)
      setIsLoading(false)
    } catch (err) {
      setError(err.message || 'Unable to load tracking details.')
      setIsLoading(false)
    }
  }

  useEffect(() => {
    if (!token) return
    fetchTracking()
    const interval = setInterval(fetchTracking, 15000)
    return () => clearInterval(interval)
  }, [token])

  const customerName = useMemo(() => {
    if (!data?.customer) return 'Customer'
    return [data.customer.first_name, data.customer.last_name].filter(Boolean).join(' ') || 'Customer'
  }, [data])

  const vehicleLabel = useMemo(() => {
    if (!data?.vehicle) return 'Vehicle details pending'
    return [data.vehicle.year, data.vehicle.make, data.vehicle.model].filter(Boolean).join(' ') || 'Vehicle details pending'
  }, [data])

  const positionLabel = formatPosition(data?.tracking?.last_position)

  if (isLoading) {
    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center px-6 py-20">
        <div className="max-w-lg w-full bg-white shadow-sm rounded-2xl border border-slate-200 p-8 text-center">
          <div className="text-sm uppercase tracking-widest text-slate-400">Tracking</div>
          <h1 className="mt-2 text-2xl font-semibold text-slate-900">Loading your live update...</h1>
        </div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center px-6 py-20">
        <div className="max-w-lg w-full bg-white shadow-sm rounded-2xl border border-slate-200 p-8 text-center">
          <div className="text-sm uppercase tracking-widest text-rose-400">Tracking unavailable</div>
          <h1 className="mt-2 text-2xl font-semibold text-slate-900">We couldn&apos;t load the tracking link.</h1>
          <p className="mt-3 text-sm text-slate-600">{error}</p>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-slate-50 px-6 py-10">
      <div className="mx-auto max-w-5xl">
        <div className="mb-8">
          <p className="text-sm uppercase tracking-widest text-indigo-500">Live tracking</p>
          <h1 className="mt-2 text-3xl font-semibold text-slate-900">{customerName}, your technician is en route</h1>
          <p className="mt-2 text-sm text-slate-600">Workorder #{data?.workorder?.number}</p>
        </div>

        <div className="grid gap-6 lg:grid-cols-[2fr,1fr]">
          <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold text-slate-900">Live map</h2>
              <span className="text-xs font-medium uppercase tracking-wide text-slate-400">Auto-refreshes every 15s</span>
            </div>
            <div className="h-80 rounded-xl border border-dashed border-slate-200 bg-gradient-to-br from-indigo-50 via-white to-slate-50 flex items-center justify-center">
              <div className="text-center">
                <div className="text-sm font-semibold text-slate-700">Current position</div>
                <div className="mt-1 text-xs text-slate-500">{positionLabel}</div>
                <div className="mt-3 text-xs text-slate-400">Map provider integration placeholder</div>
              </div>
            </div>
            <div className="mt-4 grid gap-3 md:grid-cols-3 text-sm text-slate-600">
              <div>
                <div className="text-xs uppercase tracking-wide text-slate-400">Status</div>
                <div className="mt-1 font-medium text-slate-900 capitalize">{data?.job?.status?.replace('_', ' ')}</div>
              </div>
              <div>
                <div className="text-xs uppercase tracking-wide text-slate-400">ETA</div>
                <div className="mt-1 font-medium text-slate-900">{data?.tracking?.eta || 'Updating'}</div>
              </div>
              <div>
                <div className="text-xs uppercase tracking-wide text-slate-400">Last update</div>
                <div className="mt-1 font-medium text-slate-900">{formatTimestamp(data?.tracking?.updated_at)}</div>
              </div>
            </div>
          </div>

          <div className="space-y-6">
            <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
              <h2 className="text-lg font-semibold text-slate-900">Service details</h2>
              <dl className="mt-4 space-y-3 text-sm text-slate-600">
                <div>
                  <dt className="text-xs uppercase tracking-wide text-slate-400">Job</dt>
                  <dd className="mt-1 font-medium text-slate-900">{data?.job?.title || 'Service in progress'}</dd>
                </div>
                <div>
                  <dt className="text-xs uppercase tracking-wide text-slate-400">Vehicle</dt>
                  <dd className="mt-1 font-medium text-slate-900">{vehicleLabel}</dd>
                </div>
                <div>
                  <dt className="text-xs uppercase tracking-wide text-slate-400">Contact</dt>
                  <dd className="mt-1 font-medium text-slate-900">{data?.customer?.phone || data?.customer?.email || 'On file'}</dd>
                </div>
              </dl>
            </div>

            <div className="bg-indigo-50 border border-indigo-100 rounded-2xl p-6 text-sm text-slate-700">
              <h3 className="text-sm font-semibold text-indigo-600">Need help?</h3>
              <p className="mt-2">Reply to your dispatch text or call the shop if you need to adjust your appointment.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

export default TrackingView
