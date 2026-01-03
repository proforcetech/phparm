import { useEffect, useMemo, useState } from 'react'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import appointmentService from '../../../services/appointment.service'

const dayLabels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

const normalizeHour = (hour, index) => ({
  day_of_week: hour.day_of_week ?? index,
  opens_at: hour.opens_at ?? '08:00',
  closes_at: hour.closes_at ?? '17:00',
  slot_minutes: hour.slot_minutes ?? 30,
  buffer_minutes: hour.buffer_minutes ?? 0,
  is_closed: Boolean(hour.is_closed),
})

const defaultHours = () =>
  dayLabels.map((_, index) =>
    normalizeHour(
      {
        day_of_week: index,
        opens_at: '08:00',
        closes_at: '17:00',
        slot_minutes: 30,
        buffer_minutes: 0,
        is_closed: index === 0,
      },
      index
    )
  )

export default function AvailabilitySettings() {
  const [saving, setSaving] = useState(false)
  const [hours, setHours] = useState([])
  const [holidays, setHolidays] = useState([])

  const hydrate = async () => {
    const response = await appointmentService.fetchAvailabilityConfig()
    const data = response.data
    const normalizedHours = (data.hours?.length ? data.hours : defaultHours()).map(normalizeHour)
    setHours(normalizedHours)
    setHolidays(data.holidays || [])
  }

  const resetToDefaults = () => {
    setHours(defaultHours())
  }

  const save = async () => {
    setSaving(true)
    try {
      const payloadHours = hours.map((hour) => ({
        ...hour,
        is_closed: Number(Boolean(hour.is_closed)),
      }))
      await appointmentService.saveAvailabilityConfig({ hours: payloadHours, holidays: [...holidays] })
    } finally {
      setSaving(false)
    }
  }

  const updateHour = (index, updates) => {
    setHours((prev) => prev.map((row, rowIndex) => (rowIndex === index ? { ...row, ...updates } : row)))
  }

  const addHoliday = () => {
    setHolidays((prev) => [...prev, { holiday_date: '', label: '' }])
  }

  const removeHoliday = (index) => {
    setHolidays((prev) => prev.filter((_, itemIndex) => itemIndex !== index))
  }

  const holidayRows = useMemo(() => holidays, [holidays])

  useEffect(() => {
    hydrate()
  }, [])

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Availability Settings</h1>
          <p className="mt-1 text-sm text-gray-500">Configure weekly hours, slot lengths, and holiday closures.</p>
        </div>
        <Button loading={saving} onClick={save}>
          Save Settings
        </Button>
      </div>

      <Card className="mb-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-gray-900">Weekly Hours</h2>
          <Button variant="ghost" loading={saving} onClick={resetToDefaults}>
            Reset to defaults
          </Button>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Day</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Open</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Close</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Slot (mins)</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Buffer (mins)</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Closed</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {hours.map((row, index) => (
                <tr key={row.day_of_week}>
                  <td className="px-4 py-2 text-sm text-gray-900">{dayLabels[row.day_of_week]}</td>
                  <td className="px-4 py-2">
                    <Input
                      modelValue={row.opens_at}
                      type="time"
                      disabled={!!row.is_closed}
                      onUpdateModelValue={(value) => updateHour(index, { opens_at: value })}
                    />
                  </td>
                  <td className="px-4 py-2">
                    <Input
                      modelValue={row.closes_at}
                      type="time"
                      disabled={!!row.is_closed}
                      onUpdateModelValue={(value) => updateHour(index, { closes_at: value })}
                    />
                  </td>
                  <td className="px-4 py-2">
                    <Input
                      modelValue={row.slot_minutes}
                      type="number"
                      min="5"
                      onUpdateModelValue={(value) => updateHour(index, { slot_minutes: Number(value) })}
                    />
                  </td>
                  <td className="px-4 py-2">
                    <Input
                      modelValue={row.buffer_minutes}
                      type="number"
                      min="0"
                      onUpdateModelValue={(value) => updateHour(index, { buffer_minutes: Number(value) })}
                    />
                  </td>
                  <td className="px-4 py-2">
                    <label className="inline-flex items-center gap-2 text-sm text-gray-700">
                      <input
                        checked={row.is_closed}
                        type="checkbox"
                        onChange={(event) => updateHour(index, { is_closed: event.target.checked })}
                        className="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                      />
                      Closed
                    </label>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      <Card>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-gray-900">Holiday Closures</h2>
          <Button variant="ghost" size="sm" onClick={addHoliday}>
            Add holiday
          </Button>
        </div>
        <div className="space-y-3">
          {holidayRows.map((holiday, index) => (
            <div
              key={`${holiday.holiday_date}-${index}`}
              className="flex flex-col md:flex-row md:items-center md:space-x-3 space-y-2 md:space-y-0 border border-gray-200 rounded-lg p-3"
            >
              <div className="flex-1">
                <label className="block text-xs font-medium text-gray-700">Date</label>
                <Input
                  modelValue={holiday.holiday_date}
                  type="date"
                  className="mt-1"
                  onUpdateModelValue={(value) =>
                    setHolidays((prev) =>
                      prev.map((item, itemIndex) =>
                        itemIndex === index ? { ...item, holiday_date: value } : item
                      )
                    )
                  }
                />
              </div>
              <div className="flex-1">
                <label className="block text-xs font-medium text-gray-700">Label</label>
                <Input
                  modelValue={holiday.label}
                  className="mt-1"
                  placeholder="Holiday name"
                  onUpdateModelValue={(value) =>
                    setHolidays((prev) =>
                      prev.map((item, itemIndex) =>
                        itemIndex === index ? { ...item, label: value } : item
                      )
                    )
                  }
                />
              </div>
              <div className="flex items-center">
                <Button variant="ghost" size="sm" onClick={() => removeHoliday(index)}>
                  Remove
                </Button>
              </div>
            </div>
          ))}
          {!holidays.length ? <p className="text-sm text-gray-500">No holidays defined.</p> : null}
        </div>
      </Card>
    </div>
  )
}
