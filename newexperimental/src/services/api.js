import axios from 'axios'

// Create axios instance with default config
const api = axios.create({
  baseURL: window.STRABO_CONFIG?.apiPath || '/expdb',
  headers: {
    'Content-Type': 'application/json'
  },
  withCredentials: true // Include cookies for session auth
})

// Response interceptor for error handling
api.interceptors.response.use(
  response => response.data,
  error => {
    console.error('API Error:', error.response?.data || error.message)

    // Handle session expiry
    if (error.response?.status === 401) {
      window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
    }

    return Promise.reject(error)
  }
)

// Project Service
export const projectService = {
  list: () => api.get('/myprojects'),

  get: (ppk) => api.get(`/project/${ppk}`),

  create: (data) => api.post('/project', {
    name: data.name,
    notes: data.description || ''
  }),

  update: (ppk, data) => api.put(`/project/${ppk}`, {
    name: data.name,
    notes: data.description || ''
  }),

  delete: (ppk) => api.delete(`/project/${ppk}`)
}

// Experiment Service
export const experimentService = {
  list: () => api.get('/myexperiments'),

  get: (e) => api.get(`/experiment/${e}`),

  create: (ppk, data) => api.post('/experiment', {
    project_pkey: ppk,
    ...data
  }),

  update: (e, data) => api.put(`/experiment/${e}`, data),

  delete: (e) => api.delete(`/experiment/${e}`),

  download: (e) => api.get(`/experiment/${e}/download`)
}

// Facility Service
export const facilityService = {
  list: () => api.get('/facilities'),

  get: (f) => api.get(`/facility/${f}`),

  create: (data) => api.post('/facility', data),

  update: (f, data) => api.put(`/facility/${f}`, data),

  delete: (f) => api.delete(`/facility/${f}`)
}

// Apparatus Service
export const apparatusService = {
  list: () => api.get('/apparatuses'),

  listByFacility: (f) => api.get(`/facility/${f}/apparatuses`),

  get: (a) => api.get(`/apparatus/${a}`),

  create: (f, data) => api.post('/apparatus', {
    facility_pkey: f,
    ...data
  }),

  update: (a, data) => api.put(`/apparatus/${a}`, data),

  delete: (a) => api.delete(`/apparatus/${a}`)
}

// File Upload Service
export const uploadService = {
  upload: (file, type = 'document') => {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('type', type)

    return api.post('/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    })
  }
}

export default api
