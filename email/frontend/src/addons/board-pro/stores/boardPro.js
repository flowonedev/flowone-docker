import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'

export const useBoardProStore = defineStore('boardPro', () => {
  // =========================================================================
  // State
  // =========================================================================

  // Card emails
  const cardEmails = ref({}) // { [cardId]: email[] }
  const cardEmailsLoading = ref(false)

  // Card financials
  const cardFinancials = ref({}) // { [cardId]: financials }
  const cardFinancialsLoading = ref(false)

  // Board financial summary
  const boardFinancialSummary = ref(null)
  const boardFinancialLoading = ref(false)

  // Global financials
  const globalFinancials = ref(null)
  const globalFinancialsLoading = ref(false)

  // Email rules
  const emailRules = ref([])
  const emailRulesLoading = ref(false)

  // Automation rules
  const automationRules = ref([])
  const automationRulesLoading = ref(false)

  // Card timeline
  const cardTimeline = ref([])
  const cardTimelineTotal = ref(0)
  const cardTimelineLoading = ref(false)

  // Client health
  const boardClientHealth = ref(null)
  const clientHealthLoading = ref(false)

  // Multi-lens view data
  const revenueViewData = ref([])
  const timeViewData = ref([])
  const clientViewData = ref([])
  const lensViewLoading = ref(false)

  // MoodBoard links
  const cardMoodBoardLinks = ref([])

  // AI results
  const aiSummary = ref(null)
  const aiRisks = ref(null)
  const aiEstimation = ref(null)
  const aiDraft = ref(null)
  const aiLoading = ref(false)

  // Executive report
  const executiveReport = ref(null)
  const revenueProjection = ref(null)
  const workloadAnalytics = ref(null)

  // Awaiting replies
  const awaitingReplies = ref([])

  // =========================================================================
  // Card-Email Linking
  // =========================================================================

  async function fetchCardEmails(cardId) {
    cardEmailsLoading.value = true
    try {
      const { data } = await api.get(`/board-pro/cards/${cardId}/emails`)
      if (data.success) {
        cardEmails.value[cardId] = data.data
      }
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchCardEmails error:', err)
      return []
    } finally {
      cardEmailsLoading.value = false
    }
  }

  async function linkEmailToCard(cardId, emailData) {
    try {
      const { data } = await api.post(`/board-pro/cards/${cardId}/emails`, emailData)
      if (data.success) {
        if (!cardEmails.value[cardId]) cardEmails.value[cardId] = []
        cardEmails.value[cardId].unshift(data.data)
      }
      return data
    } catch (err) {
      console.error('[BoardPro] linkEmailToCard error:', err)
      throw err
    }
  }

  async function unlinkEmail(cardId, linkId) {
    try {
      await api.delete(`/board-pro/cards/${cardId}/emails/${linkId}`)
      if (cardEmails.value[cardId]) {
        cardEmails.value[cardId] = cardEmails.value[cardId].filter(e => e.id !== linkId)
      }
    } catch (err) {
      console.error('[BoardPro] unlinkEmail error:', err)
      throw err
    }
  }

  async function updateReplyStatus(linkId, status) {
    try {
      await api.put(`/board-pro/card-emails/${linkId}/reply-status`, { reply_status: status })
    } catch (err) {
      console.error('[BoardPro] updateReplyStatus error:', err)
      throw err
    }
  }

  async function convertEmailToCard(boardId, emailData) {
    try {
      const { data } = await api.post(`/board-pro/boards/${boardId}/convert-email`, emailData)
      return data
    } catch (err) {
      console.error('[BoardPro] convertEmailToCard error:', err)
      throw err
    }
  }

  async function fetchAwaitingReplies(boardId) {
    try {
      const { data } = await api.get(`/board-pro/boards/${boardId}/awaiting-replies`)
      if (data.success) {
        awaitingReplies.value = data.data
      }
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchAwaitingReplies error:', err)
      return []
    }
  }

  // =========================================================================
  // Email Rules
  // =========================================================================

  async function fetchEmailRules(boardId) {
    emailRulesLoading.value = true
    try {
      const { data } = await api.get(`/board-pro/boards/${boardId}/email-rules`)
      if (data.success) {
        emailRules.value = data.data
      }
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchEmailRules error:', err)
      return []
    } finally {
      emailRulesLoading.value = false
    }
  }

  async function createEmailRule(boardId, ruleData) {
    try {
      const { data } = await api.post(`/board-pro/boards/${boardId}/email-rules`, ruleData)
      if (data.success) {
        emailRules.value.unshift(data.data)
      }
      return data
    } catch (err) {
      console.error('[BoardPro] createEmailRule error:', err)
      throw err
    }
  }

  async function updateEmailRule(ruleId, ruleData) {
    try {
      const { data } = await api.put(`/board-pro/email-rules/${ruleId}`, ruleData)
      if (data.success) {
        const idx = emailRules.value.findIndex(r => r.id === ruleId)
        if (idx !== -1) emailRules.value[idx] = data.data
      }
      return data
    } catch (err) {
      console.error('[BoardPro] updateEmailRule error:', err)
      throw err
    }
  }

  async function deleteEmailRule(ruleId) {
    try {
      await api.delete(`/board-pro/email-rules/${ruleId}`)
      emailRules.value = emailRules.value.filter(r => r.id !== ruleId)
    } catch (err) {
      console.error('[BoardPro] deleteEmailRule error:', err)
      throw err
    }
  }

  async function duplicateEmailRule(boardId, rule) {
    const payload = {
      rule_type: rule.rule_type,
      rule_value: rule.rule_value,
      auto_create_card: rule.auto_create_card,
      list_id: rule.list_id,
      auto_assign_to: rule.auto_assign_to,
      card_title_template: rule.card_title_template || '',
      type_categories: rule.type_categories || [],
      type_default: rule.type_default || 'General',
      body_handling: rule.body_handling || 'none',
      checklist_title: rule.checklist_title || '',
      auto_link_email: rule.auto_link_email ?? 1,
      auto_attach_files: rule.auto_attach_files ?? 1,
    }
    return createEmailRule(boardId, payload)
  }

  async function runEmailRule(ruleId, folder = 'INBOX', limit = 50) {
    try {
      const { data } = await api.post(`/board-pro/email-rules/${ruleId}/run`, { folder, limit })
      if (data.success) {
        return data.data
      }
      return null
    } catch (err) {
      console.error('[BoardPro] runEmailRule error:', err)
      throw err
    }
  }

  // =========================================================================
  // Card Financials
  // =========================================================================

  async function fetchCardFinancials(cardId) {
    cardFinancialsLoading.value = true
    try {
      const { data } = await api.get(`/board-pro/cards/${cardId}/financials`)
      if (data.success) {
        cardFinancials.value[cardId] = data.data
      }
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchCardFinancials error:', err)
      return null
    } finally {
      cardFinancialsLoading.value = false
    }
  }

  async function updateCardFinancials(cardId, financialData) {
    try {
      const { data } = await api.put(`/board-pro/cards/${cardId}/financials`, financialData)
      if (data.success) {
        cardFinancials.value[cardId] = data.data
      }
      return data
    } catch (err) {
      console.error('[BoardPro] updateCardFinancials error:', err)
      throw err
    }
  }

  async function fetchBoardFinancialSummary(boardId) {
    boardFinancialLoading.value = true
    try {
      const { data } = await api.get(`/board-pro/boards/${boardId}/financial-summary`)
      if (data.success) {
        boardFinancialSummary.value = data.data
      }
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchBoardFinancialSummary error:', err)
      return null
    } finally {
      boardFinancialLoading.value = false
    }
  }

  async function fetchGlobalFinancials() {
    globalFinancialsLoading.value = true
    try {
      const { data } = await api.get('/board-pro/financials/global')
      if (data.success) {
        globalFinancials.value = data.data
      }
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchGlobalFinancials error:', err)
      return null
    } finally {
      globalFinancialsLoading.value = false
    }
  }

  // =========================================================================
  // Client Health
  // =========================================================================

  async function fetchBoardClientHealth(boardId) {
    clientHealthLoading.value = true
    try {
      const { data } = await api.get(`/board-pro/boards/${boardId}/client-health`)
      if (data.success) {
        boardClientHealth.value = data.data
      }
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchBoardClientHealth error:', err)
      return null
    } finally {
      clientHealthLoading.value = false
    }
  }

  // =========================================================================
  // Board Automations
  // =========================================================================

  async function fetchAutomationRules(boardId) {
    automationRulesLoading.value = true
    try {
      const { data } = await api.get(`/board-pro/boards/${boardId}/automations`)
      if (data.success) {
        automationRules.value = data.data
      }
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchAutomationRules error:', err)
      return []
    } finally {
      automationRulesLoading.value = false
    }
  }

  async function createAutomationRule(boardId, ruleData) {
    try {
      const { data } = await api.post(`/board-pro/boards/${boardId}/automations`, ruleData)
      if (data.success) {
        automationRules.value.unshift(data.data)
      }
      return data
    } catch (err) {
      console.error('[BoardPro] createAutomationRule error:', err)
      throw err
    }
  }

  async function updateAutomationRule(ruleId, ruleData) {
    try {
      const { data } = await api.put(`/board-pro/automations/${ruleId}`, ruleData)
      if (data.success) {
        const idx = automationRules.value.findIndex(r => r.id === ruleId)
        if (idx !== -1) automationRules.value[idx] = data.data
      }
      return data
    } catch (err) {
      console.error('[BoardPro] updateAutomationRule error:', err)
      throw err
    }
  }

  async function deleteAutomationRule(ruleId) {
    try {
      await api.delete(`/board-pro/automations/${ruleId}`)
      automationRules.value = automationRules.value.filter(r => r.id !== ruleId)
    } catch (err) {
      console.error('[BoardPro] deleteAutomationRule error:', err)
      throw err
    }
  }

  async function fetchAutomationLog(ruleId, limit = 50, offset = 0) {
    try {
      const { data } = await api.get(`/board-pro/automations/${ruleId}/log`, { params: { limit, offset } })
      return data.success ? data.data : []
    } catch (err) {
      console.error('[BoardPro] fetchAutomationLog error:', err)
      return []
    }
  }

  async function fetchBoardAutomationLog(boardId, limit = 100, offset = 0) {
    try {
      const { data } = await api.get(`/board-pro/boards/${boardId}/automations/log`, { params: { limit, offset } })
      return data.success ? data.data : []
    } catch (err) {
      console.error('[BoardPro] fetchBoardAutomationLog error:', err)
      return []
    }
  }

  // =========================================================================
  // Unified Card Timeline
  // =========================================================================

  async function fetchCardTimeline(cardId, limit = 50, offset = 0) {
    cardTimelineLoading.value = true
    try {
      const { data } = await api.get(`/board-pro/cards/${cardId}/timeline`, { params: { limit, offset } })
      if (data.success) {
        cardTimeline.value = data.data
        cardTimelineTotal.value = data.total
      }
      return data
    } catch (err) {
      console.error('[BoardPro] fetchCardTimeline error:', err)
      return { data: [], total: 0 }
    } finally {
      cardTimelineLoading.value = false
    }
  }

  // =========================================================================
  // Multi-Lens Views
  // =========================================================================

  async function fetchRevenueView(boardId) {
    lensViewLoading.value = true
    try {
      const { data } = await api.get(`/board-pro/boards/${boardId}/revenue-view`)
      if (data.success) revenueViewData.value = data.data
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchRevenueView error:', err)
      return []
    } finally {
      lensViewLoading.value = false
    }
  }

  async function fetchTimeView(boardId) {
    lensViewLoading.value = true
    try {
      const { data } = await api.get(`/board-pro/boards/${boardId}/time-view`)
      if (data.success) timeViewData.value = data.data
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchTimeView error:', err)
      return []
    } finally {
      lensViewLoading.value = false
    }
  }

  async function fetchClientView(boardId) {
    lensViewLoading.value = true
    try {
      const { data } = await api.get(`/board-pro/boards/${boardId}/client-view`)
      if (data.success) clientViewData.value = data.data
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchClientView error:', err)
      return []
    } finally {
      lensViewLoading.value = false
    }
  }

  // =========================================================================
  // MoodBoard Hybrid
  // =========================================================================

  async function linkMoodBoardFrame(cardId, moodBoardId, itemId = null) {
    try {
      const { data } = await api.post(`/board-pro/cards/${cardId}/moodboard-link`, {
        mood_board_id: moodBoardId,
        mood_board_item_id: itemId,
      })
      return data
    } catch (err) {
      console.error('[BoardPro] linkMoodBoardFrame error:', err)
      throw err
    }
  }

  async function unlinkMoodBoardFrame(cardId, linkId) {
    try {
      await api.delete(`/board-pro/cards/${cardId}/moodboard-link/${linkId}`)
    } catch (err) {
      console.error('[BoardPro] unlinkMoodBoardFrame error:', err)
      throw err
    }
  }

  async function fetchCardMoodBoardLinks(cardId) {
    try {
      const { data } = await api.get(`/board-pro/cards/${cardId}/moodboard-links`)
      if (data.success) cardMoodBoardLinks.value = data.data
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchCardMoodBoardLinks error:', err)
      return []
    }
  }

  async function importMoodBoardAsCards(boardId, moodBoardId, listId) {
    try {
      const { data } = await api.post(`/board-pro/boards/${boardId}/import-moodboard`, {
        mood_board_id: moodBoardId,
        list_id: listId,
      })
      return data
    } catch (err) {
      console.error('[BoardPro] importMoodBoardAsCards error:', err)
      throw err
    }
  }

  // =========================================================================
  // AI Intelligence
  // =========================================================================

  async function aiSummarize(boardId) {
    aiLoading.value = true
    try {
      const { data } = await api.post(`/board-pro/boards/${boardId}/ai/summarize`)
      if (data.success) aiSummary.value = data
      return data
    } catch (err) {
      console.error('[BoardPro] aiSummarize error:', err)
      throw err
    } finally {
      aiLoading.value = false
    }
  }

  async function aiRiskReport(boardId) {
    aiLoading.value = true
    try {
      const { data } = await api.post(`/board-pro/boards/${boardId}/ai/risk-report`)
      if (data.success) aiRisks.value = data
      return data
    } catch (err) {
      console.error('[BoardPro] aiRiskReport error:', err)
      throw err
    } finally {
      aiLoading.value = false
    }
  }

  async function aiEstimate(boardId) {
    aiLoading.value = true
    try {
      const { data } = await api.post(`/board-pro/boards/${boardId}/ai/estimate`)
      if (data.success) aiEstimation.value = data
      return data
    } catch (err) {
      console.error('[BoardPro] aiEstimate error:', err)
      throw err
    } finally {
      aiLoading.value = false
    }
  }

  async function aiDraftClientUpdate(cardId) {
    aiLoading.value = true
    try {
      const { data } = await api.post(`/board-pro/cards/${cardId}/ai/draft-update`)
      if (data.success) aiDraft.value = data
      return data
    } catch (err) {
      console.error('[BoardPro] aiDraftClientUpdate error:', err)
      throw err
    } finally {
      aiLoading.value = false
    }
  }

  // =========================================================================
  // Executive Mode
  // =========================================================================

  async function fetchExecutiveReport(boardId) {
    try {
      const { data } = await api.get(`/board-pro/boards/${boardId}/executive-report`)
      if (data.success) executiveReport.value = data.report
      return data
    } catch (err) {
      console.error('[BoardPro] fetchExecutiveReport error:', err)
      return null
    }
  }

  async function fetchRevenueProjection(boardId) {
    try {
      const { data } = await api.get(`/board-pro/boards/${boardId}/revenue-projection`)
      if (data.success) revenueProjection.value = data.data
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchRevenueProjection error:', err)
      return null
    }
  }

  async function fetchWorkloadAnalytics(boardId) {
    try {
      const { data } = await api.get(`/board-pro/boards/${boardId}/workload-analytics`)
      if (data.success) workloadAnalytics.value = data.data
      return data.data
    } catch (err) {
      console.error('[BoardPro] fetchWorkloadAnalytics error:', err)
      return null
    }
  }

  // =========================================================================
  // Cleanup
  // =========================================================================

  function clearCardData() {
    cardEmails.value = {}
    cardFinancials.value = {}
    cardTimeline.value = []
    cardTimelineTotal.value = 0
    cardMoodBoardLinks.value = []
    aiDraft.value = null
  }

  function clearBoardData() {
    boardFinancialSummary.value = null
    boardClientHealth.value = null
    emailRules.value = []
    automationRules.value = []
    revenueViewData.value = []
    timeViewData.value = []
    clientViewData.value = []
    awaitingReplies.value = []
    aiSummary.value = null
    aiRisks.value = null
    aiEstimation.value = null
    executiveReport.value = null
    revenueProjection.value = null
    workloadAnalytics.value = null
  }

  return {
    // State
    cardEmails,
    cardEmailsLoading,
    cardFinancials,
    cardFinancialsLoading,
    boardFinancialSummary,
    boardFinancialLoading,
    globalFinancials,
    globalFinancialsLoading,
    emailRules,
    emailRulesLoading,
    automationRules,
    automationRulesLoading,
    cardTimeline,
    cardTimelineTotal,
    cardTimelineLoading,
    boardClientHealth,
    clientHealthLoading,
    revenueViewData,
    timeViewData,
    clientViewData,
    lensViewLoading,
    cardMoodBoardLinks,
    awaitingReplies,
    aiSummary,
    aiRisks,
    aiEstimation,
    aiDraft,
    aiLoading,
    executiveReport,
    revenueProjection,
    workloadAnalytics,

    // Card Emails
    fetchCardEmails,
    linkEmailToCard,
    unlinkEmail,
    updateReplyStatus,
    convertEmailToCard,
    fetchAwaitingReplies,

    // Email Rules
    fetchEmailRules,
    createEmailRule,
    updateEmailRule,
    deleteEmailRule,
    duplicateEmailRule,
    runEmailRule,

    // Card Financials
    fetchCardFinancials,
    updateCardFinancials,
    fetchBoardFinancialSummary,
    fetchGlobalFinancials,

    // Client Health
    fetchBoardClientHealth,

    // Automations
    fetchAutomationRules,
    createAutomationRule,
    updateAutomationRule,
    deleteAutomationRule,
    fetchAutomationLog,
    fetchBoardAutomationLog,

    // Timeline
    fetchCardTimeline,

    // Multi-Lens
    fetchRevenueView,
    fetchTimeView,
    fetchClientView,

    // MoodBoard
    linkMoodBoardFrame,
    unlinkMoodBoardFrame,
    fetchCardMoodBoardLinks,
    importMoodBoardAsCards,

    // AI
    aiSummarize,
    aiRiskReport,
    aiEstimate,
    aiDraftClientUpdate,

    // Executive
    fetchExecutiveReport,
    fetchRevenueProjection,
    fetchWorkloadAnalytics,

    // Cleanup
    clearCardData,
    clearBoardData,
  }
})

