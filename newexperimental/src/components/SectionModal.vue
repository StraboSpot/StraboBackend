<template>
  <div class="modal-overlay" @click.self="handleClose">
    <div class="modal-container" :class="{ 'modal-container--wide': hasForm }">
      <!-- Modal Header -->
      <div class="modal-header">
        <h2 class="modal-title">{{ sectionTitle }}</h2>
        <button @click="handleClose" class="close-button">×</button>
      </div>

      <!-- Modal Content -->
      <div class="modal-content">
        <!-- Sample Form -->
        <SampleForm
          v-if="section === 'sample' && !readonly"
          :initial-data="data"
          @submit="handleFormSubmit"
          @cancel="handleClose"
        />

        <!-- Sample View (readonly) -->
        <div v-else-if="section === 'sample' && readonly" class="readonly-content">
          <SampleView :data="data" />
          <div class="flex justify-center mt-6">
            <button @click="handleClose" class="btn-secondary">Close</button>
          </div>
        </div>

        <!-- Placeholder for other sections -->
        <div v-else class="placeholder-content">
          <p class="text-lg mb-4">{{ sectionTitle }} Form</p>
          <p class="text-strabo-text-secondary mb-6">
            This section will contain the full {{ sectionTitle.toLowerCase() }} form with all LAPS-compliant fields.
          </p>

          <!-- Show current data if any -->
          <div v-if="hasData" class="data-preview">
            <h3 class="text-sm font-semibold mb-2 text-strabo-text-secondary">Current Data:</h3>
            <pre class="bg-strabo-bg-primary p-3 rounded text-xs overflow-auto max-h-64">{{ JSON.stringify(data, null, 2) }}</pre>
          </div>

          <p class="text-sm text-strabo-text-secondary mt-4 italic">
            Full form implementation coming soon...
          </p>

          <!-- Placeholder Footer for non-sample sections -->
          <div class="flex justify-center gap-3 mt-6">
            <button @click="handleClose" class="btn-secondary">
              {{ readonly ? 'Close' : 'Cancel' }}
            </button>
            <button v-if="!readonly" @click="handleSave" class="btn-primary">
              Save {{ sectionTitle }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import SampleForm from '@/components/forms/SampleForm.vue'
import SampleView from '@/components/views/SampleView.vue'

const props = defineProps({
  section: {
    type: String,
    required: true
  },
  data: {
    type: [Object, Array],
    default: () => ({})
  },
  readonly: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['close', 'save'])

const sectionTitles = {
  sample: 'Sample',
  facilityApparatus: 'Facility & Apparatus',
  experiment: 'Experimental Setup',
  daq: 'DAQ (Data Acquisition)',
  protocol: 'Protocol & Controlled Variables',
  data: 'Data'
}

// Sections that have real form implementations
const sectionsWithForms = ['sample']

const sectionTitle = computed(() => sectionTitles[props.section] || props.section)

const hasForm = computed(() => sectionsWithForms.includes(props.section))

const hasData = computed(() => {
  if (Array.isArray(props.data)) {
    return props.data.length > 0
  }
  return props.data && Object.keys(props.data).length > 0
})

const handleClose = () => {
  emit('close')
}

const handleSave = () => {
  // For placeholder sections, just pass back the existing data
  emit('save', props.section, props.data)
}

const handleFormSubmit = (formData) => {
  // Called by form components when they submit
  emit('save', props.section, formData)
}
</script>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  background-color: rgba(0, 0, 0, 0.75);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  padding: 1rem;
}

.modal-container {
  background-color: var(--strabo-bg-secondary);
  border: 1px solid var(--strabo-border);
  border-radius: 0.5rem;
  width: 100%;
  max-width: 700px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
}

.modal-container--wide {
  max-width: 950px;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid var(--strabo-border);
}

.modal-title {
  font-size: 1.25rem;
  font-weight: 600;
  color: var(--strabo-text-primary);
  text-transform: uppercase;
  font-family: 'Raleway', sans-serif;
}

.close-button {
  width: 2rem;
  height: 2rem;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #f4511e;
  color: white;
  border: none;
  border-radius: 4px;
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
  transition: background-color 0.2s;
}

.close-button:hover {
  background-color: #d84315;
}

.modal-content {
  flex: 1;
  overflow-y: auto;
  padding: 1.5rem;
}

.placeholder-content {
  text-align: center;
  padding: 2rem;
}

.data-preview {
  text-align: left;
  margin-top: 1rem;
}

.modal-footer {
  display: flex;
  justify-content: center;
  gap: 1rem;
  padding: 1rem 1.5rem;
  border-top: 1px solid var(--strabo-border);
}
</style>
