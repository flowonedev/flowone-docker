import api from './api'

export default {
  /**
   * Create a new conversation
   */
  async createConversation(title) {
    const response = await api.post('/ai-helper/conversations', { title })
    return response.data.data.conversation
  },

  /**
   * Get user's conversations
   */
  async getConversations() {
    const response = await api.get('/ai-helper/conversations')
    return response.data.data.conversations
  },

  /**
   * Get conversation with messages
   */
  async getConversation(id) {
    const response = await api.get(`/ai-helper/conversations/${id}`)
    return response.data.data.conversation
  },

  /**
   * Delete a conversation
   */
  async deleteConversation(id) {
    const response = await api.delete(`/ai-helper/conversations/${id}`)
    return response.data.data
  },

  /**
   * Send message to AI
   */
  async sendMessage(conversationId, message, context = {}, model = null) {
    const payload = { message, context }
    if (model) {
      payload.model = model
    }
    const response = await api.post(`/ai-helper/conversations/${conversationId}/messages`, payload)
    return response.data.data
  },

  /**
   * Execute dry-run command
   */
  async dryRunCommand(command, cwd = null) {
    const response = await api.post('/ai-helper/dry-run', { command, cwd })
    return response.data.data
  },

  /**
   * Get cached issues
   */
  async getCachedIssues(service = null, resolved = false) {
    const params = {}
    if (service) params.service = service
    if (resolved) params.resolved = 'true'
    const response = await api.get('/ai-helper/cached-issues', { params })
    return response.data.data.issues
  },

  /**
   * Mark issue as resolved
   */
  async resolveIssue(issueId) {
    const response = await api.post(`/ai-helper/cached-issues/${issueId}/resolve`)
    return response.data.data
  },

  /**
   * Get available config files
   */
  async getConfigFiles(service = null) {
    const params = {}
    if (service) params.service = service
    const response = await api.get('/ai-helper/config-files', { params })
    return response.data.data.files
  },

  /**
   * Analyze config file
   */
  async analyzeConfig(service, configPath, model = null) {
    const payload = { service, path: configPath }
    if (model) payload.model = model
    const response = await api.post('/ai-helper/analyze-config', payload)
    return response.data.data
  },

  /**
   * Analyze logs
   */
  async analyzeLogs(service, logType = 'journalctl', lines = 100, model = null) {
    const payload = { service, type: logType, lines }
    if (model) payload.model = model
    const response = await api.post('/ai-helper/analyze-logs', payload)
    return response.data.data
  },

  /**
   * Get AI Helper settings
   */
  async getSettings() {
    const response = await api.get('/ai-helper/settings')
    return response.data.data.settings
  },

  /**
   * Update AI Helper settings
   */
  async updateSettings(settings) {
    const response = await api.put('/ai-helper/settings', { settings })
    return response.data.data
  },
}

