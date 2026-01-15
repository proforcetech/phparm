import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { ClipboardDocumentCheckIcon, MapPinIcon } from '@heroicons/react/24/outline'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'

const vehicleRecords = [
  {
    id: 1,
    plate: '8HJK921',
    vin: '1FTFW1E52MFC12345',
    vehicle: '2021 Ford F-150',
    color: 'Silver',
    location: 'Row A - Spot 12',
    status: 'Stored',
  },
  {
    id: 2,
    plate: '4LTX208',
    vin: '2HGFC2F69NH223344',
    vehicle: '2022 Honda Civic',
    color: 'Blue',
    location: 'Row B - Spot 07',
    status: 'Hold Release',
  },
  {
    id: 3,
    plate: '9KDA144',
    vin: '1G1ZD5ST9MF556677',
    vehicle: '2021 Chevrolet Malibu',
    color: 'Black',
    location: 'Row C - Spot 02',
    status: 'Pending Auction',
  },
]

const formatPlate = (plate) => plate.trim().toUpperCase()

const buildDiscrepancy = ({ plate, vehicle, issue, severity, location }) => ({
  id: `${plate}-${Date.now()}`,
  plate,
  vehicle,
  issue,
  severity,
  location,
  reportedAt: new Date().toLocaleString(),
})

const buildFollowUp = ({ plate, task, due, priority }) => ({
  id: `${plate}-${Date.now()}`,
  plate,
  task,
  due,
  priority,
})

export default function InventorySpotChecks() {
  const [scanPlate, setScanPlate] = useState('')
  const [scanLocation, setScanLocation] = useState('')
  const [lastResult, setLastResult] = useState(null)
  const [scanHistory, setScanHistory] = useState([])
  const [discrepancies, setDiscrepancies] = useState([])
  const [followUps, setFollowUps] = useState([])

  const handleScanSubmit = (event) => {
    event.preventDefault()
    const normalizedPlate = formatPlate(scanPlate)
    if (!normalizedPlate) {
      return
    }

    const matchedRecord = vehicleRecords.find((record) => record.plate === normalizedPlate)
    const normalizedLocation = scanLocation.trim()
    const timestamp = new Date().toLocaleString()

    if (!matchedRecord) {
      const discrepancy = buildDiscrepancy({
        plate: normalizedPlate,
        vehicle: 'Unknown vehicle',
        issue: 'Plate not found in stored records.',
        severity: 'High',
        location: normalizedLocation || 'Unknown location',
      })
      const followUp = buildFollowUp({
        plate: normalizedPlate,
        task: 'Investigate unregistered vehicle in yard.',
        due: 'Today',
        priority: 'Urgent',
      })

      setDiscrepancies((prev) => [discrepancy, ...prev])
      setFollowUps((prev) => [followUp, ...prev])
      setLastResult({
        plate: normalizedPlate,
        status: 'Not Found',
        detail: 'No stored vehicle record matched this plate.',
        timestamp,
      })
      setScanHistory((prev) => [
        {
          id: `${normalizedPlate}-${Date.now()}`,
          plate: normalizedPlate,
          status: 'Not Found',
          location: normalizedLocation || 'Unknown',
          time: timestamp,
        },
        ...prev,
      ])
      setScanPlate('')
      return
    }

    const locationMatches = !normalizedLocation || normalizedLocation === matchedRecord.location
    const status = locationMatches ? 'Verified' : 'Location Mismatch'
    const detail = locationMatches
      ? `${matchedRecord.vehicle} verified in ${matchedRecord.location}.`
      : `Expected ${matchedRecord.location}, found ${normalizedLocation || 'unknown location'}.`

    if (!locationMatches) {
      const discrepancy = buildDiscrepancy({
        plate: matchedRecord.plate,
        vehicle: matchedRecord.vehicle,
        issue: 'Vehicle parked outside assigned storage location.',
        severity: 'Medium',
        location: normalizedLocation || 'Unknown location',
      })
      const followUp = buildFollowUp({
        plate: matchedRecord.plate,
        task: 'Confirm relocation and update yard map.',
        due: 'Within 24 hours',
        priority: 'High',
      })
      setDiscrepancies((prev) => [discrepancy, ...prev])
      setFollowUps((prev) => [followUp, ...prev])
    }

    setLastResult({
      plate: matchedRecord.plate,
      status,
      detail,
      timestamp,
    })
    setScanHistory((prev) => [
      {
        id: `${matchedRecord.plate}-${Date.now()}`,
        plate: matchedRecord.plate,
        status,
        location: normalizedLocation || matchedRecord.location,
        time: timestamp,
      },
      ...prev,
    ])
    setScanPlate('')
    setScanLocation('')
  }

  const rosterStatus = useMemo(() => {
    const statusByPlate = new Map()
    scanHistory.forEach((scan) => {
      if (!statusByPlate.has(scan.plate)) {
        statusByPlate.set(scan.plate, scan.status)
      }
    })
    return statusByPlate
  }, [scanHistory])

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Inventory Spot-Checks</h1>
          <p className="text-sm text-gray-500">Scan plates on mobile, verify against stored records, and flag discrepancies.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to="/cp/storage/impound-intake">
            <Button variant="secondary">Impound Intake</Button>
          </Link>
          <Link to="/cp/storage/ledger">
            <Button variant="secondary">Fee Ledger</Button>
          </Link>
          <Link to="/cp/storage/notices">
            <Button variant="secondary">Notice Generation</Button>
          </Link>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
        <div className="space-y-6">
          <Card>
            <form className="space-y-4" onSubmit={handleScanSubmit}>
              <div className="flex items-center gap-2 text-sm font-medium text-primary-700">
                <ClipboardDocumentCheckIcon className="h-5 w-5" />
                Mobile Plate Scan
              </div>
              <Input
                label="License plate"
                modelValue={scanPlate}
                placeholder="Scan or enter plate"
                onUpdateModelValue={setScanPlate}
                helperText="Use the device camera scanner or type the plate manually."
              />
              <Input
                label="Physical location"
                modelValue={scanLocation}
                placeholder="Row B - Spot 07"
                icon={MapPinIcon}
                onUpdateModelValue={setScanLocation}
              />
              <div className="flex flex-wrap items-center gap-3">
                <Button type="submit">Verify Plate</Button>
                <Button type="button" variant="secondary">Launch Camera Scan</Button>
              </div>
            </form>
          </Card>

          <Card>
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-lg font-semibold text-gray-900">Last Scan Result</h2>
                <p className="text-sm text-gray-500">Immediate comparison against the stored vehicle record.</p>
              </div>
              {lastResult ? (
                <Badge
                  variant={lastResult.status === 'Verified' ? 'success' : lastResult.status === 'Location Mismatch' ? 'warning' : 'danger'}
                >
                  {lastResult.status}
                </Badge>
              ) : null}
            </div>
            {lastResult ? (
              <div className="mt-4 space-y-2 text-sm text-gray-600">
                <p><span className="font-medium text-gray-900">Plate:</span> {lastResult.plate}</p>
                <p><span className="font-medium text-gray-900">Detail:</span> {lastResult.detail}</p>
                <p><span className="font-medium text-gray-900">Timestamp:</span> {lastResult.timestamp}</p>
              </div>
            ) : (
              <p className="mt-4 text-sm text-gray-500">Scan a plate to populate the comparison details.</p>
            )}
          </Card>

          <Card>
            <div className="mb-4">
              <h2 className="text-lg font-semibold text-gray-900">Stored Vehicle Roster</h2>
              <p className="text-sm text-gray-500">Track which stored vehicles have been physically verified today.</p>
            </div>
            <div className="space-y-3">
              {vehicleRecords.map((record) => {
                const status = rosterStatus.get(record.plate) || 'Pending'
                const badgeVariant = status === 'Verified'
                  ? 'success'
                  : status === 'Location Mismatch'
                    ? 'warning'
                    : status === 'Not Found'
                      ? 'danger'
                      : 'secondary'
                return (
                  <div key={record.id} className="rounded-lg border border-gray-200 p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div>
                        <p className="font-semibold text-gray-900">{record.plate}</p>
                        <p className="text-sm text-gray-500">{record.vehicle} • {record.color}</p>
                      </div>
                      <Badge variant={badgeVariant}>{status}</Badge>
                    </div>
                    <div className="mt-2 text-sm text-gray-600">
                      <p><span className="font-medium text-gray-800">Expected Location:</span> {record.location}</p>
                      <p><span className="font-medium text-gray-800">Status:</span> {record.status}</p>
                      <p><span className="font-medium text-gray-800">VIN:</span> {record.vin}</p>
                    </div>
                  </div>
                )
              })}
            </div>
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <div className="mb-4">
              <h2 className="text-lg font-semibold text-gray-900">Discrepancy Log</h2>
              <p className="text-sm text-gray-500">Automatically logged when scans do not align with records.</p>
            </div>
            {discrepancies.length ? (
              <div className="space-y-3">
                {discrepancies.map((item) => (
                  <div key={item.id} className="rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-900">
                    <div className="flex items-center justify-between">
                      <p className="font-semibold">{item.plate}</p>
                      <Badge variant="danger">{item.severity}</Badge>
                    </div>
                    <p className="mt-1">{item.issue}</p>
                    <p className="mt-1 text-xs text-red-700">{item.vehicle} • {item.location}</p>
                    <p className="mt-1 text-xs text-red-700">Reported {item.reportedAt}</p>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-sm text-gray-500">No discrepancies logged yet.</p>
            )}
          </Card>

          <Card>
            <div className="mb-4">
              <h2 className="text-lg font-semibold text-gray-900">Follow-Up Tasks</h2>
              <p className="text-sm text-gray-500">Tasks created for yard staff based on scan outcomes.</p>
            </div>
            {followUps.length ? (
              <div className="space-y-3">
                {followUps.map((task) => (
                  <div key={task.id} className="rounded-lg border border-gray-200 p-3 text-sm text-gray-700">
                    <div className="flex items-center justify-between">
                      <p className="font-semibold text-gray-900">{task.plate}</p>
                      <Badge variant={task.priority === 'Urgent' ? 'danger' : 'warning'}>{task.priority}</Badge>
                    </div>
                    <p className="mt-1">{task.task}</p>
                    <p className="mt-1 text-xs text-gray-500">Due: {task.due}</p>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-sm text-gray-500">No follow-ups created yet.</p>
            )}
          </Card>

          <Card>
            <div className="mb-4">
              <h2 className="text-lg font-semibold text-gray-900">Recent Scan History</h2>
              <p className="text-sm text-gray-500">Chronological record of plate scans.</p>
            </div>
            {scanHistory.length ? (
              <div className="space-y-3">
                {scanHistory.slice(0, 6).map((scan) => (
                  <div key={scan.id} className="rounded-lg border border-gray-200 p-3 text-sm">
                    <div className="flex items-center justify-between">
                      <p className="font-semibold text-gray-900">{scan.plate}</p>
                      <Badge
                        variant={scan.status === 'Verified' ? 'success' : scan.status === 'Location Mismatch' ? 'warning' : 'danger'}
                      >
                        {scan.status}
                      </Badge>
                    </div>
                    <p className="mt-1 text-gray-600">{scan.location}</p>
                    <p className="mt-1 text-xs text-gray-500">{scan.time}</p>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-sm text-gray-500">No scans recorded yet.</p>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}
