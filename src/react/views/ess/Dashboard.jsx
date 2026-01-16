import { useNavigate } from 'react-router-dom'

import Card from '../../components/ui/Card'

const essCards = [
  {
    title: 'Time Clock',
    description: 'Clock in and out with location verification.',
    to: '/ess/time-clock',
    icon: (
      <svg className="mx-auto h-12 w-12 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
        />
      </svg>
    ),
  },
  {
    title: 'My Schedule',
    description: 'Review upcoming shifts and assignments.',
    to: '/ess/schedule',
    icon: (
      <svg className="mx-auto h-12 w-12 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
        />
      </svg>
    ),
  },
  {
    title: 'Pay History',
    description: 'Download PDF pay stubs and summaries.',
    to: '/ess/pay-history',
    icon: (
      <svg className="mx-auto h-12 w-12 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
        />
      </svg>
    ),
  },
  {
    title: 'Profile Updates',
    description: 'Keep your contact details up to date.',
    to: '/ess/profile',
    icon: (
      <svg className="mx-auto h-12 w-12 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
        />
      </svg>
    ),
  },
]

export default function EssDashboard() {
  const navigate = useNavigate()

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Employee Self-Service</h1>
        <p className="mt-1 text-sm text-gray-500">
          Manage your time, schedule, and pay information in one place.
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {essCards.map((card) => (
          <Card key={card.title} hover onClick={() => navigate(card.to)}>
            <div className="text-center py-6">
              {card.icon}
              <h3 className="mt-4 text-lg font-medium text-gray-900">{card.title}</h3>
              <p className="mt-1 text-sm text-gray-500">{card.description}</p>
            </div>
          </Card>
        ))}
      </div>
    </div>
  )
}
