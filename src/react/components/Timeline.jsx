import Badge from './ui/Badge'

const iconStyles = {
  status: 'bg-blue-500',
  message: 'bg-indigo-500',
  note: 'bg-slate-500',
  photo: 'bg-emerald-500',
  approval: 'bg-purple-500',
  estimate: 'bg-amber-500',
}

const iconPaths = {
  status: 'M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z',
  message: 'M8 10h8M8 14h4M21 12c0 4.418-4.03 8-9 8a9.77 9.77 0 01-4.39-1.02L3 20l1.28-3.2A7.42 7.42 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
  note: 'M7 4h10a2 2 0 012 2v12l-4-2-4 2-4-2-4 2V6a2 2 0 012-2zm2 5h6M9 13h4',
  photo: 'M4 5a2 2 0 012-2h12a2 2 0 012 2v10a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm4 7l2-2 3 3 2-2 3 3',
  approval: 'M9 12l2 2 4-4m5 2a9 9 0 11-18 0 9 9 0 0118 0z',
  estimate: 'M9 7h6m-6 4h6m-6 4h6',
}

const formatDateTime = (date) => {
  if (!date) return ''
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(date))
}

const renderMeta = (event) => {
  if (event.type === 'message' && event.meta?.sender_role) {
    return <Badge variant="secondary">Message</Badge>
  }
  if (event.type === 'note') {
    return <Badge variant="secondary">Internal</Badge>
  }
  if (event.type === 'photo' && event.meta?.category) {
    return <Badge variant="secondary">{event.meta.category}</Badge>
  }
  if (event.type === 'approval') {
    return <Badge variant="success">Approval</Badge>
  }
  if (event.type === 'estimate') {
    return <Badge variant="info">Estimate</Badge>
  }
  if (event.type === 'status') {
    return <Badge variant="info">Status</Badge>
  }
  return null
}

export default function Timeline({ events }) {
  if (!events?.length) {
    return <div className="text-center py-4 text-gray-500">No activity yet</div>
  }

  return (
    <div className="flow-root">
      <ul role="list" className="-mb-8">
        {events.map((event, idx) => (
          <li key={event.id}>
            <div className="relative pb-8">
              {idx !== events.length - 1 ? (
                <span
                  className="absolute left-4 top-4 -ml-px h-full w-0.5 bg-gray-200"
                  aria-hidden="true"
                />
              ) : null}
              <div className="relative flex space-x-3">
                <div>
                  <span
                    className={`h-8 w-8 rounded-full flex items-center justify-center ring-8 ring-white ${
                      iconStyles[event.type] || 'bg-gray-400'
                    }`}
                  >
                    <svg className="h-4 w-4 text-white" fill="currentColor" viewBox="0 0 24 24">
                      <path d={iconPaths[event.type] || iconPaths.status} />
                    </svg>
                  </span>
                </div>
                <div className="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                  <div className="space-y-2">
                    <div className="flex items-center gap-2">
                      <p className="text-sm font-medium text-gray-900">{event.title}</p>
                      {renderMeta(event)}
                    </div>
                    {event.description ? (
                      <p className="text-sm text-gray-600 whitespace-pre-line">{event.description}</p>
                    ) : null}
                    {event.type === 'photo' && event.meta?.file_path ? (
                      <img
                        src={event.meta.file_path}
                        alt={event.meta?.job_title || 'Workorder photo'}
                        className="mt-2 h-32 w-48 rounded-lg border border-gray-200 object-cover"
                      />
                    ) : null}
                  </div>
                  <div className="whitespace-nowrap text-right text-sm text-gray-500">
                    {formatDateTime(event.created_at)}
                  </div>
                </div>
              </div>
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
