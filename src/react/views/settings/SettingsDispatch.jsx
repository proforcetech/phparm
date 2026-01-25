import { useCallback, useEffect, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import dispatchService from '../../../services/dispatch.service'
import { useToast } from '../../stores/toast'

const strategyOptions = [
  { value: 'highest_score', label: 'Highest Score' },
  { value: 'round_robin', label: 'Round Robin' },
  { value: 'balanced', label: 'Balanced' },
]

const limitModeOptions = [
  { value: 'soft', label: 'Soft (Penalize Score)' },
  { value: 'hard', label: 'Hard (Exclude Driver)' },
]

const initialForm = {
  strategy: 'highest_score',
  fairness_weight: 0.20,
  workload: {
    max_concurrent_jobs: 3,
    limit_mode: 'soft',
    soft_limit_penalty: 0.5,
  },
  acceptance_rate: {
    weight: 0.05,
    minimum_threshold: null,
    lookback_days: 30,
  },
  job_priority: {
    enabled: true,
  },
  fair_distribution: {
    enabled: true,
    tracking_window_hours: 24,
    min_offers_guarantee: 5,
    under_offered_bonus: 0.15,
  },
}

export default function SettingsDispatch() {
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [form, setForm] = useState(initialForm)
  const [stats, setStats] = useState(null)
  const { addToast } = useToast()

  const loadSettings = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await dispatchService.getDispatchSettings()
      if (response.data?.data) {
        setForm((prev) => ({
          ...prev,
          ...response.data.data,
          workload: { ...prev.workload, ...(response.data.data.workload || {}) },
          acceptance_rate: { ...prev.acceptance_rate, ...(response.data.data.acceptance_rate || {}) },
          job_priority: { ...prev.job_priority, ...(response.data.data.job_priority || {}) },
          fair_distribution: { ...prev.fair_distribution, ...(response.data.data.fair_distribution || {}) },
        }))
      }

      // Load stats
      const statsResponse = await dispatchService.getLoadBalancingStats(24)
      if (statsResponse.data?.data) {
        setStats(statsResponse.data.data)
      }
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'Failed to load dispatch settings')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadSettings()
  }, [loadSettings])

  const handleSave = async () => {
    setSaving(true)
    setMessage('')
    setError('')
    try {
      await dispatchService.saveDispatchSettings(form)
      setMessage('Dispatch settings saved successfully')
      addToast('Dispatch settings saved', 'success')
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'Failed to save dispatch settings')
    } finally {
      setSaving(false)
    }
  }

  const updateField = (path, value) => {
    setForm((prev) => {
      const next = { ...prev }
      if (path.length === 1) {
        next[path[0]] = value
      } else if (path.length === 2) {
        next[path[0]] = { ...next[path[0]], [path[1]]: value }
      }
      return next
    })
  }

  if (loading) {
    return <div className="text-gray-500">Loading dispatch settings...</div>
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Dispatch Load Balancing</h1>
          <p className="mt-1 text-sm text-gray-500">
            Configure driver selection strategy and fair distribution parameters.
          </p>
        </div>
        <Button loading={saving} onClick={handleSave}>Save Settings</Button>
      </div>

      {message ? <Alert variant="success" className="mb-4">{message}</Alert> : null}
      {error ? <Alert variant="danger" className="mb-4">{error}</Alert> : null}

      <div className="space-y-6">
        {/* Strategy Selection */}
        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Dispatch Strategy</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Selection Strategy</label>
              <Select
                value={form.strategy}
                onChange={(e) => updateField(['strategy'], e.target.value)}
                options={strategyOptions}
                className="mt-1"
              />
              <p className="mt-1 text-xs text-gray-500">
                How drivers are prioritized when dispatching jobs.
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Fairness Weight</label>
              <Input
                type="number"
                step="0.05"
                min="0"
                max="1"
                value={form.fairness_weight}
                onChange={(e) => updateField(['fairness_weight'], parseFloat(e.target.value) || 0)}
                className="mt-1"
              />
              <p className="mt-1 text-xs text-gray-500">
                Weight given to fair distribution (0.0 to 1.0).
              </p>
            </div>
          </div>
        </Card>

        {/* Workload Limits */}
        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Workload Limits</h2>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Max Concurrent Jobs</label>
              <Input
                type="number"
                min="1"
                max="10"
                value={form.workload.max_concurrent_jobs}
                onChange={(e) => updateField(['workload', 'max_concurrent_jobs'], parseInt(e.target.value, 10) || 1)}
                className="mt-1"
              />
              <p className="mt-1 text-xs text-gray-500">
                Maximum active jobs per driver.
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Limit Mode</label>
              <Select
                value={form.workload.limit_mode}
                onChange={(e) => updateField(['workload', 'limit_mode'], e.target.value)}
                options={limitModeOptions}
                className="mt-1"
              />
              <p className="mt-1 text-xs text-gray-500">
                How to handle drivers at the limit.
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Soft Limit Penalty</label>
              <Input
                type="number"
                step="0.1"
                min="0"
                max="1"
                value={form.workload.soft_limit_penalty}
                onChange={(e) => updateField(['workload', 'soft_limit_penalty'], parseFloat(e.target.value) || 0)}
                className="mt-1"
                disabled={form.workload.limit_mode === 'hard'}
              />
              <p className="mt-1 text-xs text-gray-500">
                Score multiplier for soft mode (0.5 = 50% penalty).
              </p>
            </div>
          </div>
        </Card>

        {/* Acceptance Rate */}
        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Acceptance Rate</h2>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Weight</label>
              <Input
                type="number"
                step="0.01"
                min="0"
                max="0.5"
                value={form.acceptance_rate.weight}
                onChange={(e) => updateField(['acceptance_rate', 'weight'], parseFloat(e.target.value) || 0)}
                className="mt-1"
              />
              <p className="mt-1 text-xs text-gray-500">
                Scoring weight for acceptance rate.
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Minimum Threshold (%)</label>
              <Input
                type="number"
                step="5"
                min="0"
                max="100"
                value={form.acceptance_rate.minimum_threshold !== null ? form.acceptance_rate.minimum_threshold * 100 : ''}
                placeholder="No minimum"
                onChange={(e) => {
                  const val = e.target.value
                  updateField(['acceptance_rate', 'minimum_threshold'], val ? parseFloat(val) / 100 : null)
                }}
                className="mt-1"
              />
              <p className="mt-1 text-xs text-gray-500">
                Exclude drivers below this rate.
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Lookback Days</label>
              <Input
                type="number"
                min="7"
                max="90"
                value={form.acceptance_rate.lookback_days}
                onChange={(e) => updateField(['acceptance_rate', 'lookback_days'], parseInt(e.target.value, 10) || 30)}
                className="mt-1"
              />
              <p className="mt-1 text-xs text-gray-500">
                Days of history to calculate rate.
              </p>
            </div>
          </div>
        </Card>

        {/* Job Priority */}
        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Job Priority</h2>
          <div className="flex items-center gap-3">
            <input
              type="checkbox"
              id="priority-enabled"
              checked={form.job_priority.enabled}
              onChange={(e) => updateField(['job_priority', 'enabled'], e.target.checked)}
              className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
            />
            <label htmlFor="priority-enabled" className="text-sm text-gray-700">
              Enable job priority levels (normal, high, urgent, vip)
            </label>
          </div>
          <p className="mt-2 text-xs text-gray-500">
            Priority levels adjust offer timeout and queue depth multipliers.
          </p>
        </Card>

        {/* Fair Distribution */}
        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Fair Distribution</h2>
          <div className="mb-4 flex items-center gap-3">
            <input
              type="checkbox"
              id="fair-enabled"
              checked={form.fair_distribution.enabled}
              onChange={(e) => updateField(['fair_distribution', 'enabled'], e.target.checked)}
              className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
            />
            <label htmlFor="fair-enabled" className="text-sm text-gray-700">
              Enable fair distribution tracking
            </label>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Tracking Window (hours)</label>
              <Input
                type="number"
                min="1"
                max="168"
                value={form.fair_distribution.tracking_window_hours}
                onChange={(e) => updateField(['fair_distribution', 'tracking_window_hours'], parseInt(e.target.value, 10) || 24)}
                className="mt-1"
                disabled={!form.fair_distribution.enabled}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Min Offers Guarantee</label>
              <Input
                type="number"
                min="0"
                max="20"
                value={form.fair_distribution.min_offers_guarantee}
                onChange={(e) => updateField(['fair_distribution', 'min_offers_guarantee'], parseInt(e.target.value, 10) || 0)}
                className="mt-1"
                disabled={!form.fair_distribution.enabled}
              />
              <p className="mt-1 text-xs text-gray-500">
                Minimum offers per driver per window.
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Under-Offered Bonus</label>
              <Input
                type="number"
                step="0.05"
                min="0"
                max="0.5"
                value={form.fair_distribution.under_offered_bonus}
                onChange={(e) => updateField(['fair_distribution', 'under_offered_bonus'], parseFloat(e.target.value) || 0)}
                className="mt-1"
                disabled={!form.fair_distribution.enabled}
              />
              <p className="mt-1 text-xs text-gray-500">
                Score bonus for under-offered drivers.
              </p>
            </div>
          </div>
        </Card>

        {/* Statistics */}
        {stats && (
          <Card>
            <h2 className="text-lg font-semibold text-gray-900 mb-4">Distribution Statistics (Last 24h)</h2>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div className="text-center p-3 bg-gray-50 rounded-lg">
                <div className="text-2xl font-bold text-gray-900">{stats.total_offers}</div>
                <div className="text-sm text-gray-500">Total Offers</div>
              </div>
              <div className="text-center p-3 bg-green-50 rounded-lg">
                <div className="text-2xl font-bold text-green-600">{stats.total_accepted}</div>
                <div className="text-sm text-gray-500">Accepted</div>
              </div>
              <div className="text-center p-3 bg-red-50 rounded-lg">
                <div className="text-2xl font-bold text-red-600">{stats.total_declined}</div>
                <div className="text-sm text-gray-500">Declined</div>
              </div>
              <div className="text-center p-3 bg-amber-50 rounded-lg">
                <div className="text-2xl font-bold text-amber-600">{stats.total_expired}</div>
                <div className="text-sm text-gray-500">Expired</div>
              </div>
            </div>
            <div className="mt-4 grid grid-cols-2 md:grid-cols-3 gap-4">
              <div className="text-center p-3 bg-blue-50 rounded-lg">
                <div className="text-xl font-bold text-blue-600">
                  {(stats.overall_acceptance_rate * 100).toFixed(1)}%
                </div>
                <div className="text-sm text-gray-500">Acceptance Rate</div>
              </div>
              <div className="text-center p-3 bg-purple-50 rounded-lg">
                <div className="text-xl font-bold text-purple-600">
                  {stats.average_offers_per_driver}
                </div>
                <div className="text-sm text-gray-500">Avg Offers/Driver</div>
              </div>
              <div className="text-center p-3 bg-indigo-50 rounded-lg">
                <div className="text-xl font-bold text-indigo-600">
                  {(stats.fairness_score * 100).toFixed(0)}%
                </div>
                <div className="text-sm text-gray-500">Fairness Score</div>
              </div>
            </div>
          </Card>
        )}
      </div>
    </div>
  )
}
