import { useCallback, useEffect, useState } from 'react'

import Card from '../../components/ui/Card'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import { portalAccountService } from '../../../services/portal/account.service'

/**
 * Phase 2f — self-issued personal access tokens.
 *
 * Plaintext is returned exactly once at issue time and held in component
 * state so the user can copy it; once dismissed it's gone for good. The
 * list view shows only the prefix + metadata; revocation flips a flag,
 * keeping the prefix visible for the audit trail.
 */
const formatDate = (s) => (s ? new Date(s).toLocaleString() : '')

const tokenStatus = (token) => {
  if (token.revoked_at) return { label: 'Revoked', variant: 'bg-red-100 text-red-700' }
  if (token.expires_at && new Date(token.expires_at).getTime() < Date.now()) {
    return { label: 'Expired', variant: 'bg-yellow-100 text-yellow-700' }
  }
  return { label: 'Active', variant: 'bg-green-100 text-green-700' }
}

export default function PortalApiTokens() {
  const [tokens, setTokens] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [showCreate, setShowCreate] = useState(false)
  const [createName, setCreateName] = useState('')
  const [createExpires, setCreateExpires] = useState('')
  const [creating, setCreating] = useState(false)
  const [createError, setCreateError] = useState(null)

  const [issuedToken, setIssuedToken] = useState(null)
  const [copied, setCopied] = useState(false)

  const reload = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const rows = await portalAccountService.listApiTokens()
      setTokens(Array.isArray(rows) ? rows : [])
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load API tokens.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { reload() }, [reload])

  const startCreate = () => {
    setCreateName('')
    setCreateExpires('')
    setCreateError(null)
    setShowCreate(true)
  }

  const submitCreate = async () => {
    if (!createName.trim()) {
      setCreateError('Please give the token a name.')
      return
    }
    setCreating(true)
    setCreateError(null)
    try {
      const result = await portalAccountService.issueApiToken({
        name: createName.trim(),
        expires_at: createExpires || null,
      })
      setShowCreate(false)
      setIssuedToken(result)
      setCopied(false)
      await reload()
    } catch (err) {
      setCreateError(err.response?.data?.message || 'Unable to issue token.')
    } finally {
      setCreating(false)
    }
  }

  const revoke = async (token) => {
    if (!window.confirm(`Revoke "${token.name}"? Anything using this token will stop working immediately.`)) {
      return
    }
    try {
      await portalAccountService.revokeApiToken(token.id)
      await reload()
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to revoke token.')
    }
  }

  const copyToken = async () => {
    if (!issuedToken?.plaintext_token) return
    try {
      await navigator.clipboard.writeText(issuedToken.plaintext_token)
      setCopied(true)
    } catch {
      setCopied(false)
    }
  }

  return (
    <div className="space-y-6">
      <header className="flex items-end justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-2xl font-semibold">API tokens</h1>
          <p className="text-sm text-gray-600 mt-1">
            Issue tokens for scripts and integrations. Each token inherits your portal permissions.
          </p>
        </div>
        <Button onClick={startCreate} size="sm">New token</Button>
      </header>

      {error && <Alert variant="error" onClose={() => setError(null)}>{error}</Alert>}

      {loading ? (
        <div className="py-12 flex justify-center"><Loading text="Loading tokens…" /></div>
      ) : tokens.length === 0 ? (
        <Card>
          <p className="text-sm text-gray-500">
            You haven&rsquo;t issued any API tokens yet.
          </p>
        </Card>
      ) : (
        <Card padding={false}>
          <ul className="divide-y">
            {tokens.map((token) => {
              const status = tokenStatus(token)
              return (
                <li key={token.id} className="p-4">
                  <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-medium text-gray-900">{token.name}</span>
                        <span className={`text-xs px-2 py-0.5 rounded-full ${status.variant}`}>
                          {status.label}
                        </span>
                      </div>
                      <div className="text-xs text-gray-500 mt-1 font-mono">
                        pat_{token.token_prefix}…
                      </div>
                      <div className="text-xs text-gray-500 mt-1 space-x-3">
                        <span>Created {formatDate(token.created_at)}</span>
                        {token.last_used_at && <span>Last used {formatDate(token.last_used_at)}</span>}
                        {token.expires_at && <span>Expires {formatDate(token.expires_at)}</span>}
                        {token.revoked_at && <span>Revoked {formatDate(token.revoked_at)}</span>}
                      </div>
                    </div>
                    {!token.revoked_at && (
                      <Button variant="danger" size="sm" onClick={() => revoke(token)}>
                        Revoke
                      </Button>
                    )}
                  </div>
                </li>
              )
            })}
          </ul>
        </Card>
      )}

      <Modal open={showCreate} title="Issue API token" onClose={() => setShowCreate(false)}>
        <div className="space-y-4">
          {createError && <Alert variant="error" closable={false}>{createError}</Alert>}
          <Input
            label="Name"
            placeholder="e.g. CI sync, mobile script"
            modelValue={createName}
            onUpdateModelValue={setCreateName}
            disabled={creating}
            required
          />
          <Input
            label="Expires at (optional)"
            type="datetime-local"
            modelValue={createExpires}
            onUpdateModelValue={setCreateExpires}
            disabled={creating}
            helperText="Leave blank for a token that never expires."
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" onClick={() => setShowCreate(false)} disabled={creating}>
              Cancel
            </Button>
            <Button onClick={submitCreate} loading={creating} disabled={creating}>
              Issue token
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={!!issuedToken}
        title="Your new API token"
        closeOnBackdrop={false}
        onClose={() => setIssuedToken(null)}
      >
        <div className="space-y-4">
          <Alert variant="warning" closable={false}>
            Copy this token now. It will not be shown again.
          </Alert>
          <pre className="p-3 bg-gray-50 rounded text-xs font-mono break-all whitespace-pre-wrap">
            {issuedToken?.plaintext_token}
          </pre>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={copyToken}>
              {copied ? 'Copied!' : 'Copy to clipboard'}
            </Button>
            <Button onClick={() => setIssuedToken(null)}>Done</Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
