import api from './api'

export default {
  /**
   * Create a new conversation
   */
  async createConversation(title, contextType = 'general', contextData = null) {
    const response = await api.post('/api/ai-helper/conversations', { 
      title, 
      context_type: contextType,
      context_data: contextData 
    })
    return response.data.conversation
  },

  /**
   * Get user's conversations
   */
  async getConversations() {
    const response = await api.get('/api/ai-helper/conversations')
    return response.data.conversations
  },

  /**
   * Get conversation with messages
   */
  async getConversation(id) {
    const response = await api.get(`/api/ai-helper/conversations/${id}`)
    return response.data.conversation
  },

  /**
   * Delete a conversation
   */
  async deleteConversation(id) {
    const response = await api.delete(`/api/ai-helper/conversations/${id}`)
    return response.data
  },

  /**
   * Send message to AI
   */
  async sendMessage(conversationId, message, context = {}) {
    const response = await api.post(`/api/ai-helper/conversations/${conversationId}/messages`, {
      message,
      context
    })
    return response.data
  },

  /**
   * Get AI Helper settings
   */
  async getSettings() {
    const response = await api.get('/api/ai-helper/settings')
    return response.data.settings
  },

  /**
   * Update AI Helper settings
   */
  async updateSettings(settings) {
    const response = await api.put('/api/ai-helper/settings', { settings })
    return response.data
  },

  /**
   * Analyze logs with AI
   */
  async analyzeLogs(logs, service) {
    const response = await api.post('/api/ai-helper/analyze-logs', { logs, service })
    return response.data
  },

  /**
   * Analyze config with AI
   */
  async analyzeConfig(content, service, filePath = null) {
    const response = await api.post('/api/ai-helper/analyze-config', { 
      content, 
      service,
      file_path: filePath 
    })
    return response.data
  },

  /**
   * Get cached issues
   */
  async getCachedIssues(serverId = null, service = null, resolved = false) {
    const params = {}
    if (serverId) params.server_id = serverId
    if (service) params.service = service
    if (resolved) params.resolved = 'true'
    const response = await api.get('/api/ai-helper/cached-issues', { params })
    return response.data.issues
  },

  /**
   * Mark issue as resolved
   */
  async resolveIssue(issueId) {
    const response = await api.post(`/api/ai-helper/cached-issues/${issueId}/resolve`)
    return response.data
  },
}

