import api from '@/services/api'

export async function fetchTestSimulationPreflight() {
  const { data } = await api.get('/test-simulation/preflight')
  return data.data
}

export async function generateTestSimulation(promoteAdmin = false) {
  const { data } = await api.post('/test-simulation/generate', { promote_admin: promoteAdmin })
  return data.data
}

export async function listTestSimulationRuns() {
  const { data } = await api.get('/test-simulation/runs')
  return data.data?.runs ?? []
}

export async function deleteTestSimulationRun(runId) {
  const { data } = await api.delete(`/test-simulation/runs/${encodeURIComponent(runId)}`)
  return data.data
}

export async function deleteAllTestSimulationRuns() {
  const { data } = await api.delete('/test-simulation/runs')
  return data.data
}
