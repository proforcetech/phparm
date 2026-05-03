/**
 * Phase 13 admin UI — shared formatting + audit timeline normalizer for the
 * asset-lifecycle views (leases, acquisitions, decommissions).
 *
 * Audit rows from /api/audit have:
 *   { id, event, entity_type, entity_id, actor_id, context: object|json,
 *     created_at }
 *
 * The Timeline component expects:
 *   { id, type, title, description, created_at, meta }
 */

export const formatDateTime = (value) => {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleString()
}

export const formatDate = (value) => {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleDateString()
}

export const formatCurrencyCents = (cents) => {
  if (cents === null || cents === undefined || cents === '') return '—'
  const n = Number(cents)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(n / 100)
}

const titleizeWord = (word) =>
  word ? word.charAt(0).toUpperCase() + word.slice(1).toLowerCase() : word

export const titleizeStatus = (value) =>
  value
    ? value
        .split(/[_\s]+/)
        .map(titleizeWord)
        .join(' ')
    : value

const parseContext = (context) => {
  if (context === null || context === undefined) return {}
  if (typeof context === 'object') return context
  try {
    return JSON.parse(context)
  } catch {
    return { raw: String(context) }
  }
}

const eventTitle = (event, ctx) => {
  switch (event) {
    case 'lease.decision_recorded':
      return `Decision recorded: ${titleizeStatus(ctx.decision || 'unknown')}`
    case 'lease.created':
      return 'Lease created'
    case 'lease.updated':
      return 'Lease updated'
    case 'lease.terminated':
      return 'Lease terminated'
    case 'acquisition.created':
      return 'Acquisition created'
    case 'acquisition.updated':
      return 'Acquisition updated'
    case 'acquisition.transitioned':
      return `Status: ${titleizeStatus(ctx.from || '?')} → ${titleizeStatus(ctx.to || '?')}`
    case 'acquisition.portal_approved':
      return 'Approved by customer (portal)'
    case 'acquisition.portal_rejected':
      return 'Rejected by customer (portal)'
    case 'decommission.created':
      return 'Decommission initiated'
    case 'decommission.updated':
      return 'Decommission updated'
    case 'decommission.transitioned':
      return `Status: ${titleizeStatus(ctx.from || '?')} → ${titleizeStatus(ctx.to || '?')}`
    default:
      return event
  }
}

const eventDescription = (event, ctx) => {
  const lines = []
  if (ctx.note) lines.push(ctx.note)
  if (ctx.reason) lines.push(`Reason: ${ctx.reason}`)
  if (event === 'acquisition.portal_approved' || event === 'acquisition.portal_rejected') {
    if (ctx.portal_account_id) {
      lines.push(`Portal account #${ctx.portal_account_id}`)
    }
  }
  if (ctx.actor_kind === 'portal_user') {
    lines.push('Action recorded from customer portal.')
  }
  if (ctx.side_effects && typeof ctx.side_effects === 'object') {
    const summary = Object.entries(ctx.side_effects)
      .filter(([, v]) => v !== null && v !== '' && v !== undefined)
      .map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`)
      .join(' · ')
    if (summary) lines.push(summary)
  }
  return lines.length ? lines.join('\n') : ''
}

const eventType = (event) => {
  if (event.endsWith('portal_approved') || event === 'lease.decision_recorded') {
    return 'approval'
  }
  if (event.endsWith('transitioned')) return 'status'
  if (event.endsWith('created') || event.endsWith('updated')) return 'message'
  return 'status'
}

/**
 * Normalize audit rows into events the shared Timeline component renders.
 * Sorted oldest → newest (Timeline draws top-down with the connector line).
 */
export const normalizeAuditTimeline = (auditRows) => {
  if (!Array.isArray(auditRows)) return []
  return auditRows
    .map((row) => {
      const ctx = parseContext(row.context)
      return {
        id: row.id,
        type: eventType(row.event || ''),
        title: eventTitle(row.event || '', ctx),
        description: eventDescription(row.event || '', ctx),
        created_at: row.created_at,
        meta: { actor_id: row.actor_id, event: row.event, context: ctx },
      }
    })
    .sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime())
}

// ── status palettes — keep contained here so all three views render the
//    same badge for the same status. Falls back to "secondary" when missing.

export const LEASE_STATUS_VARIANTS = {
  active: 'success',
  pending_renewal: 'warning',
  buyout_pending: 'info',
  terminated: 'secondary',
  expired: 'danger',
}

export const ACQUISITION_STATUS_VARIANTS = {
  draft: 'secondary',
  quoted: 'info',
  approved: 'success',
  rejected: 'danger',
  po_issued: 'info',
  received: 'info',
  install_scheduled: 'warning',
  installed: 'info',
  active: 'success',
  cancelled: 'secondary',
}

export const DECOMMISSION_STATUS_VARIANTS = {
  initiated: 'info',
  wipe_in_progress: 'warning',
  wipe_complete: 'info',
  recovery_in_progress: 'warning',
  recovery_complete: 'info',
  entitlement_updated: 'info',
  audited: 'success',
  retired: 'success',
  cancelled: 'secondary',
}
