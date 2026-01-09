import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  InformationCircleIcon,
  ClockIcon,
  TruckIcon,
  StarIcon,
  MapPinIcon,
  ExclamationTriangleIcon,
  CheckCircleIcon,
  XCircleIcon,
  PlayIcon,
  StopIcon,
  ChatBubbleLeftIcon,
  PhoneIcon,
} from '@heroicons/react/24/outline'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import dispatchService from '../../../services/dispatch.service'
import workorderService from '../../../services/workorder.service'
import { useToast } from '../../stores/toast'

const formatNumber = (value, digits = 2) => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return 'N/A'
  return Number(value).toFixed(digits)
}

const priorityColors = {
  high: 'text-green-600 bg-green-50',
  positive: 'text-blue-600 bg-blue-50',
  info: 'text-gray-600 bg-gray-50',
  warning: 'text-amber-600 bg-amber-50',
}

const JustificationBadge = ({ reason }) => {
  const colorClass = priorityColors[reason.priority] || priorityColors.info
  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${colorClass}`}>
      {reason.text}
    </span>
  )
}

const DriverScoreBreakdown = ({ scores }) => {
  const scoreItems = [
    { key: 'distance', label: 'Distance', value: scores?.distance },
    { key: 'equipment', label: 'Equipment', value: scores?.equipment },
    { key: 'eta', label: 'ETA', value: scores?.eta },
    { key: 'shift', label: 'Shift', value: scores?.shift },
    { key: 'performance', label: 'Performance', value: scores?.performance },
  ]

  return (
    <div className="grid grid-cols-5 gap-2 mt-2">
      {scoreItems.map((item) => (
        <div key={item.key} className="text-center">
          <div className="text-xs text-gray-500">{item.label}</div>
          <div className="text-sm font-medium">
            {item.value !== null && item.value !== undefined ? formatNumber(item.value, 2) : '-'}
          </div>
        </div>
      ))}
    </div>
  )
}

const IdleAlertPanel = ({ alerts, onAcknowledge }) => {
  if (!alerts || alerts.length === 0) return null

  return (
    <Card title="Idle Driver Alerts" className="border-amber-200 bg-amber-50">
      <div className="space-y-3">
        {alerts.map((alert) => (
          <div
            key={alert.id}
            className="flex items-center justify-between p-3 bg-white rounded-lg border border-amber-200"
          >
            <div className="flex items-center gap-3">
              <ExclamationTriangleIcon className="h-5 w-5 text-amber-500" />
              <div>
                <p className="font-medium text-gray-900">{alert.driver_name}</p>
                <p className="text-sm text-gray-500">
                  Stationary for {alert.stationary_minutes} minutes on job {alert.job_reference || 'N/A'}
                </p>
              </div>
            </div>
            <Button size="sm" variant="secondary" onClick={() => onAcknowledge(alert.id)}>
              Acknowledge
            </Button>
          </div>
        ))}
      </div>
    </Card>
  )
}

const WaterfallStatusPanel = ({ waterfalls, onCancel, onRefresh }) => {
  if (!waterfalls || waterfalls.length === 0) return null

  return (
    <Card title="Active Waterfall Sequences">
      <div className="space-y-3">
        {waterfalls.map((seq) => (
          <div
            key={seq.id}
            className="flex items-center justify-between p-3 bg-gray-50 rounded-lg border"
          >
            <div>
              <div className="flex items-center gap-2">
                <PlayIcon className="h-4 w-4 text-green-500" />
                <span className="font-medium">{seq.sequence_reference}</span>
                <Badge variant="info">Position {seq.current_position}</Badge>
              </div>
              <p className="text-sm text-gray-500 mt-1">
                Job: {seq.job_reference} | Timeout: {seq.offer_timeout_seconds}s
              </p>
            </div>
            <div className="flex items-center gap-2">
              <Button size="sm" variant="secondary" onClick={onRefresh}>
                Refresh
              </Button>
              <Button size="sm" variant="danger" onClick={() => onCancel(seq.id)}>
                Cancel
              </Button>
            </div>
          </div>
        ))}
      </div>
    </Card>
  )
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
  const [scoringWeights, setScoringWeights] = useState(null)
  const [excludedDrivers, setExcludedDrivers] = useState([])

  // Waterfall state
  const [waterfallMode, setWaterfallMode] = useState(false)
  const [offerTimeout, setOfferTimeout] = useState('60')
  const [activeWaterfalls, setActiveWaterfalls] = useState([])
  const [initiatingWaterfall, setInitiatingWaterfall] = useState(false)

  // Idle alerts
  const [idleAlerts, setIdleAlerts] = useState([])

  // Heatmap data
  const [heatmapData, setHeatmapData] = useState([])
  const [showHeatmap, setShowHeatmap] = useState(false)

  const hasSuggestions = suggestions.length > 0

  // Load idle alerts and active waterfalls on mount
  useEffect(() => {
    const loadDispatcherData = async () => {
      try {
        const [alertsRes, waterfallsRes] = await Promise.all([
          dispatchService.getIdleAlerts().catch(() => ({ data: { data: [] } })),
          dispatchService.getActiveWaterfalls().catch(() => ({ data: { data: [] } })),
        ])
        setIdleAlerts(alertsRes.data?.data ?? [])
        setActiveWaterfalls(waterfallsRes.data?.data ?? [])
      } catch {
        // Silent fail for initial load
      }
    }
    loadDispatcherData()

    // Refresh every 30 seconds
    const interval = setInterval(loadDispatcherData, 30000)
    return () => clearInterval(interval)
  }, [])

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
      setScoringWeights(response.data?.scoring_weights ?? null)
      setExcludedDrivers(response.data?.excluded_drivers ?? [])
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
        eta_minutes: suggestion.eta_minutes,
        equipment: suggestion.equipment,
        remaining_shift_hours: suggestion.remaining_shift_hours,
        scores: suggestion.scores,
        justification: suggestion.justification,
      })
      success('Driver assigned successfully.')
    } catch (err) {
      toastError(err?.response?.data?.message || 'Unable to assign driver.')
    } finally {
      setAssigningId(null)
    }
  }

  const handleInitiateWaterfall = async () => {
    if (!dispatchRequirementId || !workorderId) {
      toastError('Both Dispatch Requirement ID and Workorder ID are required for waterfall dispatch.')
      return
    }

    setInitiatingWaterfall(true)
    try {
      const response = await dispatchService.initiateWaterfall({
        dispatch_requirement_id: Number(dispatchRequirementId),
        job_reference: workorderId,
        job_type: 'workorder',
        offer_timeout_seconds: Number(offerTimeout) || 60,
        max_offers: Number(limit) || 10,
      })
      success(`Waterfall dispatch initiated: ${response.data?.sequence_reference}`)
      // Refresh active waterfalls
      const waterfallsRes = await dispatchService.getActiveWaterfalls()
      setActiveWaterfalls(waterfallsRes.data?.data ?? [])
    } catch (err) {
      toastError(err?.response?.data?.message || 'Failed to initiate waterfall dispatch.')
    } finally {
      setInitiatingWaterfall(false)
    }
  }

  const handleCancelWaterfall = async (waterfallId) => {
    try {
      await dispatchService.cancelWaterfall(waterfallId, 'Cancelled by dispatcher')
      success('Waterfall dispatch cancelled.')
      setActiveWaterfalls((prev) => prev.filter((w) => w.id !== waterfallId))
    } catch (err) {
      toastError(err?.response?.data?.message || 'Failed to cancel waterfall.')
    }
  }

  const handleAcknowledgeAlert = async (alertId) => {
    try {
      await dispatchService.acknowledgeIdleAlert(alertId)
      success('Alert acknowledged.')
      setIdleAlerts((prev) => prev.filter((a) => a.id !== alertId))
    } catch (err) {
      toastError(err?.response?.data?.message || 'Failed to acknowledge alert.')
    }
  }

  const loadHeatmapData = useCallback(async () => {
    try {
      const response = await dispatchService.getHeatmapData()
      setHeatmapData(response.data?.data ?? [])
    } catch {
      // Silent fail
    }
  }, [])

  useEffect(() => {
    if (showHeatmap) {
      loadHeatmapData()
    }
  }, [showHeatmap, loadHeatmapData])

  const requirementMeta = useMemo(() => {
    if (!requirement) return []
    return [
      { label: 'Required Equipment', value: requirement.required_equipment_class || 'Any' },
      { label: 'Required Capacity', value: requirement.required_capacity ?? 'N/A' },
      { label: 'Estimated Hours', value: requirement.estimated_duration_hours ?? 'N/A' },
      {
        label: 'Pickup Location',
        value:
          requirement.pickup_latitude && requirement.pickup_longitude
            ? `${formatNumber(requirement.pickup_latitude, 4)}, ${formatNumber(requirement.pickup_longitude, 4)}`
            : 'N/A',
      },
      {
        label: 'Required Certifications',
        value:
          requirement.required_certifications && requirement.required_certifications.length > 0
            ? requirement.required_certifications.join(', ')
            : 'None',
      },
    ]
  }, [requirement])

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Dispatch Console</h1>
          <p className="text-sm text-gray-500">
            Rank drivers by ETA, equipment fit, performance, and availability.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="secondary" size="sm" onClick={() => setShowHeatmap(!showHeatmap)}>
            {showHeatmap ? 'Hide' : 'Show'} Heatmap
          </Button>
        </div>
      </div>

      {/* Idle Alerts */}
      <IdleAlertPanel alerts={idleAlerts} onAcknowledge={handleAcknowledgeAlert} />

      {/* Active Waterfalls */}
      <WaterfallStatusPanel
        waterfalls={activeWaterfalls}
        onCancel={handleCancelWaterfall}
        onRefresh={async () => {
          const res = await dispatchService.getActiveWaterfalls()
          setActiveWaterfalls(res.data?.data ?? [])
        }}
      />

      {/* Heatmap Placeholder */}
      {showHeatmap && (
        <Card title="Job Density Heatmap">
          <div className="h-64 bg-gray-100 rounded-lg flex items-center justify-center">
            <div className="text-center text-gray-500">
              <MapPinIcon className="h-12 w-12 mx-auto mb-2" />
              <p>Heatmap visualization</p>
              <p className="text-xs">{heatmapData.length} data points loaded</p>
            </div>
          </div>
        </Card>
      )}

      <Card title="Dispatch Inputs">
        <div className="grid gap-4 md:grid-cols-4">
          <Input
            label="Dispatch Requirement ID"
            placeholder="e.g. 1024"
            modelValue={dispatchRequirementId}
            onUpdateModelValue={setDispatchRequirementId}
          />
          <Input
            label="Workorder ID"
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
          <Input
            label="Offer Timeout (sec)"
            placeholder="60"
            modelValue={offerTimeout}
            onUpdateModelValue={setOfferTimeout}
          />
        </div>

        <div className="mt-4 flex flex-wrap items-center gap-3">
          <Button onClick={handleFetch} disabled={loading}>
            {loading ? 'Loading...' : 'Get Suggestions'}
          </Button>
          <Button
            variant="primary"
            onClick={handleInitiateWaterfall}
            disabled={initiatingWaterfall || !dispatchRequirementId || !workorderId}
          >
            {initiatingWaterfall ? 'Initiating...' : 'Start Waterfall Dispatch'}
          </Button>
          {loading ? <Loading size="sm" /> : null}
        </div>
        {loadError ? <Alert variant="error" className="mt-4" message={loadError} /> : null}
      </Card>

      {requirement ? (
        <Card title="Dispatch Requirement Summary">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            {requirementMeta.map((item) => (
              <div key={item.label} className="rounded-md border border-gray-200 p-3">
                <p className="text-xs uppercase text-gray-400">{item.label}</p>
                <p className="text-sm font-medium text-gray-900">{item.value}</p>
              </div>
            ))}
          </div>
        </Card>
      ) : null}

      {/* Excluded Drivers Info */}
      {excludedDrivers.length > 0 && (
        <Alert
          variant="warning"
          message={`${excludedDrivers.length} driver(s) excluded due to equipment or certification requirements.`}
        />
      )}

      {/* Scoring Weights */}
      {scoringWeights && (
        <div className="text-xs text-gray-500 flex items-center gap-4">
          <span>Scoring weights:</span>
          {Object.entries(scoringWeights).map(([key, value]) => (
            <span key={key}>
              {key}: {(value * 100).toFixed(0)}%
            </span>
          ))}
        </div>
      )}

      <Card title="Suggested Drivers">
        {!hasSuggestions && !loading ? (
          <p className="text-sm text-gray-500">No suggestions yet. Fetch to view ranked drivers.</p>
        ) : null}
        <div className="space-y-4">
          {suggestions.map((suggestion, index) => {
            const overallScore = suggestion.scores?.overall ?? 0

            return (
              <div
                key={suggestion.driver_profile_id}
                className={`rounded-lg border p-4 ${index === 0 ? 'border-green-300 bg-green-50' : 'border-gray-200'}`}
              >
                <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                  <div className="flex-1 space-y-3">
                    {/* Header */}
                    <div className="flex items-center gap-3">
                      <span className="flex items-center justify-center w-8 h-8 rounded-full bg-gray-100 text-sm font-bold">
                        #{index + 1}
                      </span>
                      <div>
                        <p className="text-lg font-semibold text-gray-900">{suggestion.driver_name}</p>
                        <p className="text-sm text-gray-500">{suggestion.driver_email}</p>
                      </div>
                      <Badge variant={index === 0 ? 'success' : 'info'}>
                        Score {formatNumber(overallScore, 3)}
                      </Badge>
                      {suggestion.current_location && (
                        <Badge variant="info">Live Location</Badge>
                      )}
                    </div>

                    {/* Key Metrics */}
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                      <div className="flex items-center gap-2">
                        <ClockIcon className="h-4 w-4 text-gray-400" />
                        <span>
                          Traffic-aware ETA:{' '}
                          {suggestion.eta_minutes !== null
                            ? `${suggestion.eta_minutes} min`
                            : 'N/A'}
                          {suggestion.traffic_factor > 1.2 && (
                            <span className="text-amber-500 ml-1">(traffic)</span>
                          )}
                        </span>
                      </div>
                      <div className="flex items-center gap-2">
                        <MapPinIcon className="h-4 w-4 text-gray-400" />
                        <span>
                          Raw distance:{' '}
                          {suggestion.distance_km !== null
                            ? `${formatNumber(suggestion.distance_km)} km`
                            : 'N/A'}
                        </span>
                      </div>
                      <div className="flex items-center gap-2">
                        <TruckIcon className="h-4 w-4 text-gray-400" />
                        <span>{suggestion.equipment?.equipment_class || 'Unspecified'}</span>
                        {suggestion.equipment_compatible === false && (
                          <XCircleIcon className="h-4 w-4 text-red-500" title="Incompatible" />
                        )}
                      </div>
                      <div className="flex items-center gap-2">
                        <StarIcon className="h-4 w-4 text-gray-400" />
                        <span>
                          {suggestion.performance?.avg_rating
                            ? `${formatNumber(suggestion.performance.avg_rating, 1)} stars`
                            : 'No rating'}
                        </span>
                      </div>
                    </div>

                    {/* Justification Badges */}
                    {suggestion.justification && suggestion.justification.length > 0 && (
                      <div className="flex flex-wrap gap-2">
                        {suggestion.justification.slice(0, 4).map((reason, i) => (
                          <JustificationBadge key={i} reason={reason} />
                        ))}
                      </div>
                    )}

                    {/* Score Breakdown */}
                    <DriverScoreBreakdown scores={suggestion.scores} />

                    {/* Additional Info */}
                    <div className="text-xs text-gray-500 flex flex-wrap gap-4">
                      <span>Shift remaining: {formatNumber(suggestion.remaining_shift_hours, 1)} hrs</span>
                      {suggestion.certifications && suggestion.certifications.length > 0 && (
                        <span>Certifications: {suggestion.certifications.join(', ')}</span>
                      )}
                      {suggestion.performance?.jobs_completed_today > 0 && (
                        <span>Jobs today: {suggestion.performance.jobs_completed_today}</span>
                      )}
                    </div>
                  </div>

                  {/* Actions */}
                  <div className="flex flex-col gap-2">
                    <Button
                      onClick={() => handleAssign(suggestion)}
                      disabled={assigningId === suggestion.driver_profile_id}
                      variant={index === 0 ? 'primary' : 'secondary'}
                    >
                      {assigningId === suggestion.driver_profile_id ? 'Assigning...' : 'Assign Driver'}
                    </Button>
                    <div className="flex gap-2">
                      <Button size="sm" variant="ghost" title="Quick Message">
                        <ChatBubbleLeftIcon className="h-4 w-4" />
                      </Button>
                      <Button size="sm" variant="ghost" title="Call Driver">
                        <PhoneIcon className="h-4 w-4" />
                      </Button>
                    </div>
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      </Card>
    </div>
  )
}
