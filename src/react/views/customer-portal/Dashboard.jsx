// @deprecated Phase 2a — frozen for legacy `customer` role. New portal lives at src/react/views/portal/*.
import { useNavigate } from 'react-router-dom'

import Card from '../../components/ui/Card'

const portalCards = [
  {
    title: 'Submit a Request',
    description: 'Open a service ticket or report an IT issue',
    to: '/portal/request',
    icon: (
      <svg className="mx-auto h-12 w-12 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75l-7-12a2 2 0 00-3.7 0l-7 12A2 2 0 005 19z"
        />
      </svg>
    ),
  },
  {
    title: 'My Invoices',
    description: 'View and pay invoices',
    to: '/portal/invoices',
    icon: (
      <svg className="mx-auto h-12 w-12 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
    title: 'Appointments',
    description: 'Schedule and manage appointments',
    to: '/portal/appointments',
    icon: (
      <svg className="mx-auto h-12 w-12 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
    title: 'My Vehicles',
    description: 'View vehicle service history',
    to: '/portal/vehicles',
    icon: (
      <svg className="mx-auto h-12 w-12 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
      </svg>
    ),
  },
  {
    title: 'Communication Hub',
    description: 'See messages, approvals, and photos',
    to: '/portal/workorders',
    icon: (
      <svg className="mx-auto h-12 w-12 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
        />
      </svg>
    ),
  },
  {
    title: 'Warranty Claims',
    description: 'Submit and follow claim conversations',
    to: '/portal/warranty-claims',
    icon: (
      <svg className="mx-auto h-12 w-12 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M9 12h6m-6 4h6M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
        />
      </svg>
    ),
  },
  {
    title: 'Credit Account',
    description: 'Check balance and submit payments',
    to: '/portal/credit',
    icon: (
      <svg className="mx-auto h-12 w-12 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M12 8c-2.21 0-4 1.343-4 3s1.79 3 4 3 4-1.343 4-3M4 12c0 3.866 3.582 7 8 7s8-3.134 8-7-3.582-7-8-7-8 3.134-8 7z"
        />
      </svg>
    ),
  },
  {
    title: 'My Profile',
    description: 'Update your information',
    to: '/portal/profile',
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

export default function Dashboard() {
  const navigate = useNavigate()

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Customer Portal</h1>
        <p className="mt-1 text-sm text-gray-500">Welcome to your customer portal</p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {portalCards.map((card) => (
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
