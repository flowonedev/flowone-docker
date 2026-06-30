// useMailAccountAdmin
// ---------------------------------------------------------------
// Shared per-mailbox admin actions for the panel: set the DRIVE +
// EMAIL (Dovecot) quota and reset a user's webmail 2FA. Used by
// both the per-site Emails tab and the central Overview mail list
// via AccountAdminMenu.vue, so the API plumbing + unit formatting
// live in exactly one place.
//
// Conventions (match useSiteManage):
//   - All API calls go through `@/services/api`.
//   - Actions surface their own toasts and resolve to a boolean so
//     callers can `if (await setQuotas(...)) refresh()`.
//   - Quotas are stored internally as quota_mb (mailbox) and
//     quota_bytes (drive); the UI only ever shows MB/GB/Unlimited.

import { ref } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const KB = 1024
const MB = 1024 * 1024
const GB = 1024 * 1024 * 1024
const TB = 1024 * 1024 * 1024 * 1024

// Human-readable byte size (e.g. 1.5 GB). Mirrors the agent's humanFileSize.
export function formatBytes(bytes) {
  const n = Number(bytes)
  if (!Number.isFinite(n) || n <= 0) return '0 B'
  if (n >= TB) return `${(n / TB).toFixed(2)} TB`
  if (n >= GB) return `${(n / GB).toFixed(2)} GB`
  if (n >= MB) return `${(n / MB).toFixed(1)} MB`
  if (n >= KB) return `${(n / KB).toFixed(0)} KB`
  return `${n} B`
}

// Mailbox quota (stored in MB; 0 = unlimited) → display string.
export function formatMailboxQuota(quotaMb) {
  const mb = Number(quotaMb)
  if (!Number.isFinite(mb) || mb <= 0) return 'Unlimited'
  return formatBytes(mb * MB)
}

// Drive quota (stored in bytes; < 0 = unlimited) → display string.
export function formatDriveQuota(bytes) {
  const n = Number(bytes)
  if (!Number.isFinite(n) || n < 0) return 'Unlimited'
  return formatBytes(n)
}

export function useMailAccountAdmin() {
  const toast = useToastStore()
  const savingQuota = ref(false)
  const resetting2fa = ref(false)

  /**
   * Set mailbox and/or drive quota for one account.
   * @param {string} email
   * @param {{ quotaMb?: number|null, driveQuotaBytes?: number|null }} quotas
   *   quotaMb: 0 = unlimited; driveQuotaBytes: -1 = unlimited.
   *   Omit a field (undefined/null) to leave it unchanged.
   * @returns {Promise<boolean>} success
   */
  const setQuotas = async (email, { quotaMb = null, driveQuotaBytes = null } = {}) => {
    const body = {}
    if (quotaMb !== null && quotaMb !== undefined) body.quota_mb = Math.trunc(Number(quotaMb))
    if (driveQuotaBytes !== null && driveQuotaBytes !== undefined) {
      body.drive_quota_bytes = Math.trunc(Number(driveQuotaBytes))
    }

    if (Object.keys(body).length === 0) {
      toast.error('Nothing to update')
      return false
    }

    savingQuota.value = true
    try {
      const r = await api.post(`/mail/accounts/${encodeURIComponent(email)}/quota`, body)
      if (r.data?.success) {
        // The agent reports a warning (e.g. doveadm recalc deferred) but still
        // saved — surface it as a warning, not a hard failure.
        const warnings = r.data?.data?.warnings || []
        if (warnings.length) {
          toast.warning(warnings.join(' '))
        } else {
          toast.success('Quota updated')
        }
        return true
      }
      toast.error(r.data?.error || 'Failed to update quota')
      return false
    } catch (e) {
      toast.error(e?.response?.data?.error || 'Failed to update quota')
      return false
    } finally {
      savingQuota.value = false
    }
  }

  /**
   * Reset webmail 2FA for one account: disables 2FA, wipes the secret +
   * backup codes, revokes trusted devices, and signs out active sessions.
   * @param {string} email
   * @returns {Promise<boolean>} success
   */
  const reset2fa = async (email) => {
    resetting2fa.value = true
    try {
      const r = await api.post(`/mail/accounts/${encodeURIComponent(email)}/reset-2fa`)
      if (r.data?.success) {
        toast.success(`2FA reset for ${email}`)
        return true
      }
      toast.error(r.data?.error || 'Failed to reset 2FA')
      return false
    } catch (e) {
      toast.error(e?.response?.data?.error || 'Failed to reset 2FA')
      return false
    } finally {
      resetting2fa.value = false
    }
  }

  return {
    savingQuota,
    resetting2fa,
    setQuotas,
    reset2fa,
    // formatters re-exported for convenience inside components
    formatBytes,
    formatMailboxQuota,
    formatDriveQuota,
  }
}
