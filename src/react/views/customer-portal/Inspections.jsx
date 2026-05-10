// @deprecated Phase 2a — frozen for legacy `customer` role. New portal lives at src/react/views/portal/*.
import { useCallback, useEffect, useState } from 'react'

import inspectionService from '../../../services/inspection.service'

export default function Inspections() {
  const [inspections, setInspections] = useState([])
  const [activeReport, setActiveReport] = useState(null)
  const [error, setError] = useState('')

  const loadInspections = useCallback(async () => {
    try {
      const response = await inspectionService.customerList()
      setInspections(response || [])
    } catch (err) {
      console.error(err)
      setError('Unable to load inspections')
    }
  }, [])

  const openDetail = useCallback(async (id) => {
    try {
      const response = await inspectionService.customerShow(id)
      setActiveReport(response)
    } catch (err) {
      console.error(err)
      setError(err.response?.data?.message || 'Unable to load inspection')
    }
  }, [])

  useEffect(() => {
    loadInspections()
  }, [loadInspections])

  return (
    <div className="p-6 space-y-4">
      <h1 className="text-2xl font-semibold">My Inspections</h1>
      <p className="text-sm text-gray-600">View inspections shared with your account.</p>

      <div className="bg-white rounded shadow">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-2 text-left text-sm font-semibold text-gray-700">ID</th>
              <th className="px-4 py-2 text-left text-sm font-semibold text-gray-700">Template</th>
              <th className="px-4 py-2 text-left text-sm font-semibold text-gray-700">Status</th>
              <th className="px-4 py-2 text-left text-sm font-semibold text-gray-700">Completed</th>
              <th className="px-4 py-2 text-left text-sm font-semibold text-gray-700">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200">
            {inspections.map((inspection) => (
              <tr key={inspection.id}>
                <td className="px-4 py-2">{inspection.id}</td>
                <td className="px-4 py-2">{inspection.template_id}</td>
                <td className="px-4 py-2">{inspection.status}</td>
                <td className="px-4 py-2">{inspection.completed_at || 'Pending'}</td>
                <td className="px-4 py-2">
                  <button className="text-indigo-600" type="button" onClick={() => openDetail(inspection.id)}>
                    View
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {activeReport ? (
        <div className="p-4 bg-white rounded shadow">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold">Inspection #{activeReport.report.id}</h2>
            <button className="text-sm text-gray-600" type="button" onClick={() => setActiveReport(null)}>
              Close
            </button>
          </div>
          <p className="text-sm text-gray-600">Status: {activeReport.report.status}</p>
          <div className="mt-2 space-y-2">
            {activeReport.items.map((item) => (
              <div key={item.id} className="p-2 border rounded">
                <p className="font-semibold">{item.label}</p>
                <p className="text-sm">Response: {item.response}</p>
                {item.note ? <p className="text-sm text-gray-600">Note: {item.note}</p> : null}
              </div>
            ))}
          </div>
          <div className="mt-3">
            <p className="font-semibold">Media</p>
            <div className="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {activeReport.media.map((media) => (
                <div key={media.id} className="border rounded-lg p-2">
                  {media.type === 'video' ? (
                    <video className="w-full h-40 rounded object-cover" controls preload="metadata">
                      <source src={media.path} type={media.mime_type} />
                    </video>
                  ) : (
                    <img src={media.path} alt="Inspection media" className="w-full h-40 rounded object-cover" />
                  )}
                  <a href={media.path} className="mt-2 block text-xs text-indigo-600" target="_blank" rel="noreferrer">
                    Open {media.type}
                  </a>
                </div>
              ))}
            </div>
          </div>
        </div>
      ) : null}

      {error ? <p className="text-sm text-red-600">{error}</p> : null}
    </div>
  )
}
