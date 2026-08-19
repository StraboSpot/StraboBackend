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
    const response = await fetch('/experimental/api/get_projects.php', {
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
    const response = await fetch(`/experimental/api/get_project.php?id=${ppk}`, {
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
    const response = await fetch('/experimental/api/save_project.php', {
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
    const response = await fetch('/experimental/api/save_project.php', {
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
    const response = await fetch(`/experimental/api/delete_project.php?id=${ppk}`, {
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
    window.location.href = `/experimental/api/download_project.php?id=${ppk}`
  }
}

// Experiment Service
export const experimentService = {
  // Get single experiment with all LAPS data
  get: async (e) => {
    const response = await fetch(`/experimental/api/get_experiment.php?id=${e}`, {
      credentials: 'include'
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to fetch experiment')
    }
    return response.json()
  },

  // Create new experiment
  create: async (ppk, data = {}) => {
    const response = await fetch('/experimental/api/save_experiment.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        project_pkey: ppk,
        experiment_id: data.experiment_id || '',
        data: data.data || {}
      })
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to create experiment')
    }
    return response.json()
  },

  // Update existing experiment
  update: async (e, data) => {
    const response = await fetch('/experimental/api/save_experiment.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        pkey: e,
        experiment_id: data.experiment_id || '',
        data: data.data || {}
      })
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to update experiment')
    }
    return response.json()
  },

  // Delete experiment
  delete: async (e) => {
    const response = await fetch(`/experimental/api/delete_experiment.php?id=${e}`, {
      method: 'POST',
      credentials: 'include'
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to delete experiment')
    }
    return response.json()
  },

  // Download experiment as JSON
  download: (e) => {
    window.location.href = `/experimental/api/download_experiment.php?id=${e}`
  }
}

// Facility Service
export const facilityService = {
  // Get all facilities with their apparatuses (includes permission flags)
  listWithApparatuses: async () => {
    const response = await fetch('/experimental/api/get_facilities.php', {
      credentials: 'include'
    })
    return response.json()
  },

  // Get single facility (guest-friendly)
  get: async (f) => {
    const response = await fetch(`/experimental/api/get_facility.php?id=${f}`, {
      credentials: 'include'
    })
    return response.json()
  },

  // Create new facility
  create: async (data) => {
    const response = await fetch('/experimental/api/save_facility.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    })
    return response.json()
  },

  // Update existing facility
  update: async (f, data) => {
    const response = await fetch('/experimental/api/save_facility.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...data, pkey: f })
    })
    return response.json()
  },

  delete: (f) => api.delete(`/facility/${f}`)
}

// Apparatus Service
export const apparatusService = {
  list: () => api.get('/apparatuses'),

  listByFacility: (f) => api.get(`/facility/${f}/apparatuses`),

  // Get single apparatus (guest-friendly)
  get: async (a) => {
    const response = await fetch(`/experimental/api/get_apparatus.php?id=${a}`, {
      credentials: 'include'
    })
    return response.json()
  },

  // Create new apparatus
  create: async (f, data) => {
    const response = await fetch('/experimental/api/save_apparatus.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...data, facility_pkey: f })
    })
    return response.json()
  },

  // Update existing apparatus
  update: async (a, data) => {
    const response = await fetch('/experimental/api/save_apparatus.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...data, pkey: a })
    })
    return response.json()
  },

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

// Bulk Load Service - for loading data from various sources
export const bulkLoadService = {
  // Get all user's experiments for "Load from Previous Experiment" feature
  getMyExperiments: async () => {
    const response = await fetch('/experimental/api/get_my_experiments.php', {
      credentials: 'include'
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to fetch experiments')
    }
    return response.json()
  },

  // Get example experiment data for "New User? Load Example Data" feature
  getExampleData: async () => {
    const response = await fetch('/experimental/data/example_experiment.json')
    if (!response.ok) {
      throw new Error('Failed to load example data')
    }
    return response.json()
  },

  // Get all facilities with apparatuses for apparatus repository selection
  getApparatusList: async () => {
    const response = await fetch('/experimental/api/get_apparatus_list.php', {
      credentials: 'include'
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to fetch apparatus list')
    }
    return response.json()
  },

  // Get single facility from apparatus repository
  getApprepoFacility: async (id) => {
    const response = await fetch(`/experimental/api/get_apprepo_facility.php?id=${id}`, {
      credentials: 'include'
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to fetch facility')
    }
    return response.json()
  },

  // Get single apparatus from apparatus repository
  getApprepoApparatus: async (id) => {
    const response = await fetch(`/experimental/api/get_apprepo_apparatus.php?id=${id}`, {
      credentials: 'include'
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to fetch apparatus')
    }
    return response.json()
  }
}

// StraboSamples Link Service - "Link Sample From StraboSamples" picker
export const sampleLinkService = {
  // List the user's StraboSamples spine samples (own + collaborated) with
  // picker flags (has_field_data / has_micro_data / experimental_link_count)
  // plus viewer_pkey to tell own from collaborated rows.
  getMySamples: async () => {
    const response = await fetch('/experimental/api/get_my_samples.php', {
      credentials: 'include'
    })
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to fetch StraboSamples list')
    }
    return response.json()
  },

  // Full spine record for prefill after a picker selection. Returns null on
  // 404 (sample gone or not readable) so callers can degrade gracefully.
  getSample: async (id, owner = null) => {
    const params = new URLSearchParams({ id })
    if (owner) params.set('owner', owner)
    const response = await fetch(`/experimental/api/get_sample.php?${params.toString()}`, {
      credentials: 'include'
    })
    if (response.status === 404) {
      return null
    }
    if (!response.ok) {
      if (response.status === 401) {
        window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
      }
      throw new Error('Failed to fetch sample')
    }
    const data = await response.json()
    return data.sample || null
  }
}

export default api
