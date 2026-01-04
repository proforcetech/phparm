import { useMemo, useState } from 'react'
import { InformationCircleIcon } from '@heroicons/react/24/outline'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import dispatchService from '../../../services/dispatch.service'
import workorderService from '../../../services/workorder.service'
import { useToast } from '../../stores/toast'

const formatNumber = (value, digits = 2) => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return 'N/A'
  return Number(value).toFixed(digits)
}

export default function DispatchView() {
  const { success, error: toastError } = useToast()
  const [dispatchRequirementId, setDispatchRequirementId] = useState('')
  const [workorderId, setWorkorderId] = useState('')
  const [limit, setLimit] = useState('5')
  const [suggestions, setSuggestions] = useState([])
  const [requirement, setRequirement] = useState(null)
  const [loading, setLoading] = useState(false)
  const [assigningId, setAssigningId] = useState(null)
  const [loadError, setLoadError] = useState(null)

  const hasSuggestions = suggestions.length > 0

  const handleFetch = async () => {
    if (!dispatchRequirementId) {
      setLoadError('Dispatch requirement ID is required to fetch suggestions.')
      return
    }

    setLoading(true)
    setLoadError(null)
    try {
      const response = await dispatchService.getSuggestions({
        dispatch_requirement_id: dispatchRequirementId,
        limit: Number(limit) || 5,
      })
      setSuggestions(response.data?.data ?? [])
      setRequirement(response.data?.requirement ?? null)
    } catch (err) {
      setLoadError(err?.response?.data?.message || 'Unable to load dispatch suggestions.')
    } finally {
      setLoading(false)
    }
  }

  const handleAssign = async (suggestion) => {
    if (!workorderId) {
      toastError('Enter a workorder ID before assigning a driver.')
      return
    }

    setAssigningId(suggestion.driver_profile_id)
    try {
      await workorderService.assignTechnician(Number(workorderId), suggestion.driver_user_id, {
        source: 'dispatch_recommendation',
        driver_profile_id: suggestion.driver_profile_id,
        driver_user_id: suggestion.driver_user_id,
        distance_km: suggestion.distance_km,
        equipment: suggestion.equipment,
        remaining_shift_hours: suggestion.remaining_shift_hours,
        scores: suggestion.scores,
      })
      success('Driver assigned successfully.')
    } catch (err) {
      toastError(err?.response?.data?.message || 'Unable to assign driver.')
    } finally {
      setAssigningId(null)
    }
  }

  const requirementMeta = useMemo(() => {
    if (!requirement) return []
    return [
      { label: 'Required Equipment', value: requirement.required_equipment_class || 'Any' },
      { label: 'Required Capacity', value: requirement.required_capacity ?? 'N/A' },
      { label: 'Estimated Hours', value: requirement.estimated_duration_hours ?? 'N/A' },
      {
        label: 'Pickup Lat/Lng',
        value:
          requirement.pickup_latitude && requirement.pickup_longitude
            ? `${formatNumber(requirement.pickup_latitude, 4)}, ${formatNumber(
                requirement.pickup_longitude,
                4
              )}`
            : 'N/A',
      },
    ]
  }, [requirement])

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Dispatch Suggestions</h1>
          <p className="text-sm text-gray-500">
            Rank drivers by distance, equipment fit, and remaining shift hours.
          </p>
        </div>
      </div>

      <Card title="Dispatch Inputs">
        <div className="grid gap-4 md:grid-cols-3">
          <Input
            label="Dispatch Requirement ID"
            placeholder="e.g. 1024"
            modelValue={dispatchRequirementId}
            onUpdateModelValue={setDispatchRequirementId}
          />
          <Input
            label="Workorder ID for Assignment"
            placeholder="e.g. 420"
            modelValue={workorderId}
            onUpdateModelValue={setWorkorderId}
          />
          <Input
            label="Result Limit"
            placeholder="5"
            modelValue={limit}
            onUpdateModelValue={setLimit}
          />
        </div>
        <div className="mt-4 flex items-center gap-3">
          <Button onClick={handleFetch} disabled={loading}>
            {loading ? 'Loading...' : 'Get Suggestions'}
          </Button>
          {loading ? <Loading size="sm" /> : null}
        </div>
        {loadError ? <Alert variant="error" className="mt-4" message={loadError} /> : null}
      </Card>

      {requirement ? (
        <Card title="Dispatch Requirement Summary">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {requirementMeta.map((item) => (
              <div key={item.label} className="rounded-md border border-gray-200 p-3">
                <p className="text-xs uppercase text-gray-400">{item.label}</p>
                <p className="text-sm font-medium text-gray-900">{item.value}</p>
              </div>
            ))}
          </div>
        </Card>
      ) : null}

      <Card title="Suggested Drivers">
        {!hasSuggestions && !loading ? (
          <p className="text-sm text-gray-500">No suggestions yet. Fetch to view ranked drivers.</p>
        ) : null}
        <div className="space-y-4">
          {suggestions.map((suggestion) => {
            const rationale = [
              `Distance: ${suggestion.distance_km ? `${formatNumber(suggestion.distance_km)} km` : 'N/A'}`,
              `Equipment Fit: ${formatNumber(suggestion.scores?.equipment, 2)}`,
              `Remaining Shift Hours: ${formatNumber(suggestion.remaining_shift_hours, 2)}`,
            ].join(' • ')
            const overallScore = suggestion.scores?.overall ?? 0

            return (
              <div
                key={suggestion.driver_profile_id}
                className="flex flex-col gap-4 rounded-lg border border-gray-200 p-4 md:flex-row md:items-center md:justify-between"
              >
                <div className="space-y-2">
                  <div className="flex items-center gap-2">
                    <p className="text-lg font-semibold text-gray-900">{suggestion.driver_name}</p>
                    <Badge variant="info">Score {formatNumber(overallScore, 2)}</Badge>
                  </div>
                  <div className="flex flex-wrap items-center gap-3 text-sm text-gray-600">
                    <span>Equipment: {suggestion.equipment?.equipment_class || 'Unspecified'}</span>
                    <span>Capacity: {suggestion.equipment?.capacity ?? 'N/A'}</span>
                    <span>
                      Distance: {suggestion.distance_km ? `${formatNumber(suggestion.distance_km)} km` : 'N/A'}
                    </span>
                    <span>Shift Remaining: {formatNumber(suggestion.remaining_shift_hours, 2)} hrs</span>
                  </div>
                  <div className="flex items-center gap-2 text-sm text-gray-500">
                    <InformationCircleIcon className="h-4 w-4" title={rationale} />
                    <span>Rationale available</span>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <Button
                    onClick={() => handleAssign(suggestion)}
                    disabled={assigningId === suggestion.driver_profile_id}
                  >
                    {assigningId === suggestion.driver_profile_id ? 'Assigning...' : 'Assign Driver'}
                  </Button>
                </div>
              </div>
            )}
          )}
        </div>
      </Card>
    </div>
  )
}
