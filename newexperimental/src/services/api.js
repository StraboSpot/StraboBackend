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
  // Get all user's projects
  list: async () => {
    const response = await fetch('/newexperimental/api/get_projects.php', {
      credentials: 'include'
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to fetch projects')
    }
    const data = await response.json()
    return data.projects
  },

  // Get single project with experiments
  get: async (ppk) => {
    const response = await fetch(`/newexperimental/api/get_project.php?id=${ppk}`, {
      credentials: 'include'
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to fetch project')
    }
    return response.json()
  },

  // Create new project
  create: async (data) => {
    const response = await fetch('/newexperimental/api/save_project.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: data.name,
        description: data.description || ''
      })
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to create project')
    }
    return response.json()
  },

  // Update existing project
  update: async (ppk, data) => {
    const response = await fetch('/newexperimental/api/save_project.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        pkey: ppk,
        name: data.name,
        description: data.description || ''
      })
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to update project')
    }
    return response.json()
  },

  // Delete project
  delete: async (ppk) => {
    const response = await fetch(`/newexperimental/api/delete_project.php?id=${ppk}`, {
      method: 'POST',
      credentials: 'include'
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to delete project')
    }
    return response.json()
  },

  // Download project as JSON
  download: (ppk) => {
    window.location.href = `/newexperimental/api/download_project.php?id=${ppk}`
  }
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
  // Get all facilities with their apparatuses (includes permission flags)
  listWithApparatuses: async () => {
    const response = await fetch('/newexperimental/api/get_facilities.php', {
      credentials: 'include'
    })
    return response.json()
  },

  // Get single facility (guest-friendly)
  get: async (f) => {
    const response = await fetch(`/newexperimental/api/get_facility.php?id=${f}`, {
      credentials: 'include'
    })
    return response.json()
  },

  create: (data) => api.post('/facility', data),

  update: (f, data) => api.put(`/facility/${f}`, data),

  delete: (f) => api.delete(`/facility/${f}`)
}

// Apparatus Service
export const apparatusService = {
  list: () => api.get('/apparatuses'),

  listByFacility: (f) => api.get(`/facility/${f}/apparatuses`),

  // Get single apparatus (guest-friendly)
  get: async (a) => {
    const response = await fetch(`/newexperimental/api/get_apparatus.php?id=${a}`, {
      credentials: 'include'
    })
    return response.json()
  },

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
