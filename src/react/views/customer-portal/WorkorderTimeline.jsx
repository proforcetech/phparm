// @deprecated Phase 2a — frozen for legacy `customer` role. New portal lives at src/react/views/portal/*.
import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import Timeline from '../../components/Timeline'
import workorderService from '../../../services/workorder.service'

export default function WorkorderTimeline() {
  const { id } = useParams()
  const [workorder, setWorkorder] = useState(null)
  const [timelineEvents, setTimelineEvents] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const loadTimeline = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [workorderResponse, timelineResponse] = await Promise.all([
        workorderService.getWorkorder(id),
        workorderService.getTimeline(id),
      ])
      setWorkorder(workorderResponse.data)
      setTimelineEvents(timelineResponse.data?.timeline || [])
    } catch (loadError) {
      console.error('Failed to load workorder timeline:', loadError)
      setError(loadError.response?.data?.message || 'Unable to load timeline')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    loadTimeline()
  }, [loadTimeline])

  if (loading) {
    return (
      <div className="flex justify-center py-10">
        <Loading text="Loading timeline..." />
      </div>
    )
  }

  if (error) {
    return (
      <Card>
        <div className="text-center py-10">
          <p className="text-sm text-red-600">{error}</p>
          <Link to="/portal/workorders" className="text-sm text-blue-600 hover:text-blue-700 mt-4 inline-block">
            Back to communication hub
          </Link>
        </div>
      </Card>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <Link to="/portal/workorders" className="text-sm text-blue-600 hover:text-blue-700">
          ← Back to communication hub
        </Link>
        <h1 className="mt-2 text-2xl font-bold text-gray-900">Workorder {workorder?.number}</h1>
        <p className="mt-1 text-sm text-gray-500">
          Follow messages, approvals, and photo updates from your service team.
        </p>
      </div>

      <Card>
        <Timeline events={timelineEvents} />
      </Card>
    </div>
  )
}
