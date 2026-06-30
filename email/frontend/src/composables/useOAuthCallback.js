import { onMounted } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { useMailboxStore } from '@/stores/mailbox'
import { useToastStore } from '@/stores/toast'
import { isDebugEnabled } from '@/utils/debug'

/**
 * Composable to handle OAuth callback results from sessionStorage
 * 
 * When a popup loses its window.opener reference (common with cross-origin OAuth redirects),
 * the backend stores the OAuth result in sessionStorage and redirects with ?oauth_complete=1.
 * This composable checks for that fallback and processes the result.
 */
export function useOAuthCallback() {
  const accountsStore = useAccountsStore()
  const mailboxStore = useMailboxStore()
  const toast = useToastStore()

  /**
   * Process OAuth result from data object
   */
  async function processOAuthResult(data) {
    if (data.type === 'oauth_callback') {
      const { success, error, account_email, provider } = data
      const providerName = provider === 'microsoft' ? 'Microsoft' : 'Google'

      if (success) {
        toast.success(`${providerName} account ${account_email || ''} connected successfully`)
        await accountsStore.fetchAccounts()
        
        // Refresh inbox if this was a linked account
        // The account type info isn't in the callback, so just refresh to be safe
        try {
          await mailboxStore.fetchMessages('INBOX')
        } catch (e) {
          // Ignore fetch errors - account was still added
        }
        
        return true
      } else if (error) {
        toast.error(`${providerName} sign-in failed: ${error.replace(/_/g, ' ')}`)
        return false
      }
    } else if (data.type === 'calendar_oauth_callback' || data.provider === 'google_calendar') {
      // Handle calendar-only OAuth callbacks
      const { success, error, account_email } = data
      
      if (success) {
        toast.success(`Google Calendar ${account_email || ''} connected successfully`)
        return true
      } else if (error) {
        toast.error(`Google Calendar connection failed: ${error.replace(/_/g, ' ')}`)
        return false
      }
    }
    return false
  }

  /**
   * Check for OAuth callback result in sessionStorage
   * Call this in onMounted() of components that initiate OAuth flows
   */
  async function checkOAuthFallback() {
    const urlParams = new URLSearchParams(window.location.search)
    const hasOAuthComplete = urlParams.has('oauth_complete')
    const hasOrphanedState = urlParams.has('state') && !urlParams.has('code')
    
    // Get result from sessionStorage - check even without oauth_complete param
    // (handles cases where redirect didn't add the param)
    const resultJson = sessionStorage.getItem('oauth_callback_result')
    
    // Clean up URL if it has OAuth-related params
    if (hasOAuthComplete || hasOrphanedState || resultJson) {
      const cleanUrl = window.location.pathname + window.location.hash
      window.history.replaceState({}, '', cleanUrl)
    }
    
    // If we have a state param but no oauth_complete, OAuth flow was interrupted
    if (hasOrphanedState && !resultJson) {
      console.warn('[OAuth] Orphaned state parameter detected - OAuth flow may have been interrupted')
      // Don't show error toast - user might have cancelled intentionally
      return false
    }
    
    // No result to process
    if (!resultJson) {
      if (hasOAuthComplete) {
        console.warn('[OAuth] oauth_complete flag present but no result in sessionStorage')
      }
      return false
    }

    try {
      const data = JSON.parse(resultJson)
      sessionStorage.removeItem('oauth_callback_result')
      isDebugEnabled() && console.log('[OAuth] Processing callback result from sessionStorage:', data.success ? 'success' : 'error')
      return await processOAuthResult(data)
    } catch (e) {
      console.error('[OAuth] Failed to parse callback result:', e)
      sessionStorage.removeItem('oauth_callback_result')
    }

    return false
  }

  /**
   * Setup automatic check on mount
   * Returns the check function for manual use if needed
   */
  function setupOAuthCallbackHandler() {
    onMounted(() => {
      checkOAuthFallback()
    })

    return { checkOAuthFallback }
  }

  return {
    checkOAuthFallback,
    processOAuthResult,
    setupOAuthCallbackHandler,
  }
}

