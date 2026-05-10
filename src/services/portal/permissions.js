/**
 * Mirror of src/Services/Portal/PortalPermission.php constants.
 *
 * Keep these slugs in sync with the PHP catalog. The backend is the source
 * of truth — these are just type-safe references for `usePortalAuth().can()`
 * calls so views don't pass raw strings that would silently never match.
 */
export const PORTAL_PERMISSION = Object.freeze({
  VIEW_DASHBOARD: 'view.dashboard',
  VIEW_SITES: 'view.sites',
  VIEW_ASSETS: 'view.assets',
  VIEW_WORKORDERS: 'view.workorders',
  VIEW_INVOICES: 'view.invoices',
  VIEW_CONTRACTS: 'view.contracts',
  VIEW_ESTIMATES: 'view.estimates',
  VIEW_LIFECYCLE: 'view.lifecycle',
  VIEW_MESSAGES: 'view.messages',

  CREATE_TICKETS: 'create.tickets',
  CREATE_MESSAGES: 'create.messages',
  UPLOAD_FILES: 'upload.files',

  APPROVE_ESTIMATES: 'approve.estimates',
  SIGN_CONTRACTS: 'sign.contracts',
  PAY_INVOICES: 'pay.invoices',
  MANAGE_PAYMENT_METHODS: 'manage.payment_methods',
  DECIDE_LIFECYCLE: 'decide.lifecycle',

  MANAGE_TEAM: 'manage.team',
})

export const PORTAL_TIER_LABELS = Object.freeze({
  viewer: 'Viewer',
  requester: 'Requester',
  approver: 'Approver',
  admin: 'Admin',
})
