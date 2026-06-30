import api from '@/services/api'

export async function fetchTimeBreakdown(params = {}) {
  const query = new URLSearchParams()

  if (params.period) query.set('period', params.period)
  if (params.start_date) query.set('start_date', params.start_date)
  if (params.end_date) query.set('end_date', params.end_date)
  if (params.client_id) query.set('client_id', params.client_id)
  if (params.board_id) query.set('board_id', params.board_id)
  if (params.user_email) query.set('user_email', params.user_email)

  const qs = query.toString()
  const url = `/project-hub/time-breakdown${qs ? '?' + qs : ''}`
  const { data } = await api.get(url)
  return data
}
