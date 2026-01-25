import { useCallback, useEffect, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import {
  getVinDecoderSettings,
  saveVinDecoderSettings,
  getVinDecoderStats,
  clearVinDecoderCache,
} from '../../../services/vehicle.service'
import { useToast } from '../../stores/toast'

const decoderOptions = [
  { value: 'nhtsa', label: 'NHTSA (Free)' },
  { value: 'partstech', label: 'PartsTech' },
  { value: 'auto', label: 'Auto (Fallback Chain)' },
]

const cacheTtlOptions = [
  { value: 86400, label: '1 Day' },
  { value: 604800, label: '7 Days' },
  { value: 2592000, label: '30 Days' },
  { value: 7776000, label: '90 Days' },
  { value: 31536000, label: '1 Year' },
]

const initialForm = {
  primary_decoder: 'nhtsa',
  fallback_enabled: true,
  nhtsa: {
    enabled: true,
    timeout: 10,
  },
  partstech: {
    enabled: false,
    timeout: 10,
  },
  cache: {
    enabled: true,
    ttl_seconds: 2592000,
    memory_cache: true,
  },
  rate_limit: {
    enabled: true,
    per_user_per_minute: 30,
    global_per_minute: 100,
    burst_allowance: 5,
  },
  retry: {
    enabled: true,
    max_attempts: 2,
    initial_delay_ms: 500,
  },
  logging: {
    log_decodes: true,
    log_cache: false,
    log_errors: true,
  },
}

export default function SettingsVinDecoder() {
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [clearing, setClearing] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [form, setForm] = useState(initialForm)
  const [stats, setStats] = useState(null)
  const [availableDecoders, setAvailableDecoders] = useState([])
  const { addToast } = useToast()

  const loadSettings = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await getVinDecoderSettings()
      if (response.data?.data) {
        const data = response.data.data
        setForm((prev) => ({
          ...prev,
          ...data,
          nhtsa: { ...prev.nhtsa, ...(data.nhtsa || {}) },
          partstech: { ...prev.partstech, ...(data.partstech || {}) },
          cache: { ...prev.cache, ...(data.cache || {}) },
          rate_limit: { ...prev.rate_limit, ...(data.rate_limit || {}) },
          retry: { ...prev.retry, ...(data.retry || {}) },
          logging: { ...prev.logging, ...(data.logging || {}) },
        }))
        setAvailableDecoders(data.available_decoders || [])
      }

      // Load stats
      const statsResponse = await getVinDecoderStats(30)
      if (statsResponse.data?.data) {
        setStats(statsResponse.data.data)
      }
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'Failed to load VIN decoder settings')
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
      await saveVinDecoderSettings(form)
      setMessage('VIN decoder settings saved successfully')
      addToast('VIN decoder settings saved', 'success')
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'Failed to save VIN decoder settings')
    } finally {
      setSaving(false)
    }
  }

  const handleClearCache = async (expiredOnly = false) => {
    setClearing(true)
    try {
      const response = await clearVinDecoderCache(expiredOnly)
      const count = response.data?.data?.deleted_count || 0
      addToast(`Cleared ${count} cache entries`, 'success')
      loadSettings() // Reload stats
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'Failed to clear cache')
    } finally {
      setClearing(false)
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

  const formatCacheTtl = (seconds) => {
    const days = Math.round(seconds / 86400)
    if (days >= 365) return `${Math.round(days / 365)} year(s)`
    if (days >= 30) return `${Math.round(days / 30)} month(s)`
    return `${days} day(s)`
  }

  if (loading) {
    return <div className="text-gray-500">Loading VIN decoder settings...</div>
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">VIN Decoder Settings</h1>
          <p className="mt-1 text-sm text-gray-500">
            Configure VIN decoding services, caching, and rate limiting.
          </p>
        </div>
        <Button loading={saving} onClick={handleSave}>Save Settings</Button>
      </div>

      {message ? <Alert variant="success" className="mb-4">{message}</Alert> : null}
      {error ? <Alert variant="danger" className="mb-4">{error}</Alert> : null}

      <div className="space-y-6">
        {/* Decoder Selection */}
        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Decoder Configuration</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Primary Decoder</label>
              <Select
                value={form.primary_decoder}
                onChange={(e) => updateField(['primary_decoder'], e.target.value)}
                options={decoderOptions}
                className="mt-1"
              />
              <p className="mt-1 text-xs text-gray-500">
                Select the primary VIN decoder service to use.
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Fallback</label>
              <div className="mt-2 flex items-center gap-3">
                <input
                  type="checkbox"
                  id="fallback-enabled"
                  checked={form.fallback_enabled}
                  onChange={(e) => updateField(['fallback_enabled'], e.target.checked)}
                  className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
                />
                <label htmlFor="fallback-enabled" className="text-sm text-gray-700">
                  Enable fallback to secondary decoder on failure
                </label>
              </div>
            </div>
          </div>
          <div className="mt-4">
            <div className="text-sm font-medium text-gray-700 mb-2">Available Decoders</div>
            <div className="flex flex-wrap gap-2">
              {availableDecoders.length > 0 ? (
                availableDecoders.map((decoder) => (
                  <span
                    key={decoder}
                    className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"
                  >
                    {decoder.toUpperCase()}
                  </span>
                ))
              ) : (
                <span className="text-sm text-gray-500">No decoders available</span>
              )}
            </div>
          </div>
        </Card>

        {/* NHTSA Settings */}
        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">NHTSA Settings</h2>
          <p className="text-sm text-gray-500 mb-4">
            Free VIN decoder from the National Highway Traffic Safety Administration.
          </p>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <div className="flex items-center gap-3">
                <input
                  type="checkbox"
                  id="nhtsa-enabled"
                  checked={form.nhtsa.enabled}
                  onChange={(e) => updateField(['nhtsa', 'enabled'], e.target.checked)}
                  className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
                />
                <label htmlFor="nhtsa-enabled" className="text-sm text-gray-700">
                  Enable NHTSA decoder
                </label>
              </div>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Timeout (seconds)</label>
              <Input
                type="number"
                min="5"
                max="30"
                value={form.nhtsa.timeout}
                onChange={(e) => updateField(['nhtsa', 'timeout'], parseInt(e.target.value, 10) || 10)}
                className="mt-1"
                disabled={!form.nhtsa.enabled}
              />
            </div>
          </div>
        </Card>

        {/* PartsTech Settings */}
        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">PartsTech Settings</h2>
          <p className="text-sm text-gray-500 mb-4">
            Premium VIN decoder through PartsTech integration (requires API key).
          </p>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <div className="flex items-center gap-3">
                <input
                  type="checkbox"
                  id="partstech-enabled"
                  checked={form.partstech.enabled}
                  onChange={(e) => updateField(['partstech', 'enabled'], e.target.checked)}
                  className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
                />
                <label htmlFor="partstech-enabled" className="text-sm text-gray-700">
                  Enable PartsTech decoder
                </label>
              </div>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Timeout (seconds)</label>
              <Input
                type="number"
                min="5"
                max="30"
                value={form.partstech.timeout}
                onChange={(e) => updateField(['partstech', 'timeout'], parseInt(e.target.value, 10) || 10)}
                className="mt-1"
                disabled={!form.partstech.enabled}
              />
            </div>
          </div>
        </Card>

        {/* Caching */}
        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Caching</h2>
          <div className="mb-4 flex items-center gap-3">
            <input
              type="checkbox"
              id="cache-enabled"
              checked={form.cache.enabled}
              onChange={(e) => updateField(['cache', 'enabled'], e.target.checked)}
              className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
            />
            <label htmlFor="cache-enabled" className="text-sm text-gray-700">
              Enable database caching of decoded VINs
            </label>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Cache Duration</label>
              <Select
                value={form.cache.ttl_seconds}
                onChange={(e) => updateField(['cache', 'ttl_seconds'], parseInt(e.target.value, 10))}
                options={cacheTtlOptions}
                className="mt-1"
                disabled={!form.cache.enabled}
              />
              <p className="mt-1 text-xs text-gray-500">
                How long to cache decoded VIN data.
              </p>
            </div>
            <div>
              <div className="mt-6 flex items-center gap-3">
                <input
                  type="checkbox"
                  id="memory-cache"
                  checked={form.cache.memory_cache}
                  onChange={(e) => updateField(['cache', 'memory_cache'], e.target.checked)}
                  className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
                  disabled={!form.cache.enabled}
                />
                <label htmlFor="memory-cache" className="text-sm text-gray-700">
                  Enable in-memory cache for same-request deduplication
                </label>
              </div>
            </div>
          </div>
          <div className="mt-4 flex gap-3">
            <Button
              variant="outline"
              size="sm"
              loading={clearing}
              onClick={() => handleClearCache(true)}
            >
              Clear Expired Cache
            </Button>
            <Button
              variant="outline"
              size="sm"
              loading={clearing}
              onClick={() => handleClearCache(false)}
            >
              Clear All Cache
            </Button>
          </div>
        </Card>

        {/* Rate Limiting */}
        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Rate Limiting</h2>
          <div className="mb-4 flex items-center gap-3">
            <input
              type="checkbox"
              id="rate-limit-enabled"
              checked={form.rate_limit.enabled}
              onChange={(e) => updateField(['rate_limit', 'enabled'], e.target.checked)}
              className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
            />
            <label htmlFor="rate-limit-enabled" className="text-sm text-gray-700">
              Enable rate limiting
            </label>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Per User / Minute</label>
              <Input
                type="number"
                min="5"
                max="100"
                value={form.rate_limit.per_user_per_minute}
                onChange={(e) => updateField(['rate_limit', 'per_user_per_minute'], parseInt(e.target.value, 10) || 30)}
                className="mt-1"
                disabled={!form.rate_limit.enabled}
              />
              <p className="mt-1 text-xs text-gray-500">
                Max decodes per user per minute.
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Global / Minute</label>
              <Input
                type="number"
                min="10"
                max="500"
                value={form.rate_limit.global_per_minute}
                onChange={(e) => updateField(['rate_limit', 'global_per_minute'], parseInt(e.target.value, 10) || 100)}
                className="mt-1"
                disabled={!form.rate_limit.enabled}
              />
              <p className="mt-1 text-xs text-gray-500">
                Max decodes globally per minute.
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Burst Allowance</label>
              <Input
                type="number"
                min="0"
                max="20"
                value={form.rate_limit.burst_allowance}
                onChange={(e) => updateField(['rate_limit', 'burst_allowance'], parseInt(e.target.value, 10) || 5)}
                className="mt-1"
                disabled={!form.rate_limit.enabled}
              />
              <p className="mt-1 text-xs text-gray-500">
                Extra requests allowed in short bursts.
              </p>
            </div>
          </div>
        </Card>

        {/* Retry Settings */}
        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Retry Settings</h2>
          <div className="mb-4 flex items-center gap-3">
            <input
              type="checkbox"
              id="retry-enabled"
              checked={form.retry.enabled}
              onChange={(e) => updateField(['retry', 'enabled'], e.target.checked)}
              className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
            />
            <label htmlFor="retry-enabled" className="text-sm text-gray-700">
              Enable automatic retry on transient failures
            </label>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Max Attempts</label>
              <Input
                type="number"
                min="1"
                max="5"
                value={form.retry.max_attempts}
                onChange={(e) => updateField(['retry', 'max_attempts'], parseInt(e.target.value, 10) || 2)}
                className="mt-1"
                disabled={!form.retry.enabled}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Initial Delay (ms)</label>
              <Input
                type="number"
                min="100"
                max="2000"
                step="100"
                value={form.retry.initial_delay_ms}
                onChange={(e) => updateField(['retry', 'initial_delay_ms'], parseInt(e.target.value, 10) || 500)}
                className="mt-1"
                disabled={!form.retry.enabled}
              />
            </div>
          </div>
        </Card>

        {/* Logging */}
        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Logging</h2>
          <div className="space-y-3">
            <div className="flex items-center gap-3">
              <input
                type="checkbox"
                id="log-decodes"
                checked={form.logging.log_decodes}
                onChange={(e) => updateField(['logging', 'log_decodes'], e.target.checked)}
                className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
              />
              <label htmlFor="log-decodes" className="text-sm text-gray-700">
                Log all decode attempts
              </label>
            </div>
            <div className="flex items-center gap-3">
              <input
                type="checkbox"
                id="log-cache"
                checked={form.logging.log_cache}
                onChange={(e) => updateField(['logging', 'log_cache'], e.target.checked)}
                className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
              />
              <label htmlFor="log-cache" className="text-sm text-gray-700">
                Log cache hits/misses
              </label>
            </div>
            <div className="flex items-center gap-3">
              <input
                type="checkbox"
                id="log-errors"
                checked={form.logging.log_errors}
                onChange={(e) => updateField(['logging', 'log_errors'], e.target.checked)}
                className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
              />
              <label htmlFor="log-errors" className="text-sm text-gray-700">
                Log errors
              </label>
            </div>
          </div>
        </Card>

        {/* Statistics */}
        {stats && (
          <Card>
            <h2 className="text-lg font-semibold text-gray-900 mb-4">Statistics (Last 30 Days)</h2>

            {/* Cache Stats */}
            {stats.cache && stats.cache.length > 0 && (
              <div className="mb-6">
                <h3 className="text-md font-medium text-gray-700 mb-3">Cache Status</h3>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  {stats.cache.map((item) => (
                    <div key={item.decoder_source} className="text-center p-3 bg-gray-50 rounded-lg">
                      <div className="text-xs text-gray-500 uppercase mb-1">{item.decoder_source}</div>
                      <div className="text-xl font-bold text-gray-900">{item.valid_cached}</div>
                      <div className="text-xs text-gray-500">Valid Entries</div>
                      {item.expired_cached > 0 && (
                        <div className="text-xs text-amber-600 mt-1">{item.expired_cached} expired</div>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Decode Stats */}
            {stats.decodes && stats.decodes.length > 0 && (
              <div className="mb-6">
                <h3 className="text-md font-medium text-gray-700 mb-3">Decode Performance</h3>
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Source</th>
                        <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Requests</th>
                        <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Cache Hits</th>
                        <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Success</th>
                        <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Failed</th>
                        <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Avg Time</th>
                      </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                      {stats.decodes.map((item) => (
                        <tr key={item.decoder_source}>
                          <td className="px-4 py-2 text-sm font-medium text-gray-900">{item.decoder_source}</td>
                          <td className="px-4 py-2 text-sm text-right text-gray-500">{item.total_requests || 0}</td>
                          <td className="px-4 py-2 text-sm text-right text-green-600">{item.cache_hits || 0}</td>
                          <td className="px-4 py-2 text-sm text-right text-gray-900">{item.successful_decodes || 0}</td>
                          <td className="px-4 py-2 text-sm text-right text-red-600">{item.failed_decodes || 0}</td>
                          <td className="px-4 py-2 text-sm text-right text-gray-500">
                            {item.avg_response_time_ms ? `${Math.round(item.avg_response_time_ms)}ms` : '-'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {/* Rate Limit Status */}
            {stats.rate_limits && stats.rate_limits.length > 0 && (
              <div>
                <h3 className="text-md font-medium text-gray-700 mb-3">Current Rate Limits</h3>
                <div className="flex flex-wrap gap-3">
                  {stats.rate_limits.map((item) => (
                    <div key={item.rate_key} className="text-center p-2 bg-blue-50 rounded-lg">
                      <div className="text-xs text-gray-500">{item.rate_key}</div>
                      <div className="text-lg font-bold text-blue-600">{item.request_count}</div>
                      <div className="text-xs text-gray-500">requests/min</div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {!stats.cache?.length && !stats.decodes?.length && (
              <p className="text-sm text-gray-500">No statistics available yet.</p>
            )}
          </Card>
        )}
      </div>
    </div>
  )
}
