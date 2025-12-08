<template>
  <Dialog
    :visible="visible"
    modal
    :header="detailMode ? detailTitle : 'Choose Apparatus'"
    :style="{ width: '900px', maxWidth: '95vw' }"
    :closable="true"
    @update:visible="handleClose"
    :pt="{
      header: { class: 'dialog-header' },
      headerTitle: { class: 'text-xl flex-1 text-center' }
    }"
  >
    <div class="apparatus-modal-content">
      <!-- Detail View Mode -->
      <div v-if="detailMode">
        <button class="back-btn" @click="closeDetail">
          ← Back to List
        </button>

        <div v-if="detailLoading" class="text-center py-8">
          <p class="text-gray-400">Loading details...</p>
        </div>

        <div v-else-if="detailData" class="detail-content">
          <!-- Facility Detail -->
          <template v-if="detailType === 'facility'">
            <div class="detail-section">
              <h3 class="detail-section-title">Facility Information</h3>
              <div class="detail-grid">
                <div class="detail-field" v-if="detailData.name">
                  <span class="detail-label">Name</span>
                  <span class="detail-value">{{ detailData.name }}</span>
                </div>
                <div class="detail-field" v-if="detailData.type">
                  <span class="detail-label">Type</span>
                  <span class="detail-value">{{ detailData.type }}</span>
                </div>
                <div class="detail-field" v-if="detailData.institute">
                  <span class="detail-label">Institute</span>
                  <span class="detail-value">{{ detailData.institute }}</span>
                </div>
                <div class="detail-field" v-if="detailData.department">
                  <span class="detail-label">Department</span>
                  <span class="detail-value">{{ detailData.department }}</span>
                </div>
                <div class="detail-field" v-if="detailData.website">
                  <span class="detail-label">Website</span>
                  <span class="detail-value">{{ detailData.website }}</span>
                </div>
                <div class="detail-field detail-full" v-if="detailData.description">
                  <span class="detail-label">Description</span>
                  <span class="detail-value">{{ detailData.description }}</span>
                </div>
              </div>
            </div>

            <div class="detail-section" v-if="hasAddress(detailData)">
              <h4 class="detail-subsection-title">Address</h4>
              <div class="detail-grid">
                <div class="detail-field" v-if="detailData.address?.street">
                  <span class="detail-label">Street</span>
                  <span class="detail-value">{{ detailData.address.street }}</span>
                </div>
                <div class="detail-field" v-if="detailData.address?.city">
                  <span class="detail-label">City</span>
                  <span class="detail-value">{{ detailData.address.city }}</span>
                </div>
                <div class="detail-field" v-if="detailData.address?.state">
                  <span class="detail-label">State</span>
                  <span class="detail-value">{{ detailData.address.state }}</span>
                </div>
                <div class="detail-field" v-if="detailData.address?.country">
                  <span class="detail-label">Country</span>
                  <span class="detail-value">{{ detailData.address.country }}</span>
                </div>
              </div>
            </div>

            <div class="detail-section" v-if="hasContact(detailData)">
              <h4 class="detail-subsection-title">Contact</h4>
              <div class="detail-grid">
                <div class="detail-field" v-if="detailData.contact?.firstname || detailData.contact?.lastname">
                  <span class="detail-label">Name</span>
                  <span class="detail-value">{{ [detailData.contact?.firstname, detailData.contact?.lastname].filter(Boolean).join(' ') }}</span>
                </div>
                <div class="detail-field" v-if="detailData.contact?.email">
                  <span class="detail-label">Email</span>
                  <span class="detail-value">{{ detailData.contact.email }}</span>
                </div>
                <div class="detail-field" v-if="detailData.contact?.phone">
                  <span class="detail-label">Phone</span>
                  <span class="detail-value">{{ detailData.contact.phone }}</span>
                </div>
              </div>
            </div>
          </template>

          <!-- Apparatus Detail -->
          <template v-else-if="detailType === 'apparatus'">
            <div class="detail-section">
              <h3 class="detail-section-title">Apparatus Information</h3>
              <div class="detail-grid">
                <div class="detail-field" v-if="detailData.name">
                  <span class="detail-label">Name</span>
                  <span class="detail-value">{{ detailData.name }}</span>
                </div>
                <div class="detail-field" v-if="detailData.type">
                  <span class="detail-label">Type</span>
                  <span class="detail-value">{{ detailData.type }}</span>
                </div>
                <div class="detail-field" v-if="detailData.location">
                  <span class="detail-label">Location</span>
                  <span class="detail-value">{{ detailData.location }}</span>
                </div>
                <div class="detail-field" v-if="detailData.id">
                  <span class="detail-label">ID</span>
                  <span class="detail-value">{{ detailData.id }}</span>
                </div>
                <div class="detail-field detail-full" v-if="detailData.description">
                  <span class="detail-label">Description</span>
                  <span class="detail-value">{{ detailData.description }}</span>
                </div>
              </div>
            </div>

            <div class="detail-section" v-if="detailData.features?.length > 0">
              <h4 class="detail-subsection-title">Features</h4>
              <div class="feature-tags">
                <span v-for="feature in detailData.features" :key="feature" class="feature-tag">
                  {{ feature }}
                </span>
              </div>
            </div>

            <div class="detail-section" v-if="detailData.parameters?.length > 0">
              <h4 class="detail-subsection-title">Parameters</h4>
              <table class="detail-table">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Min</th>
                    <th>Max</th>
                    <th>Unit</th>
                    <th>Note</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(param, idx) in detailData.parameters" :key="idx">
                    <td>{{ param.type || '-' }}</td>
                    <td>{{ param.min || '-' }}</td>
                    <td>{{ param.max || '-' }}</td>
                    <td>{{ param.unit || '-' }}</td>
                    <td>{{ param.note || '-' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </div>
      </div>

      <!-- List View Mode -->
      <div v-else>
        <p class="contact-note">
          If you need an institute added to the list below, please contact
          <a href="mailto:strabospot@gmail.com?subject=Need Institute Added to StraboSpot Experimental Apparatus Repository">
            strabospot@gmail.com
          </a>
        </p>

        <div class="apparatus-list">
          <div v-if="loading" class="text-center py-8">
            <p class="text-gray-400">Loading apparatus repository...</p>
          </div>

          <div v-else-if="error" class="text-center py-8">
            <p class="text-red-500">{{ error }}</p>
          </div>

          <div v-else-if="facilities.length === 0" class="text-center py-8">
            <p class="text-gray-400">No facilities with apparatus found.</p>
          </div>

          <div v-else>
            <div v-for="facility in facilities" :key="facility.id" class="facility-group">
              <div class="facility-institute">{{ facility.institute }}</div>
              <div class="facility-header">
                <span class="facility-name">{{ facility.name }}</span>
                <button class="view-btn" @click="showFacilityDetail(facility.id)">
                  View
                </button>
              </div>

              <div class="apparatus-table">
                <table>
                  <thead>
                    <tr>
                      <th style="width: 80px;"></th>
                      <th style="width: 80px;"></th>
                      <th>Apparatus Name</th>
                      <th>Apparatus Type</th>
                      <th>Last Modified</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="apparatus in facility.apparatuses" :key="apparatus.id">
                      <td class="action-cell">
                        <button
                          class="select-btn"
                          @click="handleSelect(facility.id, apparatus.id)"
                        >
                          Select
                        </button>
                      </td>
                      <td class="action-cell">
                        <button class="view-btn" @click="showApparatusDetail(apparatus.id)">
                          View
                        </button>
                      </td>
                      <td>{{ apparatus.name }}</td>
                      <td>{{ apparatus.type }}</td>
                      <td>{{ apparatus.modified_timestamp }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Dialog>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import Dialog from 'primevue/dialog'
import { bulkLoadService } from '@/services/api'

const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['close', 'select'])

// List view state
const loading = ref(false)
const error = ref(null)
const facilities = ref([])

// Detail view state
const detailMode = ref(false)
const detailType = ref(null) // 'facility' or 'apparatus'
const detailData = ref(null)
const detailLoading = ref(false)

// Dynamic title for detail view
const detailTitle = computed(() => {
  if (!detailData.value) return 'Loading...'
  if (detailType.value === 'facility') {
    return `Facility: ${detailData.value.name || 'Details'}`
  }
  return `Apparatus: ${detailData.value.name || 'Details'}`
})

// Load apparatus list when modal becomes visible
watch(() => props.visible, async (newVal) => {
  if (newVal) {
    // Reset detail view when opening
    detailMode.value = false
    detailType.value = null
    detailData.value = null
    await loadApparatusList()
  }
})

const loadApparatusList = async () => {
  loading.value = true
  error.value = null

  try {
    const data = await bulkLoadService.getApparatusList()
    facilities.value = data.facilities || []
  } catch (err) {
    error.value = 'Failed to load apparatus repository'
    console.error(err)
  } finally {
    loading.value = false
  }
}

// Show facility detail
const showFacilityDetail = async (facilityId) => {
  detailMode.value = true
  detailType.value = 'facility'
  detailLoading.value = true
  detailData.value = null

  try {
    detailData.value = await bulkLoadService.getApprepoFacility(facilityId)
  } catch (err) {
    console.error('Failed to load facility details:', err)
    detailData.value = { error: 'Failed to load facility details' }
  } finally {
    detailLoading.value = false
  }
}

// Show apparatus detail
const showApparatusDetail = async (apparatusId) => {
  detailMode.value = true
  detailType.value = 'apparatus'
  detailLoading.value = true
  detailData.value = null

  try {
    detailData.value = await bulkLoadService.getApprepoApparatus(apparatusId)
  } catch (err) {
    console.error('Failed to load apparatus details:', err)
    detailData.value = { error: 'Failed to load apparatus details' }
  } finally {
    detailLoading.value = false
  }
}

// Close detail view, return to list
const closeDetail = () => {
  detailMode.value = false
  detailType.value = null
  detailData.value = null
}

// Handle modal close - reset state
const handleClose = () => {
  detailMode.value = false
  detailType.value = null
  detailData.value = null
  emit('close')
}

// Helper functions for checking data
const hasAddress = (data) => {
  const a = data?.address
  return a && (a.street || a.city || a.state || a.country)
}

const hasContact = (data) => {
  const c = data?.contact
  return c && (c.firstname || c.lastname || c.email || c.phone)
}

const handleSelect = async (facilityId, apparatusId) => {
  loading.value = true
  error.value = null

  try {
    // Fetch both facility and apparatus data
    const [facilityData, apparatusData] = await Promise.all([
      bulkLoadService.getApprepoFacility(facilityId),
      bulkLoadService.getApprepoApparatus(apparatusId)
    ])

    // Emit the loaded data
    emit('select', {
      facility: facilityData,
      apparatus: apparatusData
    })
    emit('close')
  } catch (err) {
    error.value = 'Failed to load facility/apparatus data'
    console.error(err)
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.apparatus-modal-content {
  max-height: 70vh;
  overflow-y: auto;
}

/* Back button */
.back-btn {
  display: inline-flex;
  align-items: center;
  padding: 0.5rem 1rem;
  margin-bottom: 1rem;
  background-color: var(--p-surface-700);
  color: #ffffff;
  border: 1px solid var(--p-surface-600);
  border-radius: 4px;
  font-size: 0.9rem;
  cursor: pointer;
  transition: background-color 0.2s;
}

.back-btn:hover {
  background-color: var(--p-surface-600);
}

/* Detail view styles */
.detail-content {
  padding: 0.5rem 0;
}

.detail-section {
  margin-bottom: 1.5rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid var(--p-surface-600);
}

.detail-section:last-child {
  border-bottom: none;
  margin-bottom: 0;
}

.detail-section-title {
  font-size: 1.1rem;
  font-weight: 600;
  color: #ffffff;
  margin-bottom: 1rem;
  text-transform: uppercase;
}

.detail-subsection-title {
  font-size: 1rem;
  font-weight: 600;
  color: #d1d5db;
  margin-bottom: 0.75rem;
}

.detail-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;
}

.detail-field {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.detail-field.detail-full {
  grid-column: span 2;
}

.detail-label {
  font-size: 0.8rem;
  font-weight: 500;
  color: #9ca3af;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.detail-value {
  font-size: 1rem;
  color: #ffffff;
}

.feature-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.feature-tag {
  display: inline-block;
  padding: 0.375rem 0.625rem;
  background-color: var(--p-surface-700);
  border: 1px solid var(--p-surface-600);
  border-radius: 4px;
  font-size: 0.875rem;
  color: #ffffff;
}

.detail-table {
  width: 100%;
  border-collapse: collapse;
}

.detail-table th,
.detail-table td {
  padding: 0.5rem 0.75rem;
  text-align: left;
  border-bottom: 1px solid var(--p-surface-600);
}

.detail-table th {
  background-color: var(--p-surface-700);
  font-weight: 600;
  color: #d1d5db;
  font-size: 0.85rem;
}

.detail-table td {
  color: #ffffff;
  font-size: 0.9rem;
}

/* List view styles */
.contact-note {
  text-align: center;
  font-size: 0.9rem;
  color: #d1d5db;
  margin-bottom: 1.5rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid var(--p-surface-600);
}

.contact-note a {
  color: #60a5fa;
  text-decoration: none;
}

.contact-note a:hover {
  text-decoration: underline;
}

.facility-group {
  margin-bottom: 1.5rem;
}

.facility-institute {
  font-size: 1.1rem;
  font-weight: 600;
  color: #9ca3af;
  margin-bottom: 0.25rem;
}

.facility-header {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 0.5rem;
}

.facility-name {
  font-size: 1rem;
  font-weight: 600;
  color: #ffffff;
}

.view-btn {
  padding: 0.25rem 0.5rem;
  background-color: transparent;
  color: #60a5fa;
  border: none;
  font-size: 0.9rem;
  font-weight: 600;
  cursor: pointer;
  transition: color 0.2s;
}

.view-btn:hover {
  color: #93c5fd;
  text-decoration: underline;
}

.apparatus-table {
  overflow-x: auto;
  margin-bottom: 1rem;
}

.apparatus-table table {
  width: 100%;
  border-collapse: collapse;
}

.apparatus-table th,
.apparatus-table td {
  padding: 0.5rem 0.75rem;
  text-align: left;
  border-bottom: 1px solid var(--p-surface-600);
  white-space: nowrap;
}

.apparatus-table th {
  background-color: var(--p-surface-700);
  color: #d1d5db;
  font-weight: 600;
  font-size: 0.85rem;
}

.apparatus-table td {
  color: #ffffff;
  font-size: 0.85rem;
}

.apparatus-table tbody tr:hover {
  background-color: var(--p-surface-700);
}

.action-cell {
  text-align: center;
  vertical-align: top;
}

.select-btn {
  padding: 0.25rem 0.5rem;
  background-color: #dc3545;
  color: white;
  border: none;
  border-radius: 4px;
  font-size: 0.8rem;
  cursor: pointer;
  transition: background-color 0.2s;
}

.select-btn:hover {
  background-color: #c82333;
}

@media (max-width: 768px) {
  .detail-grid {
    grid-template-columns: 1fr;
  }

  .detail-field.detail-full {
    grid-column: span 1;
  }
}
</style>
