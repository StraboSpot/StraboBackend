<template>
  <Dialog
    :visible="visible"
    modal
    :header="'Choose Apparatus'"
    :style="{ width: '900px', maxWidth: '95vw' }"
    :closable="true"
    @update:visible="$emit('close')"
    :pt="{
      header: { class: 'dialog-header' },
      headerTitle: { class: 'text-xl flex-1 text-center' }
    }"
  >
    <div class="apparatus-modal-content">
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
              <a
                :href="`/newexperimental/view_facility?f=${facility.id}`"
                target="_blank"
                class="view-link"
              >
                View
              </a>
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
                      <a
                        :href="`/newexperimental/view_apparatus?a=${apparatus.id}`"
                        target="_blank"
                        class="view-link"
                      >
                        View
                      </a>
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
  </Dialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { bulkLoadService } from '@/services/api'

const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['close', 'select'])

const loading = ref(false)
const error = ref(null)
const facilities = ref([])

// Load apparatus list when modal becomes visible
watch(() => props.visible, async (newVal) => {
  if (newVal) {
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

.view-link {
  color: #60a5fa;
  text-decoration: none;
  font-size: 0.9rem;
  font-weight: 600;
}

.view-link:hover {
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
</style>
