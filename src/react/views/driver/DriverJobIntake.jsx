import { useMemo, useState } from 'react'

import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import workorderService from '../../../services/workorder.service'
import { decodeVin } from '../../../services/vehicle.service'
import { normalizeVinData } from '../../../utils/vin'

const emptyVehicle = {
  vin: '',
  year: null,
  make: '',
  model: '',
  trim: '',
}

const checkpointTypes = [
  { key: 'pre_load', label: 'Pre-load photo' },
  { key: 'hookup', label: 'Hookup photo' },
  { key: 'dropoff', label: 'Drop-off photo' },
]

const clampPoint = (value) => Math.min(1, Math.max(0, value))
const isMobileDevice = () =>
  typeof navigator !== 'undefined' &&
  /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent)
const isAndroidDevice = () => typeof navigator !== 'undefined' && /Android/i.test(navigator.userAgent)
const parseCoordinate = (value) => {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}
const buildMapLinks = (provider, lat, lng, androidDevice) => {
  const coordinates = `${lat},${lng}`
  switch (provider) {
    case 'waze':
      return {
        deepLink: `waze://?ll=${coordinates}&navigate=yes`,
        webLink: `https://www.waze.com/ul?ll=${coordinates}&navigate=yes`,
      }
    case 'apple':
      return {
        deepLink: `maps://?daddr=${coordinates}`,
        webLink: `https://maps.apple.com/?daddr=${coordinates}`,
      }
    case 'google':
    default: {
      const deepLink = androidDevice
        ? `google.navigation:q=${coordinates}`
        : `comgooglemaps://?daddr=${coordinates}&directionsmode=driving`
      return {
        deepLink,
        webLink: `https://www.google.com/maps/search/?api=1&query=${coordinates}`,
      }
    }
  }
}

const DamageDiagram = ({ points, onAddPoint, onClear }) => {
  const handleClick = (event) => {
    const rect = event.currentTarget.getBoundingClientRect()
    const x = clampPoint((event.clientX - rect.left) / rect.width)
    const y = clampPoint((event.clientY - rect.top) / rect.height)
    onAddPoint({ x: Number(x.toFixed(3)), y: Number(y.toFixed(3)) })
  }

  return (
    <div>
      <div
        className="relative mx-auto flex h-64 w-full max-w-xl items-center justify-center rounded-lg border border-dashed border-gray-300 bg-gray-50"
        onClick={handleClick}
        role="button"
        tabIndex={0}
      >
        <svg className="absolute h-40 w-64 text-gray-300" viewBox="0 0 320 180" aria-hidden="true">
          <rect x="40" y="60" width="240" height="70" rx="24" fill="currentColor" opacity="0.3" />
          <rect x="80" y="30" width="160" height="50" rx="20" fill="currentColor" opacity="0.4" />
          <circle cx="90" cy="140" r="20" fill="currentColor" />
          <circle cx="230" cy="140" r="20" fill="currentColor" />
        </svg>
        {points.map((point, index) => (
          <span
            key={`${point.x}-${point.y}-${index}`}
            className="absolute h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-red-500 shadow"
            style={{ left: `${point.x * 100}%`, top: `${point.y * 100}%` }}
          />
        ))}
      </div>
      <div className="mt-3 flex items-center justify-between text-sm text-gray-600">
        <span>{points.length} damage marks</span>
        <Button variant="ghost" size="sm" onClick={onClear} disabled={points.length === 0}>
          Clear marks
        </Button>
      </div>
    </div>
  )
}

export default function DriverJobIntake() {
  const [workorderId, setWorkorderId] = useState('')
  const [jobId, setJobId] = useState('')
  const [destinationLat, setDestinationLat] = useState('')
  const [destinationLng, setDestinationLng] = useState('')
  const [mapProvider, setMapProvider] = useState('google')
  const [navigationStatus, setNavigationStatus] = useState('')
  const [vinFile, setVinFile] = useState(null)
  const [vinText, setVinText] = useState('')
  const [vinStatus, setVinStatus] = useState('')
  const [vehicleData, setVehicleData] = useState(emptyVehicle)
  const [ocrLoading, setOcrLoading] = useState(false)
  const [ocrError, setOcrError] = useState('')
  const [damagePoints, setDamagePoints] = useState([])
  const [damageNotes, setDamageNotes] = useState('')
  const [damageStatus, setDamageStatus] = useState('')
  const [checkpointFiles, setCheckpointFiles] = useState({})
  const [checkpointStatus, setCheckpointStatus] = useState(null)
  const [checkpointMessage, setCheckpointMessage] = useState('')

  const canSubmitJob = workorderId && jobId

  const summaryItems = useMemo(() => {
    if (!vehicleData.vin) return []
    return [
      { label: 'VIN', value: vehicleData.vin },
      { label: 'Year', value: vehicleData.year || '—' },
      { label: 'Make', value: vehicleData.make || '—' },
      { label: 'Model', value: vehicleData.model || '—' },
      { label: 'Trim', value: vehicleData.trim || '—' },
    ]
  }, [vehicleData])

  const handleVinDecode = async (vinValue) => {
    if (!vinValue || vinValue.length < 17) return
    try {
      const response = await decodeVin(vinValue)
      const normalized = normalizeVinData(response)
      setVehicleData({
        vin: normalized.vin || vinValue,
        year: normalized.year,
        make: normalized.make || '',
        model: normalized.model || '',
        trim: normalized.trim || '',
      })
      setVinStatus('Vehicle data populated from VIN decode.')
    } catch (error) {
      console.error('VIN decode failed', error)
      setVinStatus('VIN decoded, but vehicle data could not be retrieved.')
    }
  }

  const runOcr = async () => {
    if (!vinFile) {
      setOcrError('Upload a VIN photo to run OCR.')
      return
    }

    setOcrLoading(true)
    setOcrError('')
    setVinStatus('')

    try {
      const { createWorker } = await import('tesseract.js')
      const worker = await createWorker('eng')
      await worker.setParameters({
        tessedit_char_whitelist: 'ABCDEFGHJKLMNPRSTUVWXYZ0123456789',
      })

      const { data } = await worker.recognize(vinFile)
      await worker.terminate()

      const normalized = data.text.toUpperCase().replace(/[^A-Z0-9]/g, '')
      const match = normalized.match(/[A-HJ-NPR-Z0-9]{17}/)

      if (!match) {
        setOcrError('No VIN detected. Try a clearer photo or enter the VIN manually.')
        return
      }

      setVinText(match[0])
      await handleVinDecode(match[0])
    } catch (error) {
      console.error('OCR failed', error)
      setOcrError('Unable to read VIN from the image.')
    } finally {
      setOcrLoading(false)
    }
  }

  const saveDamageReport = async () => {
    if (!canSubmitJob) {
      setDamageStatus('Enter a workorder ID and job ID before saving damage reports.')
      return
    }

    try {
      setDamageStatus('Saving damage report...')
      await workorderService.createDamageReport(Number(workorderId), Number(jobId), {
        diagram_points: damagePoints,
        notes: damageNotes,
      })
      setDamageStatus('Damage report saved.')
    } catch (error) {
      console.error('Damage report failed', error)
      setDamageStatus('Unable to save damage report.')
    }
  }

  const uploadCheckpoint = async (type) => {
    if (!canSubmitJob) {
      setCheckpointMessage('Enter a workorder ID and job ID before uploading photos.')
      return
    }

    const file = checkpointFiles[type]
    if (!file) {
      setCheckpointMessage('Select a photo before uploading.')
      return
    }

    try {
      setCheckpointMessage(`Uploading ${type.replace('_', ' ')} photo...`)
      await workorderService.uploadJobCheckpoint(Number(workorderId), Number(jobId), type, file)
      setCheckpointMessage('Photo uploaded.')
      const status = await workorderService.getJobCheckpointStatus(Number(workorderId), Number(jobId))
      setCheckpointStatus(status.data)
    } catch (error) {
      console.error('Checkpoint upload failed', error)
      setCheckpointMessage('Unable to upload checkpoint photo.')
    }
  }

  const refreshCheckpointStatus = async () => {
    if (!canSubmitJob) return
    try {
      const status = await workorderService.getJobCheckpointStatus(Number(workorderId), Number(jobId))
      setCheckpointStatus(status.data)
    } catch (error) {
      console.error('Checkpoint status failed', error)
    }
  }

  const handleNavigate = () => {
    const lat = parseCoordinate(destinationLat)
    const lng = parseCoordinate(destinationLng)
    if (lat == null || lng == null) {
      setNavigationStatus('Enter valid latitude and longitude coordinates before navigating.')
      return
    }

    const links = buildMapLinks(mapProvider, lat, lng, isAndroidDevice())
    const useMobile = isMobileDevice()
    const url = useMobile ? links.deepLink : links.webLink

    setNavigationStatus(
      useMobile ? 'Opening navigation app…' : 'Opening web map in a new tab for desktop browsers.'
    )

    if (useMobile) {
      window.location.href = url
    } else {
      window.open(url, '_blank', 'noopener,noreferrer')
    }
  }

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold text-gray-900">Driver Job Intake</h1>
        <p className="mt-2 text-sm text-gray-600">
          Capture VIN, log vehicle damage, and upload photo checkpoints before moving job status.
        </p>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Input
          label="Workorder ID"
          placeholder="Enter workorder ID"
          modelValue={workorderId}
          onUpdateModelValue={setWorkorderId}
        />
        <Input label="Job ID" placeholder="Enter job ID" modelValue={jobId} onUpdateModelValue={setJobId} />
      </div>

      <section className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h2 className="text-lg font-semibold text-gray-900">Navigation</h2>
        <p className="mt-1 text-sm text-gray-600">
          Enter destination coordinates and launch turn-by-turn directions in your preferred map app.
        </p>

        <div className="mt-4 grid gap-4 md:grid-cols-3">
          <Input
            label="Latitude"
            placeholder="e.g. 34.0522"
            modelValue={destinationLat}
            onUpdateModelValue={setDestinationLat}
          />
          <Input
            label="Longitude"
            placeholder="e.g. -118.2437"
            modelValue={destinationLng}
            onUpdateModelValue={setDestinationLng}
          />
          <Select
            label="Map provider"
            modelValue={mapProvider}
            onUpdateModelValue={setMapProvider}
            placeholder=""
            options={[
              { label: 'Google Maps', value: 'google' },
              { label: 'Waze', value: 'waze' },
              { label: 'Apple Maps', value: 'apple' },
            ]}
          />
        </div>

        <div className="mt-4 flex flex-wrap items-center gap-3">
          <Button onClick={handleNavigate} disabled={!destinationLat || !destinationLng}>
            Navigate
          </Button>
          {navigationStatus ? <span className="text-sm text-gray-600">{navigationStatus}</span> : null}
        </div>
      </section>

      <section className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h2 className="text-lg font-semibold text-gray-900">VIN OCR & Vehicle Data</h2>
        <p className="mt-1 text-sm text-gray-600">
          Upload a VIN photo to auto-fill vehicle details or paste the VIN manually.
        </p>

        <div className="mt-4 grid gap-4 md:grid-cols-[1fr_auto]">
          <input
            type="file"
            accept="image/*"
            onChange={(event) => setVinFile(event.target.files?.[0] ?? null)}
            className="text-sm"
          />
          <Button onClick={runOcr} loading={ocrLoading} disabled={!vinFile}>
            Run OCR
          </Button>
        </div>

        {ocrError ? <p className="mt-2 text-sm text-red-600">{ocrError}</p> : null}
        {vinStatus ? <p className="mt-2 text-sm text-green-600">{vinStatus}</p> : null}

        <div className="mt-4 grid gap-4 md:grid-cols-2">
          <Input
            label="VIN"
            placeholder="Enter VIN"
            modelValue={vinText}
            onUpdateModelValue={(value) => {
              setVinText(value)
              setVehicleData((prev) => ({ ...prev, vin: value }))
            }}
          />
          <Button
            className="mt-6"
            variant="secondary"
            onClick={() => handleVinDecode(vinText)}
            disabled={!vinText || vinText.length < 17}
          >
            Decode VIN
          </Button>
        </div>

        {summaryItems.length ? (
          <div className="mt-4 grid gap-3 rounded-md bg-gray-50 p-4 text-sm text-gray-700 md:grid-cols-5">
            {summaryItems.map((item) => (
              <div key={item.label}>
                <p className="text-xs uppercase text-gray-500">{item.label}</p>
                <p className="mt-1 font-medium text-gray-900">{item.value}</p>
              </div>
            ))}
          </div>
        ) : null}
      </section>

      <section className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h2 className="text-lg font-semibold text-gray-900">Tap-to-Mark Damage</h2>
        <p className="mt-1 text-sm text-gray-600">
          Tap on the diagram to mark damage locations. Save the report to attach to the job.
        </p>

        <div className="mt-4">
          <DamageDiagram
            points={damagePoints}
            onAddPoint={(point) => setDamagePoints((prev) => [...prev, point])}
            onClear={() => setDamagePoints([])}
          />
        </div>

        <div className="mt-4">
          <Textarea
            label="Damage notes"
            placeholder="Describe observed damage"
            modelValue={damageNotes}
            onUpdateModelValue={setDamageNotes}
            rows={3}
          />
        </div>

        <div className="mt-4 flex flex-wrap items-center gap-3">
          <Button onClick={saveDamageReport} disabled={!damagePoints.length}>
            Save damage report
          </Button>
          {damageStatus ? <span className="text-sm text-gray-600">{damageStatus}</span> : null}
        </div>
      </section>

      <section className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h2 className="text-lg font-semibold text-gray-900">Photo Checkpoints</h2>
        <p className="mt-1 text-sm text-gray-600">
          Upload required pre-load, hookup, and drop-off photos to advance job status.
        </p>

        <div className="mt-4 space-y-4">
          {checkpointTypes.map((checkpoint) => (
            <div key={checkpoint.key} className="flex flex-wrap items-center gap-3">
              <input
                type="file"
                accept="image/*"
                onChange={(event) =>
                  setCheckpointFiles((prev) => ({
                    ...prev,
                    [checkpoint.key]: event.target.files?.[0] ?? null,
                  }))
                }
                className="text-sm"
              />
              <Button variant="secondary" onClick={() => uploadCheckpoint(checkpoint.key)}>
                Upload {checkpoint.label}
              </Button>
              {checkpointStatus?.checkpoints ? (
                <span className="text-xs text-gray-500">
                  {checkpointStatus.checkpoints[checkpoint.key] || 0} uploaded
                </span>
              ) : null}
            </div>
          ))}
        </div>

        <div className="mt-4 flex items-center gap-3">
          <Button variant="ghost" onClick={refreshCheckpointStatus} disabled={!canSubmitJob}>
            Refresh status
          </Button>
          {checkpointMessage ? <span className="text-sm text-gray-600">{checkpointMessage}</span> : null}
        </div>
      </section>
    </div>
  )
}
