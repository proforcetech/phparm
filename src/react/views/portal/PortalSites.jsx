import { useCallback, useEffect, useState } from 'react'

import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import { portalService } from '../../../services/portal/portal.service'

const formatAddress = (s) => {
  const parts = [s.street, [s.city, s.state].filter(Boolean).join(', '), s.postal_code]
    .filter((p) => p && String(p).trim() !== '')
  return parts.length ? parts.join(' · ') : null
}

const statusColor = (status) => {
  switch (status) {
    case 'active': return 'bg-green-100 text-green-800'
    case 'inactive': return 'bg-gray-100 text-gray-700'
    case 'retired': return 'bg-red-100 text-red-800'
    default: return 'bg-gray-100 text-gray-700'
  }
}

function SiteAssets({ siteId }) {
  const [open, setOpen] = useState(false)
  const [loaded, setLoaded] = useState(false)
  const [loading, setLoading] = useState(false)
  const [assets, setAssets] = useState([])
  const [total, setTotal] = useState(0)
  const [error, setError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const result = await portalService.listAssetsAtSite(siteId, { limit: 100 })
      setAssets(Array.isArray(result?.data) ? result.data : (Array.isArray(result) ? result : []))
      setTotal(Number(result?.total ?? (Array.isArray(result?.data) ? result.data.length : 0)))
      setLoaded(true)
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load assets.')
    } finally {
      setLoading(false)
    }
  }, [siteId])

  const toggle = () => {
    if (!open && !loaded) load()
    setOpen((v) => !v)
  }

  return (
    <div className="mt-3">
      <Button variant="ghost" size="sm" onClick={toggle}>
        {open ? 'Hide assets' : `Show assets${loaded ? ` (${total})` : ''}`}
      </Button>
      {open && (
        <div className="mt-3">
          {loading ? (
            <Loading text="Loading assets…" />
          ) : error ? (
            <Alert variant="error" closable={false}>{error}</Alert>
          ) : assets.length === 0 ? (
            <p className="text-sm text-gray-500">No assets at this site.</p>
          ) : (
            <div className="overflow-x-auto border rounded">
              <table className="min-w-full divide-y divide-gray-200 text-sm">
                <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                  <tr>
                    <th className="px-3 py-2 text-left">Name</th>
                    <th className="px-3 py-2 text-left">Code</th>
                    <th className="px-3 py-2 text-left">Status</th>
                    <th className="px-3 py-2 text-left">Location</th>
                    <th className="px-3 py-2 text-left">Manufacturer</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 bg-white">
                  {assets.map((a) => (
                    <tr key={a.id}>
                      <td className="px-3 py-2 text-gray-900">{a.name || `Asset #${a.id}`}</td>
                      <td className="px-3 py-2 text-gray-600">{a.code || '—'}</td>
                      <td className="px-3 py-2">
                        <span className={`px-2 py-0.5 rounded-full text-xs ${statusColor(a.status)}`}>
                          {a.status || '—'}
                        </span>
                      </td>
                      <td className="px-3 py-2 text-gray-600">
                        {[a.building, a.floor, a.room, a.rack].filter(Boolean).join(' / ') || '—'}
                      </td>
                      <td className="px-3 py-2 text-gray-600">
                        {a.manufacturer || '—'}
                        {a.model_number ? ` · ${a.model_number}` : ''}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

export default function PortalSites() {
  const [sites, setSites] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    portalService.listSites()
      .then((list) => { if (!cancelled) setSites(Array.isArray(list) ? list : []) })
      .catch((err) => { if (!cancelled) setError(err.response?.data?.message || 'Unable to load sites.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [])

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold">Sites</h1>
        <p className="text-sm text-gray-600 mt-1">
          Locations and installed assets visible to your portal account.
        </p>
      </header>

      {error && <Alert variant="error" closable={false}>{error}</Alert>}

      {loading ? (
        <Card>
          <div className="py-10 flex justify-center">
            <Loading text="Loading sites…" />
          </div>
        </Card>
      ) : sites.length === 0 ? (
        <Card>
          <p className="text-sm text-gray-600">
            No sites are visible to your account yet. If this looks wrong, contact support.
          </p>
        </Card>
      ) : (
        <div className="space-y-3">
          {sites.map((s) => {
            const address = formatAddress(s)
            return (
              <Card key={s.id}>
                <div className="flex items-start justify-between gap-4 flex-wrap">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <h3 className="text-base font-semibold">{s.name}</h3>
                      {s.code && <span className="text-xs text-gray-500">[{s.code}]</span>}
                      {s.is_primary && (
                        <span className="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-800">
                          Primary
                        </span>
                      )}
                      {s.status && (
                        <span className={`text-xs px-2 py-0.5 rounded-full ${statusColor(s.status)}`}>
                          {s.status}
                        </span>
                      )}
                    </div>
                    {address && <p className="mt-1 text-sm text-gray-600">{address}</p>}
                    {s.phone && (
                      <p className="text-sm text-gray-500 mt-0.5">
                        <a href={`tel:${s.phone}`} className="hover:underline">{s.phone}</a>
                      </p>
                    )}
                  </div>
                </div>
                <SiteAssets siteId={s.id} />
              </Card>
            )
          })}
        </div>
      )}
    </div>
  )
}
