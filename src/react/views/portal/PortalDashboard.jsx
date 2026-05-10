import { Link } from 'react-router-dom'

import { usePortalAuth } from '../../stores/portalAuth'
import Card from '../../components/ui/Card'

/**
 * Phase 2a — placeholder home for the new portal tree.
 *
 * Shows the surfaces that 2b/2c will fill. Each card links to a route that
 * is currently a Soon stub; no live data is fetched here yet. The point is
 * to give a working, navigable shell so the foundation work can be
 * verified end-to-end before the surface phases land.
 */
const SURFACES = [
  {
    to: '/p/approvals',
    title: 'Approvals',
    description: 'Review and approve pending estimates and contracts.',
  },
  {
    to: '/p/requests',
    title: 'New request',
    description: 'Submit a support, maintenance, or service request.',
  },
  {
    to: '/p/invoices',
    title: 'Invoices',
    description: 'View invoices, download PDFs, and pay online.',
  },
  {
    to: '/p/payment-methods',
    title: 'Payment methods',
    description: 'Save cards and accounts for faster checkout.',
  },
  {
    to: '/p/sites',
    title: 'Sites',
    description: 'View locations and installed assets at each site.',
  },
  {
    to: '/p/work-orders',
    title: 'Work orders',
    description: 'Track active and completed work at your sites.',
  },
  {
    to: '/p/contracts',
    title: 'Contracts',
    description: 'Review service agreements and SLA performance.',
  },
  {
    to: '/p/messages',
    title: 'Messages',
    description: 'Communicate with the team about your work.',
  },
]

export default function PortalDashboard() {
  const { user, account } = usePortalAuth()

  return (
    <div className="space-y-8">
      <header>
        <h1 className="text-2xl font-semibold">
          Welcome{user?.name ? `, ${user.name.split(' ')[0]}` : ''}
        </h1>
        <p className="text-sm text-gray-600 mt-1">
          {account
            ? `Company #${account.company_id}${
                account.allowed_site_ids ? ` · ${account.allowed_site_ids.length} site(s)` : ' · all sites'
              }`
            : 'Loading account…'}
        </p>
      </header>

      <section>
        <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500 mb-3">
          What would you like to do?
        </h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {SURFACES.map((s) => (
            <Link key={s.to} to={s.to} className="block group">
              <Card className="h-full hover:shadow-md transition-shadow">
                <h3
                  className="text-base font-semibold group-hover:underline"
                  style={{ color: 'var(--portal-primary, #2563eb)' }}
                >
                  {s.title}
                </h3>
                <p className="text-sm text-gray-600 mt-1">{s.description}</p>
              </Card>
            </Link>
          ))}
        </div>
      </section>
    </div>
  )
}
