const API_BASE = ''

let authToken = null

const api = {
  setAuthToken(token) {
    authToken = token
  },

  async request(method, endpoint, data = null, options = {}) {
    const headers = {
      'Content-Type': 'application/json',
      ...options.headers
    }

    if (authToken) {
      headers['Authorization'] = `Bearer ${authToken}`
    }

    const config = {
      method,
      headers,
    }

    if (data && ['POST', 'PUT', 'PATCH'].includes(method)) {
      config.body = JSON.stringify(data)
    }

    const response = await fetch(`${API_BASE}${endpoint}`, config)

    // Handle 401 - redirect to login
    if (response.status === 401 && !endpoint.includes('/auth/')) {
      localStorage.removeItem('token')
      localStorage.removeItem('refreshToken')
      localStorage.removeItem('user')
      window.location.href = '/login'
      throw new Error('Unauthorized')
    }

    const result = await response.json()

    if (!response.ok) {
      const error = new Error(result.error || 'Request failed')
      error.response = { data: result, status: response.status }
      throw error
    }

    return result
  },

  get(endpoint, options = {}) {
    return this.request('GET', endpoint, null, options)
  },

  post(endpoint, data, options = {}) {
    return this.request('POST', endpoint, data, options)
  },

  put(endpoint, data, options = {}) {
    return this.request('PUT', endpoint, data, options)
  },

  delete(endpoint, options = {}) {
    return this.request('DELETE', endpoint, null, options)
  },

  /**
   * Upload a file using multipart/form-data
   */
  async uploadFile(endpoint, file, fieldName = 'file', additionalData = {}, onProgress = null) {
    const formData = new FormData()
    formData.append(fieldName, file)
    
    // Add any additional data
    for (const [key, value] of Object.entries(additionalData)) {
      formData.append(key, value)
    }

    const headers = {}
    if (authToken) {
      headers['Authorization'] = `Bearer ${authToken}`
    }

    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest()
      
      xhr.upload.addEventListener('progress', (event) => {
        if (event.lengthComputable && onProgress) {
          const progress = Math.round((event.loaded / event.total) * 100)
          onProgress(progress)
        }
      })

      xhr.addEventListener('load', () => {
        try {
          const result = JSON.parse(xhr.responseText)
          if (xhr.status >= 200 && xhr.status < 300) {
            resolve(result)
          } else {
            const error = new Error(result.error || 'Upload failed')
            error.response = { data: result, status: xhr.status }
            reject(error)
          }
        } catch (e) {
          reject(new Error('Invalid response from server'))
        }
      })

      xhr.addEventListener('error', () => {
        reject(new Error('Network error during upload'))
      })

      xhr.addEventListener('abort', () => {
        reject(new Error('Upload aborted'))
      })

      xhr.open('POST', `${API_BASE}${endpoint}`)
      
      for (const [key, value] of Object.entries(headers)) {
        xhr.setRequestHeader(key, value)
      }

      xhr.send(formData)
    })
  }
}

export default api

