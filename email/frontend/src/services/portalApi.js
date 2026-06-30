/**
 * Portal API - Axios instance for Client Portal endpoints
 * 
 * Uses X-Portal-Token header (stored in localStorage) instead of 
 * the internal JWT Bearer token used by the main app.
 */
import axios from 'axios'
import { getApiOrigin } from './serverRegistry'

const portalApi = axios.create({
  baseURL: getApiOrigin() + '/api',
  headers: {
    'Content-Type': 'application/json',
  },
})

// Request interceptor: add portal session token
portalApi.interceptors.request.use((config) => {
  // Resolve the backend per request so native targets the right deployment.
  config.baseURL = getApiOrigin() + '/api'
  const portalToken = localStorage.getItem('portal_session_token')
  if (portalToken) {
    config.headers['X-Portal-Token'] = portalToken
  }
  return config
})

// Response interceptor: handle 401 (session expired/revoked)
portalApi.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Session expired or revoked — clear token and redirect to portal login
      localStorage.removeItem('portal_session_token')
      localStorage.removeItem('portal_user')

      const path = window.location.pathname
      if (path.startsWith('/portal') && !path.startsWith('/portal/auth/')) {
        window.location.href = '/portal'
      }
    }
    return Promise.reject(error)
  }
)

export default portalApi

