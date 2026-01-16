import { useMemo, useState } from 'react'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'

const formatTime = (value) => new Intl.DateTimeFormat('en-US', {
  hour: 'numeric',
  minute: '2-digit',
}).format(value)

const formatDate = (value) => new Intl.DateTimeFormat('en-US', {
  month: 'short',
  day: 'numeric',
  year: 'numeric',
}).format(value)

export default function TimeClock() {
  const [isClockedIn, setIsClockedIn] = useState(false)
  const [statusMessage, setStatusMessage] = useState('You are currently off the clock.')
  const [locationState, setLocationState] = useState({
    status: 'idle',
    coords: null,
    error: null,
    capturedAt: null,
  })
  const [entries, setEntries] = useState([
    {
      id: 1,
      type: 'Clock Out',
      time: new Date(Date.now() - 1000 * 60 * 60 * 2),
      location: 'Main Shop - Bay 2',
    },
    {
      id: 2,
      type: 'Clock In',
      time: new Date(Date.now() - 1000 * 60 * 60 * 6),
      location: 'Main Shop - Bay 2',
    },
  ])

  const locationLabel = useMemo(() => {
    if (locationState.status === 'locating') {
      return 'Locating...'
    }

    if (locationState.status === 'error') {
      return locationState.error || 'Unable to access location.'
    }

    if (locationState.status === 'success' && locationState.coords) {
      const { latitude, longitude, accuracy } = locationState.coords
      return `Lat ${latitude.toFixed(5)}, Lng ${longitude.toFixed(5)} (±${Math.round(accuracy)}m)`
    }

    return 'No location captured yet.'
  }, [locationState])

  const handleCaptureLocation = () => {
    if (!navigator.geolocation) {
      setLocationState({
        status: 'error',
        coords: null,
        error: 'Geolocation is not supported on this device.',
        capturedAt: null,
      })
      return
    }

    setLocationState((prev) => ({
      ...prev,
      status: 'locating',
      error: null,
    }))

    navigator.geolocation.getCurrentPosition(
      (position) => {
        setLocationState({
          status: 'success',
          coords: position.coords,
          error: null,
          capturedAt: new Date(),
        })
      },
      (error) => {
        setLocationState({
          status: 'error',
          coords: null,
          error: error.message || 'Unable to access location.',
          capturedAt: null,
        })
      },
      {
        enableHighAccuracy: true,
        timeout: 10000,
      }
    )
  }

  const addEntry = (type) => {
    const time = new Date()
    const location = locationState.status === 'success'
      ? `Lat ${locationState.coords.latitude.toFixed(4)}, Lng ${locationState.coords.longitude.toFixed(4)}`
      : 'Location pending'

    setEntries((prev) => [
      {
        id: prev.length + 1,
        type,
        time,
        location,
      },
      ...prev,
    ])
  }

  const handleClockIn = () => {
    setIsClockedIn(true)
    setStatusMessage('Clocked in. Have a great shift!')
    addEntry('Clock In')
  }

  const handleClockOut = () => {
    setIsClockedIn(false)
    setStatusMessage('Clocked out. Enjoy your time off!')
    addEntry('Clock Out')
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Time Clock</h1>
        <p className="mt-1 text-sm text-gray-500">
          Use geolocation to verify your worksite when clocking in or out.
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <Card className="lg:col-span-2">
          <div className="space-y-4">
            <div>
              <p className="text-sm text-gray-500">Status</p>
              <p className="text-lg font-semibold text-gray-900">
                {isClockedIn ? 'Clocked In' : 'Clocked Out'}
              </p>
              <p className="text-sm text-gray-500">{statusMessage}</p>
            </div>

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
              <Button
                type="button"
                variant={isClockedIn ? 'secondary' : 'primary'}
                onClick={handleClockIn}
                disabled={isClockedIn}
              >
                Clock In
              </Button>
              <Button
                type="button"
                variant={isClockedIn ? 'danger' : 'secondary'}
                onClick={handleClockOut}
                disabled={!isClockedIn}
              >
                Clock Out
              </Button>
              <Button type="button" variant="outline" onClick={handleCaptureLocation}>
                Capture Location
              </Button>
            </div>

            <div className="rounded-lg border border-dashed border-gray-200 p-4">
              <div className="flex flex-col gap-1">
                <span className="text-xs uppercase tracking-wide text-gray-400">Location status</span>
                <span className="text-sm font-medium text-gray-700">{locationLabel}</span>
                {locationState.capturedAt ? (
                  <span className="text-xs text-gray-500">
                    Captured {formatDate(locationState.capturedAt)} at {formatTime(locationState.capturedAt)}
                  </span>
                ) : null}
              </div>
            </div>
          </div>
        </Card>

        <Card title="Shift Summary">
          <div className="space-y-3">
            <div>
              <p className="text-sm text-gray-500">Current shift</p>
              <p className="text-base font-semibold text-gray-900">Main Shop - Technician</p>
              <p className="text-xs text-gray-500">Scheduled 8:00 AM - 5:00 PM</p>
            </div>
            <div>
              <p className="text-sm text-gray-500">Supervisor</p>
              <p className="text-base font-medium text-gray-900">Dana Lopez</p>
            </div>
            <div>
              <p className="text-sm text-gray-500">On-site requirement</p>
              <p className="text-xs text-gray-500">Location must be captured within 5 minutes of clocking.</p>
            </div>
          </div>
        </Card>
      </div>

      <Card title="Recent punches">
        <div className="space-y-4">
          {entries.map((entry) => (
            <div key={entry.id} className="flex flex-col gap-1 border-b border-gray-100 pb-4 last:border-b-0 last:pb-0">
              <div className="flex items-center justify-between">
                <span className="text-sm font-semibold text-gray-900">{entry.type}</span>
                <span className="text-sm text-gray-500">
                  {formatDate(entry.time)} · {formatTime(entry.time)}
                </span>
              </div>
              <p className="text-sm text-gray-500">{entry.location}</p>
            </div>
          ))}
        </div>
      </Card>
    </div>
  )
}
