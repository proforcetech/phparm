import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import api from '../../../services/api'
import assetImports from '../../../services/asset-imports.service'

/**
 * Phase 18 / S12 — bulk asset CSV import wizard.
 *
 * Three-step flow shown inline so the operator can iterate the mapping
 * without navigating away:
 *
 *   1. Upload CSV (defaults: site, division, asset_type)
 *   2. Map columns → SiteAsset fields, run dry-run validate
 *   3. Review per-row errors → apply (INSERTs into site_assets)
 *
 * The header row carries status across reloads, so a closed tab can resume
 * an import job from the list view at the top.
 */

const TARGET_FIELDS = [
  { value: '', label: '— skip —' },
  { value: 'name', label: 'name (required)' },
  { value: 'code', label: 'code' },
  { value: 'status', label: 'status' },
  { value: 'install_date', label: 'install_date' },
  { value: 'notes', label: 'notes' },
  { value: 'site_id', label: 'site_id' },
  { value: 'site_code', label: 'site_code' },
  { value: 'site_name', label: 'site_name' },
  { value: 'division_id', label: 'division_id' },
  { value: 'asset_type_id', label: 'asset_type_id' },
  { value: 'asset_type_code', label: 'asset_type_code' },
  { value: 'asset_type_name', label: 'asset_type_name' },
  { value: 'parent_asset_code', label: 'parent_asset_code' },
  { value: 'manufacturer', label: 'manufacturer' },
  { value: 'model_number', label: 'model_number' },
  { value: 'serial_number', label: 'serial_number' },
  { value: 'vendor', label: 'vendor' },
  { value: 'warranty_start', label: 'warranty_start' },
  { value: 'warranty_end', label: 'warranty_end' },
  { value: 'purchase_cents', label: 'purchase_cents' },
  { value: 'building', label: 'building' },
  { value: 'floor', label: 'floor' },
  { value: 'room', label: 'room' },
  { value: 'rack', label: 'rack' },
  { value: 'rack_position', label: 'rack_position' },
  { value: 'ip_address', label: 'ip_address' },
  { value: 'mac_address', label: 'mac_address' },
  { value: 'subnet', label: 'subnet' },
  { value: 'vlan', label: 'vlan' },
  { value: 'condition_score', label: 'condition_score' },
  { value: 'expected_life_years', label: 'expected_life_years' },
  { value: 'replacement_estimate_cents', label: 'replacement_estimate_cents' },
]

const STATUS_VARIANT = {
  pending: 'warning',
  validated: 'info',
  applying: 'primary',
  applied: 'success',
  failed: 'danger',
  cancelled: 'secondary',
}

const ROW_STATUS_VARIANT = {
  pending: 'secondary',
  validated: 'info',
  invalid: 'danger',
  created: 'success',
  skipped: 'secondary',
}

export default function AssetImport() {
  const [history, setHistory] = useState([])
  const [historyLoading, setHistoryLoading] = useState(true)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const [file, setFile] = useState(null)
  const [defaultSiteId, setDefaultSiteId] = useState('')
  const [defaultDivisionId, setDefaultDivisionId] = useState('')
  const [defaultAssetTypeId, setDefaultAssetTypeId] = useState('')

  const [sites, setSites] = useState([])
  const [assetTypes, setAssetTypes] = useState([])

  const [activeId, setActiveId] = useState(null)
  const [active, setActive] = useState(null)
  const [columns, setColumns] = useState([]) // csv columns from first row
  const [mapping, setMapping] = useState({}) // { csv_col: target_field }
  const [rows, setRows] = useState([])
  const [rowsLoading, setRowsLoading] = useState(false)
  const [rowFilter, setRowFilter] = useState('')

  // -- bootstrap: fetch reference catalogs + history --

  useEffect(() => {
    api
      .get('/sites', { params: { status: 'active', limit: 500 } })
      .then((r) => setSites(r.data?.data ?? []))
      .catch(() => { /* not fatal */ })
    api
      .get('/asset-types')
      .then((r) => setAssetTypes(r.data?.data ?? []))
      .catch(() => { /* not fatal */ })
  }, [])

  const loadHistory = useCallback(() => {
    setHistoryLoading(true)
    assetImports
      .list(20)
      .then((res) => setHistory(res?.data ?? []))
      .catch((e) => setError(extractError(e, 'Failed to load import history')))
      .finally(() => setHistoryLoading(false))
  }, [])

  useEffect(() => { loadHistory() }, [loadHistory])

  // -- detail loading when an import is selected --

  const loadDetail = useCallback(async (id) => {
    if (!id) return
    setRowsLoading(true)
    try {
      const detail = await assetImports.get(id)
      const header = detail?.data?.header
      if (!header) throw new Error('Import not found')
      setActive(header)
      setMapping(header.mapping || {})
      setDefaultSiteId(header.default_site_id ? String(header.default_site_id) : '')
      setDefaultDivisionId(header.default_division_id ? String(header.default_division_id) : '')
      setDefaultAssetTypeId(header.default_asset_type_id ? String(header.default_asset_type_id) : '')
      const rowList = await assetImports.listRows(id, { limit: 1000 })
      const fetched = rowList?.data ?? []
      setRows(fetched)
      // CSV columns come from the first raw_data dict — every row has the same shape
      if (fetched.length > 0 && fetched[0].raw_data) {
        setColumns(Object.keys(fetched[0].raw_data))
      } else {
        setColumns([])
      }
    } catch (e) {
      setError(extractError(e, 'Failed to load import detail'))
    } finally {
      setRowsLoading(false)
    }
  }, [])

  useEffect(() => {
    if (activeId) loadDetail(activeId)
    else { setActive(null); setRows([]); setColumns([]); setMapping({}) }
  }, [activeId, loadDetail])

  // -- handlers --

  const submitUpload = async () => {
    if (!file) {
      setError('Choose a CSV file before uploading.')
      return
    }
    setBusy(true)
    setError('')
    try {
      const res = await assetImports.upload(file, {
        default_site_id: defaultSiteId || null,
        default_division_id: defaultDivisionId || null,
        default_asset_type_id: defaultAssetTypeId || null,
      })
      const newId = res?.data?.id
      if (newId) {
        setActiveId(newId)
        setFile(null)
        loadHistory()
      }
    } catch (e) {
      setError(extractError(e, 'Upload failed'))
    } finally {
      setBusy(false)
    }
  }

  const saveMapping = async () => {
    if (!activeId) return
    setBusy(true)
    setError('')
    try {
      await assetImports.updateMapping(activeId, {
        mapping,
        default_site_id: defaultSiteId || null,
        default_division_id: defaultDivisionId || null,
        default_asset_type_id: defaultAssetTypeId || null,
      })
      await loadDetail(activeId)
    } catch (e) {
      setError(extractError(e, 'Save mapping failed'))
    } finally {
      setBusy(false)
    }
  }

  const runValidate = async () => {
    if (!activeId) return
    setBusy(true)
    setError('')
    try {
      await assetImports.validate(activeId)
      await loadDetail(activeId)
      loadHistory()
    } catch (e) {
      setError(extractError(e, 'Validation failed'))
    } finally {
      setBusy(false)
    }
  }

  const runApply = async () => {
    if (!activeId) return
    if (!confirm(`Apply ${active?.valid_rows ?? 0} validated rows? This INSERTS into site_assets and is not reversible.`)) return
    setBusy(true)
    setError('')
    try {
      await assetImports.apply(activeId)
      await loadDetail(activeId)
      loadHistory()
    } catch (e) {
      setError(extractError(e, 'Apply failed'))
    } finally {
      setBusy(false)
    }
  }

  const runCancel = async () => {
    if (!activeId) return
    if (!confirm('Cancel this import? Already-applied rows will remain in site_assets.')) return
    setBusy(true)
    setError('')
    try {
      await assetImports.cancel(activeId)
      await loadDetail(activeId)
      loadHistory()
    } catch (e) {
      setError(extractError(e, 'Cancel failed'))
    } finally {
      setBusy(false)
    }
  }

  const filteredRows = useMemo(() => {
    if (!rowFilter) return rows
    return rows.filter((r) => r.status === rowFilter)
  }, [rows, rowFilter])

  // -- render --

  return (
    <div className="p-4 space-y-4">
      <header>
        <h1 className="text-xl font-semibold">Bulk asset import</h1>
        <p className="text-sm text-gray-500">
          Upload a CSV, map columns to asset fields, dry-run, then apply.
          Required field: <code>name</code>. Site comes from a per-row column or a default below.
        </p>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      {/* Step 1 — upload */}
      {!activeId && (
        <Card>
          <div className="p-4 space-y-3">
            <h2 className="font-semibold">1. Upload CSV</h2>
            <input
              type="file"
              accept=".csv,text/csv"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              className="block text-sm"
            />
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <Select
                label="Default site (optional)"
                value={defaultSiteId}
                onChange={(e) => setDefaultSiteId(e?.target?.value ?? e)}
                options={[{ value: '', label: '— none —' }, ...sites.map((s) => ({ value: String(s.id), label: s.name }))]}
              />
              <Input
                label="Default division id (optional)"
                value={defaultDivisionId}
                onChange={(e) => setDefaultDivisionId(e.target.value)}
              />
              <Select
                label="Default asset type (optional)"
                value={defaultAssetTypeId}
                onChange={(e) => setDefaultAssetTypeId(e?.target?.value ?? e)}
                options={[{ value: '', label: '— none —' }, ...assetTypes.map((t) => ({ value: String(t.id), label: t.name }))]}
              />
            </div>
            <Button onClick={submitUpload} disabled={!file || busy}>
              {busy ? 'Uploading…' : 'Upload'}
            </Button>
          </div>
        </Card>
      )}

      {/* Active import workspace */}
      {activeId && active && (
        <>
          <Card>
            <div className="p-4 flex items-center justify-between flex-wrap gap-3">
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <span className="font-semibold">Import #{active.id}</span>
                  <Badge variant={STATUS_VARIANT[active.status] || 'secondary'}>{active.status}</Badge>
                  {active.original_filename && (
                    <span className="text-sm text-gray-500">{active.original_filename}</span>
                  )}
                </div>
                <div className="text-xs text-gray-500">
                  Total {active.total_rows} · valid {active.valid_rows} · errors {active.error_rows} · created {active.created_rows}
                </div>
              </div>
              <div className="flex gap-2">
                <Button variant="secondary" onClick={() => setActiveId(null)}>Back to list</Button>
                {active.status !== 'applied' && active.status !== 'cancelled' && (
                  <Button variant="danger" onClick={runCancel} disabled={busy}>Cancel import</Button>
                )}
              </div>
            </div>
          </Card>

          {/* Step 2 — mapping */}
          <Card>
            <div className="p-4 space-y-3">
              <h2 className="font-semibold">2. Map columns → asset fields</h2>
              {columns.length === 0 ? (
                <p className="text-sm text-gray-500">No rows parsed from this CSV.</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                      <tr>
                        <th className="text-left p-2">CSV column</th>
                        <th className="text-left p-2">Sample value</th>
                        <th className="text-left p-2">Maps to field</th>
                      </tr>
                    </thead>
                    <tbody>
                      {columns.map((col) => (
                        <tr key={col} className="border-t">
                          <td className="p-2 font-mono">{col}</td>
                          <td className="p-2 text-gray-600">{String(rows[0]?.raw_data?.[col] ?? '')}</td>
                          <td className="p-2">
                            <Select
                              value={mapping[col] || ''}
                              onChange={(e) => setMapping({ ...mapping, [col]: e?.target?.value ?? e })}
                              options={TARGET_FIELDS}
                            />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <Select
                  label="Default site"
                  value={defaultSiteId}
                  onChange={(e) => setDefaultSiteId(e?.target?.value ?? e)}
                  options={[{ value: '', label: '— none —' }, ...sites.map((s) => ({ value: String(s.id), label: s.name }))]}
                />
                <Input
                  label="Default division id"
                  value={defaultDivisionId}
                  onChange={(e) => setDefaultDivisionId(e.target.value)}
                />
                <Select
                  label="Default asset type"
                  value={defaultAssetTypeId}
                  onChange={(e) => setDefaultAssetTypeId(e?.target?.value ?? e)}
                  options={[{ value: '', label: '— none —' }, ...assetTypes.map((t) => ({ value: String(t.id), label: t.name }))]}
                />
              </div>
              <div className="flex gap-2">
                <Button variant="secondary" onClick={saveMapping} disabled={busy || active.status === 'applied'}>
                  Save mapping
                </Button>
                <Button onClick={runValidate} disabled={busy || active.status === 'applied'}>
                  {busy ? 'Working…' : 'Save & dry-run validate'}
                </Button>
                {active.status === 'validated' && active.valid_rows > 0 && (
                  <Button variant="success" onClick={runApply} disabled={busy}>
                    Apply {active.valid_rows} valid rows
                  </Button>
                )}
              </div>
            </div>
          </Card>

          {/* Step 3 — row results */}
          <Card>
            <div className="p-4 space-y-3">
              <div className="flex items-center justify-between">
                <h2 className="font-semibold">3. Rows</h2>
                <Select
                  value={rowFilter}
                  onChange={(e) => setRowFilter(e?.target?.value ?? e)}
                  options={[
                    { value: '', label: 'All statuses' },
                    { value: 'pending', label: 'Pending' },
                    { value: 'validated', label: 'Validated' },
                    { value: 'invalid', label: 'Invalid' },
                    { value: 'created', label: 'Created' },
                  ]}
                />
              </div>
              {rowsLoading ? (
                <div className="p-6 text-center"><Loading /></div>
              ) : filteredRows.length === 0 ? (
                <div className="p-6 text-center text-gray-500">No rows.</div>
              ) : (
                <div className="overflow-x-auto max-h-[60vh]">
                  <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-xs uppercase text-gray-500 sticky top-0">
                      <tr>
                        <th className="text-left p-2">#</th>
                        <th className="text-left p-2">Status</th>
                        <th className="text-left p-2">Name / preview</th>
                        <th className="text-left p-2">Error</th>
                        <th className="text-left p-2">Created asset id</th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredRows.map((r) => (
                        <tr key={r.id} className="border-t">
                          <td className="p-2">{r.row_number}</td>
                          <td className="p-2">
                            <Badge variant={ROW_STATUS_VARIANT[r.status] || 'secondary'}>{r.status}</Badge>
                          </td>
                          <td className="p-2 font-mono text-xs">
                            {r.parsed_data?.name || r.raw_data?.name || JSON.stringify(r.raw_data).slice(0, 80)}
                          </td>
                          <td className="p-2 text-xs text-red-600">{r.error_message || ''}</td>
                          <td className="p-2 text-xs">{r.created_asset_id || ''}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </Card>
        </>
      )}

      {/* History */}
      <Card>
        <div className="p-4 space-y-3">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold">Recent imports</h2>
            <Button variant="secondary" onClick={loadHistory}>Refresh</Button>
          </div>
          {historyLoading ? (
            <div className="p-6 text-center"><Loading /></div>
          ) : history.length === 0 ? (
            <div className="p-6 text-center text-gray-500">No imports yet.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                  <tr>
                    <th className="text-left p-2">Id</th>
                    <th className="text-left p-2">Status</th>
                    <th className="text-left p-2">File</th>
                    <th className="text-left p-2">Started</th>
                    <th className="text-left p-2">Total / valid / errors / created</th>
                    <th className="text-right p-2"> </th>
                  </tr>
                </thead>
                <tbody>
                  {history.map((h) => (
                    <tr key={h.id} className="border-t">
                      <td className="p-2">#{h.id}</td>
                      <td className="p-2">
                        <Badge variant={STATUS_VARIANT[h.status] || 'secondary'}>{h.status}</Badge>
                      </td>
                      <td className="p-2 truncate max-w-xs">{h.original_filename || '—'}</td>
                      <td className="p-2 text-xs">{h.started_at}</td>
                      <td className="p-2 text-xs">
                        {h.total_rows} / {h.valid_rows} / {h.error_rows} / {h.created_rows}
                      </td>
                      <td className="p-2 text-right">
                        <Button variant="secondary" onClick={() => setActiveId(h.id)}>Open</Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </Card>
    </div>
  )
}

function extractError(e, fallback) {
  return e?.response?.data?.message || e?.response?.data?.error || e?.message || fallback
}
