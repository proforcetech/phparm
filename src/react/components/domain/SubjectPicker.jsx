import { useCallback, useEffect, useMemo, useState } from 'react'

import Autocomplete from '../ui/Autocomplete'
import Select from '../ui/Select'
import assetsService from '../../../services/assets.service'
import { getCustomerVehicles } from '../../../services/customer-vehicle.service'
import { useAuthStore } from '../../stores/auth'

// Subject FK rules used to be hardcoded here as a slug→rule map. Migration
// 176 moved them onto each service_lines row, and /api/auth/me now ships
// subject_column / subject_required / subject_label alongside the trimmed
// service-line list — so this component just reads the active line.
function getRuleForLine(line) {
  if (!line) {
    return { column: null, required: false, label: null }
  }
  const column = line.subject_column ?? null
  const required = column ? Boolean(line.subject_required) : false
  const label = column ? (line.subject_label || (column === 'vehicle_id' ? 'Vehicle' : 'Asset')) : null
  return { column, required, label }
}

function formatVehicleLabel(vehicle) {
  const parts = [vehicle.year, vehicle.make, vehicle.model].filter(Boolean)
  const base = parts.join(' ') || `Vehicle #${vehicle.id}`
  return vehicle.license_plate ? `${base} (${vehicle.license_plate})` : base
}

function formatAssetLabel(asset) {
  const code = asset.code ? `[${asset.code}] ` : ''
  return `${code}${asset.name || `Asset #${asset.id}`}`
}

function formatAssetSubtext(asset) {
  const bits = []
  if (asset.serial_number) bits.push(`SN: ${asset.serial_number}`)
  if (asset.building || asset.floor || asset.room) {
    bits.push([asset.building, asset.floor, asset.room].filter(Boolean).join(' / '))
  }
  return bits.join(' • ')
}

/**
 * Renders the right "subject" control for the active service line:
 *   - vehicle dropdown (customer-scoped) for auto_repair / fleet_management
 *   - asset autocomplete (cross-site search) for property / equipment / IT / etc.
 *   - nothing for commercial_cleaning (route-bound, not asset-bound)
 *
 * Also shows a service-line selector when the user has access to more than one
 * line so they can switch the document's vertical without leaving the form.
 *
 * Controlled component — parent owns serviceLineId / vehicleId / siteAssetId.
 * Selecting a vehicle clears site_asset_id and vice versa, since SubjectResolver
 * only honors the column the line cares about.
 */
export default function SubjectPicker({
  serviceLineId,
  vehicleId,
  siteAssetId,
  customerId,
  onChange,
  disabled = false,
  required: requiredProp,
}) {
  const { serviceLines, currentServiceLineId } = useAuthStore()

  const effectiveServiceLineId = useMemo(() => {
    if (serviceLineId !== null && serviceLineId !== undefined) return serviceLineId
    return currentServiceLineId ?? null
  }, [serviceLineId, currentServiceLineId])

  const activeLine = useMemo(
    () => serviceLines.find((l) => l.id === effectiveServiceLineId) ?? null,
    [serviceLines, effectiveServiceLineId]
  )
  const rule = getRuleForLine(activeLine)
  const isRequired = requiredProp ?? rule.required

  const [customerVehicles, setCustomerVehicles] = useState([])
  const [vehiclesLoading, setVehiclesLoading] = useState(false)

  // Vehicle path: load this customer's vehicles when the line wants vehicle_id.
  // Same data fetch as the existing inline pickers so we don't change behavior.
  useEffect(() => {
    if (rule.column !== 'vehicle_id' || !customerId) {
      setCustomerVehicles([])
      return
    }
    let cancelled = false
    setVehiclesLoading(true)
    getCustomerVehicles(customerId)
      .then((data) => {
        if (cancelled) return
        const list = Array.isArray(data) ? data : (data?.data ?? [])
        setCustomerVehicles(list)
      })
      .catch(() => {
        if (!cancelled) setCustomerVehicles([])
      })
      .finally(() => {
        if (!cancelled) setVehiclesLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [rule.column, customerId])

  const emit = useCallback(
    (next) => {
      onChange?.({
        service_line_id: effectiveServiceLineId,
        vehicle_id: null,
        site_asset_id: null,
        ...next,
      })
    },
    [effectiveServiceLineId, onChange]
  )

  const handleServiceLineChange = (event) => {
    const nextId = event.target.value ? Number(event.target.value) : null
    // Switching lines invalidates the previously chosen subject — the new line
    // may not even use the same FK column. Caller handles re-prompting.
    onChange?.({
      service_line_id: nextId,
      vehicle_id: null,
      site_asset_id: null,
    })
  }

  const handleVehicleChange = (event) => {
    const nextId = event.target.value ? Number(event.target.value) : null
    emit({ vehicle_id: nextId })
  }

  const searchAssets = useCallback(async (query) => {
    const params = { query, limit: 25 }
    if (effectiveServiceLineId) {
      params.service_line_id = effectiveServiceLineId
    }
    const res = await assetsService.search(params)
    return res?.data ?? []
  }, [effectiveServiceLineId])

  const handleAssetSelect = (asset) => {
    emit({ site_asset_id: asset?.id ?? null })
  }

  const handleAssetClear = (value) => {
    if (value === null) {
      emit({ site_asset_id: null })
    }
  }

  const hasServiceLines = Array.isArray(serviceLines) && serviceLines.length > 0

  return (
    <div className="space-y-3">
      {hasServiceLines ? (
        <div>
          <label className="block text-sm font-medium text-gray-700">Service Line</label>
          <Select
            value={effectiveServiceLineId ?? ''}
            onChange={handleServiceLineChange}
            disabled={disabled || serviceLines.length <= 1}
            options={serviceLines.map((line) => ({
              value: line.id,
              label: `${line.icon ? line.icon + ' ' : ''}${line.name}`,
            }))}
          />
        </div>
      ) : null}

      {rule.column === 'vehicle_id' ? (
        <div>
          <label className="block text-sm font-medium text-gray-700">
            {rule.label}
            {isRequired ? ' *' : ''}
          </label>
          <Select
            value={vehicleId ?? ''}
            onChange={handleVehicleChange}
            disabled={disabled || !customerId || vehiclesLoading}
            required={isRequired}
            placeholder={
              vehiclesLoading
                ? 'Loading vehicles...'
                : customerId
                  ? 'Select a vehicle'
                  : 'Select a customer first'
            }
            options={customerVehicles.map((v) => ({
              value: v.id,
              label: formatVehicleLabel(v),
            }))}
          />
          {customerId && !vehiclesLoading && customerVehicles.length === 0 ? (
            <p className="mt-1 text-xs text-amber-600">No vehicles found for this customer.</p>
          ) : null}
        </div>
      ) : null}

      {rule.column === 'site_asset_id' ? (
        <div>
          <label className="block text-sm font-medium text-gray-700">
            {rule.label}
            {isRequired ? ' *' : ''}
          </label>
          <Autocomplete
            modelValue={siteAssetId}
            placeholder="Search by name, code, or serial..."
            searchFn={searchAssets}
            itemValue={(item) => item.id}
            itemLabel={formatAssetLabel}
            itemSubtext={formatAssetSubtext}
            disabled={disabled}
            required={isRequired}
            onSelect={handleAssetSelect}
            onUpdateModelValue={handleAssetClear}
          />
        </div>
      ) : null}

      {rule.column === null && activeLine ? (
        <p className="text-xs text-gray-500">
          {activeLine.name} work isn't tied to a single asset — leave the subject blank.
        </p>
      ) : null}
    </div>
  )
}
