import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useMailboxStore } from '@/stores/mailbox'

export const useSpamStore = defineStore('spam', () => {
  const toast = useToastStore()
  const mailbox = useMailboxStore()

  // State
  const blockedSenders = ref([])
  const safeSenders = ref([])
  const spamEmails = ref([])
  const spamEmailsTotal = ref(0)
  const spamEmailsPage = ref(1)
  const spamEmailsPages = ref(0)
  const spamFolder = ref(null)
  const settings = ref({
    auto_delete_days: 30,
    auto_training_enabled: true,
  })
  const stats = ref(null)
  const loading = ref({
    blockedSenders: false,
    safeSenders: false,
    spamEmails: false,
    settings: false,
    stats: false,
    action: false,
  })

  // Getters
  const blockedCount = computed(() => blockedSenders.value.length)
  const safeCount = computed(() => safeSenders.value.length)
  const spamEmailsCount = computed(() => spamEmailsTotal.value)

  // Actions

  /**
   * Fetch blocked senders list
   */
  async function fetchBlockedSenders() {
    loading.value.blockedSenders = true
    try {
      const response = await api.get('/spam/blocked-senders')
      if (response.data.success) {
        blockedSenders.value = response.data.data || []
      }
    } catch (e) {
      console.error('Failed to fetch blocked senders:', e)
    } finally {
      loading.value.blockedSenders = false
    }
  }

  /**
   * Fetch emails from the Spam/Junk IMAP folder
   */
  async function fetchSpamEmails(page = 1, limit = 50) {
    loading.value.spamEmails = true
    try {
      const response = await api.get('/spam/emails', { params: { page, limit } })
      if (response.data.success) {
        spamEmails.value = response.data.data.emails || []
        spamEmailsTotal.value = response.data.data.total || 0
        spamEmailsPage.value = response.data.data.page || 1
        spamEmailsPages.value = response.data.data.pages || 0
        spamFolder.value = response.data.data.folder || null
      }
    } catch (e) {
      console.error('Failed to fetch spam emails:', e)
    } finally {
      loading.value.spamEmails = false
    }
  }

  /**
   * Block a sender
   */
  async function blockSender(email, options = {}) {
    loading.value.action = true
    try {
      const response = await api.post('/spam/block-sender', {
        email,
        reason: options.reason || null,
        block_domain: options.blockDomain || false,
        create_filter: options.createFilter !== false,
      })
      if (response.data.success) {
        const data = response.data.data || {}
        if (data.sieve_warning) {
          toast.warning(data.sieve_warning)
        } else if (!data.filter_created && options.createFilter !== false) {
          toast.warning(response.data.message || 'Sender blocked, but mailbox filter was not created')
        } else if (options.blockDomain) {
          const domain = email.split('@')[1]
          toast.success(`Blocked entire domain @${domain}`)
        } else {
          toast.success(`Blocked ${email}`)
        }
        await fetchBlockedSenders()
        return true
      } else {
        toast.error(response.data.message || 'Failed to block sender')
        return false
      }
    } catch (e) {
      console.error('Failed to block sender:', e)
      toast.error(e.response?.data?.message || 'Failed to block sender')
      return false
    } finally {
      loading.value.action = false
    }
  }

  /**
   * Unblock a sender
   */
  async function unblockSender(id) {
    loading.value.action = true
    try {
      const response = await api.delete(`/spam/blocked-sender/${id}`)
      if (response.data.success) {
        const data = response.data.data || {}
        if (data.sieve_warning) {
          toast.warning('Sender unblocked. ' + data.sieve_warning)
        } else {
          toast.success('Sender unblocked')
        }
        blockedSenders.value = blockedSenders.value.filter(s => s.id !== id)
        return true
      } else {
        toast.error(response.data.message || 'Failed to unblock sender')
        return false
      }
    } catch (e) {
      console.error('Failed to unblock sender:', e)
      toast.error('Failed to unblock sender')
      return false
    } finally {
      loading.value.action = false
    }
  }

  /**
   * Fetch safe senders list
   */
  async function fetchSafeSenders() {
    loading.value.safeSenders = true
    try {
      const response = await api.get('/spam/safe-senders')
      if (response.data.success) {
        safeSenders.value = response.data.data || []
      }
    } catch (e) {
      console.error('Failed to fetch safe senders:', e)
    } finally {
      loading.value.safeSenders = false
    }
  }

  /**
   * Add a safe sender
   */
  async function addSafeSender(email, trustDomain = false) {
    loading.value.action = true
    try {
      const response = await api.post('/spam/safe-sender', {
        email,
        trust_domain: trustDomain,
      })
      if (response.data.success) {
        const data = response.data.data || {}
        if (data.sieve_warning) {
          toast.warning(`Added ${email} to trusted senders. ` + data.sieve_warning)
        } else {
          toast.success(`Added ${email} to trusted senders`)
        }
        await fetchSafeSenders()
        return true
      } else {
        toast.error(response.data.message || 'Failed to add trusted sender')
        return false
      }
    } catch (e) {
      console.error('Failed to add trusted sender:', e)
      toast.error('Failed to add trusted sender')
      return false
    } finally {
      loading.value.action = false
    }
  }

  /**
   * Remove a safe sender
   */
  async function removeSafeSender(id) {
    loading.value.action = true
    try {
      const response = await api.delete(`/spam/safe-sender/${id}`)
      if (response.data.success) {
        const data = response.data.data || {}
        if (data.sieve_warning) {
          toast.warning('Trusted sender removed. ' + data.sieve_warning)
        } else {
          toast.success('Trusted sender removed')
        }
        safeSenders.value = safeSenders.value.filter(s => s.id !== id)
        return true
      } else {
        toast.error(response.data.message || 'Failed to remove trusted sender')
        return false
      }
    } catch (e) {
      console.error('Failed to remove trusted sender:', e)
      toast.error('Failed to remove trusted sender')
      return false
    } finally {
      loading.value.action = false
    }
  }

  /**
   * Report an email as spam
   */
  async function reportSpam(folder, uid, options = {}) {
    loading.value.action = true
    try {
      const response = await api.post('/spam/report', {
        folder,
        uid,
        train: options.train !== false,
        block_sender: options.blockSender || false,
      })
      if (response.data.success) {
        const data = response.data.data
        let message = 'Marked as spam'
        if (data.trained) message += ' (trained as spam)'
        if (data.sender_blocked) message += ' and sender blocked'
        toast.success(message)
        
        // Refresh messages if in same folder
        if (mailbox.currentFolder === folder) {
          await mailbox.fetchMessages()
        }
        
        return true
      } else {
        toast.error(response.data.message || 'Failed to report spam')
        return false
      }
    } catch (e) {
      console.error('Failed to report spam:', e)
      toast.error(e.response?.data?.message || 'Failed to report spam')
      return false
    } finally {
      loading.value.action = false
    }
  }

  /**
   * Mark email as not spam
   */
  async function notSpam(folder, uid, options = {}) {
    loading.value.action = true
    try {
      const response = await api.post('/spam/not-spam', {
        folder,
        uid,
        train: options.train !== false,
        add_to_safe: options.addToSafe || false,
      })
      if (response.data.success) {
        const data = response.data.data
        let message = 'Moved to inbox'
        if (data.trained) message += ' (trained as not spam)'
        if (data.added_to_safe) message += ' and sender added to trusted list'
        
        if (data.sieve_warning) {
          toast.warning(message + '. ' + data.sieve_warning)
        } else {
          toast.success(message)
        }
        
        if (mailbox.currentFolder === folder) {
          await mailbox.fetchMessages()
        }
        
        return true
      } else {
        toast.error(response.data.message || 'Failed to mark as not spam')
        return false
      }
    } catch (e) {
      console.error('Failed to mark as not spam:', e)
      toast.error(e.response?.data?.message || 'Failed to mark as not spam')
      return false
    } finally {
      loading.value.action = false
    }
  }

  /**
   * Batch report multiple emails as spam (move + train)
   * @param {Array<{uid: number, folder: string}>} items
   */
  async function bulkReportSpam(items, options = {}) {
    if (!items?.length) return { moved: 0 }
    loading.value.action = true
    try {
      const response = await api.post('/spam/report-batch', {
        items,
        train: options.train !== false,
      })
      if (response.data.success) {
        const data = response.data.data
        let message = `Marked ${data.moved} message${data.moved !== 1 ? 's' : ''} as spam`
        if (data.trained > 0) message += ` (trained ${data.trained})`
        toast.success(message)

        if (mailbox.currentFolder) {
          await mailbox.fetchMessages()
        }

        return data
      } else {
        toast.error(response.data.message || 'Failed to report spam')
        return { moved: 0 }
      }
    } catch (e) {
      console.error('Failed to bulk report spam:', e)
      toast.error('Failed to report spam')
      return { moved: 0 }
    } finally {
      loading.value.action = false
    }
  }

  /**
   * Batch mark multiple emails as not-spam (move to INBOX + log ham + optional train)
   * @param {Array<{uid: number, folder: string}>} items
   */
  async function bulkNotSpam(items, options = {}) {
    if (!items?.length) return { moved: 0 }
    loading.value.action = true
    try {
      const response = await api.post('/spam/not-spam-batch', {
        items,
        train: options.train !== false,
      })
      if (response.data.success) {
        const data = response.data.data
        let message = `Moved ${data.moved} message${data.moved !== 1 ? 's' : ''} to inbox`
        if (data.trained > 0) message += ` (trained ${data.trained})`
        toast.success(message)

        if (mailbox.currentFolder) {
          await mailbox.fetchMessages()
        }

        return data
      } else {
        toast.error(response.data.message || 'Failed to mark as not spam')
        return { moved: 0 }
      }
    } catch (e) {
      console.error('Failed to bulk mark as not spam:', e)
      toast.error('Failed to mark as not spam')
      return { moved: 0 }
    } finally {
      loading.value.action = false
    }
  }

  /**
   * Fetch spam settings
   */
  async function fetchSettings() {
    loading.value.settings = true
    try {
      const response = await api.get('/spam/settings')
      if (response.data.success) {
        settings.value = response.data.data || {
          auto_delete_days: 30,
          auto_training_enabled: true,
        }
      }
    } catch (e) {
      console.error('Failed to fetch spam settings:', e)
    } finally {
      loading.value.settings = false
    }
  }

  /**
   * Update spam settings
   */
  async function updateSettings(newSettings) {
    loading.value.settings = true
    try {
      const response = await api.put('/spam/settings', newSettings)
      if (response.data.success) {
        settings.value = { ...settings.value, ...newSettings }
        toast.success('Spam settings updated')
        return true
      } else {
        toast.error(response.data.message || 'Failed to update settings')
        return false
      }
    } catch (e) {
      console.error('Failed to update spam settings:', e)
      toast.error('Failed to update settings')
      return false
    } finally {
      loading.value.settings = false
    }
  }

  /**
   * Fetch spam statistics
   */
  async function fetchStats(days = 30) {
    loading.value.stats = true
    try {
      const response = await api.get('/spam/stats', { params: { days } })
      if (response.data.success) {
        stats.value = response.data.data
      }
    } catch (e) {
      console.error('Failed to fetch spam stats:', e)
    } finally {
      loading.value.stats = false
    }
  }

  /**
   * Check if a sender is blocked
   */
  function isSenderBlocked(email) {
    if (!email) return false
    const lowerEmail = email.toLowerCase()
    const domain = email.split('@')[1]?.toLowerCase()
    
    return blockedSenders.value.some(s => {
      if (s.blocked_email?.toLowerCase() === lowerEmail) return true
      if (s.blocked_domain && domain === s.blocked_domain.toLowerCase()) return true
      return false
    })
  }

  /**
   * Check if a sender is in safe list
   */
  function isSenderSafe(email) {
    if (!email) return false
    const lowerEmail = email.toLowerCase()
    const domain = email.split('@')[1]?.toLowerCase()
    
    return safeSenders.value.some(s => {
      if (s.safe_email?.toLowerCase() === lowerEmail) return true
      if (s.safe_domain && domain === s.safe_domain.toLowerCase()) return true
      return false
    })
  }

  return {
    // State
    blockedSenders,
    safeSenders,
    spamEmails,
    spamEmailsTotal,
    spamEmailsPage,
    spamEmailsPages,
    spamFolder,
    settings,
    stats,
    loading,
    
    // Getters
    blockedCount,
    safeCount,
    spamEmailsCount,
    
    // Actions
    fetchBlockedSenders,
    fetchSpamEmails,
    blockSender,
    unblockSender,
    fetchSafeSenders,
    addSafeSender,
    removeSafeSender,
    reportSpam,
    bulkReportSpam,
    notSpam,
    bulkNotSpam,
    fetchSettings,
    updateSettings,
    fetchStats,
    isSenderBlocked,
    isSenderSafe,
  }
})

