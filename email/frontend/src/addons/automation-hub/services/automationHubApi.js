import api from '@/services/api'
import axios from 'axios'

const BASE = '/automation-hub'
const DRIVE_LOCAL_URL = 'http://127.0.0.1:47891'

export default {
  // Workflows
  listWorkflows(params = {}) {
    return api.get(`${BASE}/workflows`, { params })
  },
  getWorkflow(id) {
    return api.get(`${BASE}/workflows/${id}`)
  },
  createWorkflow(data) {
    return api.post(`${BASE}/workflows`, data)
  },
  updateWorkflow(id, data) {
    return api.put(`${BASE}/workflows/${id}`, data)
  },
  deleteWorkflow(id) {
    return api.delete(`${BASE}/workflows/${id}`)
  },
  toggleWorkflow(id) {
    return api.post(`${BASE}/workflows/${id}/toggle`)
  },
  duplicateWorkflow(id) {
    return api.post(`${BASE}/workflows/${id}/duplicate`)
  },

  // Execution
  executeWorkflow(id, data = {}) {
    return api.post(`${BASE}/workflows/${id}/execute`, data)
  },
  testWorkflow(id, data = {}) {
    return api.post(`${BASE}/workflows/${id}/test`, data)
  },
  listExecutions(workflowId, params = {}) {
    return api.get(`${BASE}/workflows/${workflowId}/executions`, { params })
  },
  getExecution(executionId) {
    return api.get(`${BASE}/executions/${executionId}`)
  },
  getNodeExecutions(executionId) {
    return api.get(`${BASE}/executions/${executionId}/nodes`)
  },

  // Node registry
  getNodeRegistry() {
    return api.get(`${BASE}/node-registry`)
  },

  // Connections
  getConnections() {
    return api.get(`${BASE}/connections`)
  },
  saveConnection(data) {
    return api.post(`${BASE}/connections`, data)
  },
  disconnectProvider(data) {
    return api.post(`${BASE}/connections/disconnect`, data)
  },

  // Trello
  getTrelloAuthUrl() {
    return api.get(`${BASE}/trello/auth-url`)
  },
  saveTrelloToken(token) {
    return api.post(`${BASE}/trello/save-token`, { token })
  },

  // Local printer access via FlowOneDrive (no auth -- public localhost endpoint)
  async getLocalPrinters() {
    try {
      const res = await axios.get(`${DRIVE_LOCAL_URL}/printers`, { timeout: 2000 })
      return res.data?.printers || []
    } catch {
      return null
    }
  },

  async checkDriveStatus() {
    try {
      const res = await axios.get(`${DRIVE_LOCAL_URL}/status`, { timeout: 2000 })
      return res.data?.running === true
    } catch {
      return false
    }
  },
}
