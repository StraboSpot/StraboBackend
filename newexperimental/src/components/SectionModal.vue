<template>
  <v-dialog
    :model-value="true"
    @update:model-value="handleClose"
    :max-width="hasForm ? 1000 : 700"
    scrollable
    persistent
  >
    <v-card>
      <!-- Modal Header -->
      <v-card-title class="d-flex justify-space-between align-center pa-4">
        <span class="text-h6 text-uppercase">{{ sectionTitle }}</span>
        <v-btn
          icon
          size="small"
          color="primary"
          @click="handleClose"
        >
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <v-divider />

      <!-- Modal Content -->
      <v-card-text class="pa-4">
        <!-- Sample Form -->
        <SampleForm
          v-if="section === 'sample' && !readonly"
          :initial-data="data"
          @submit="handleFormSubmit"
          @cancel="handleClose"
        />

        <!-- Sample View (readonly) -->
        <div v-else-if="section === 'sample' && readonly">
          <SampleView :data="data" />
          <div class="d-flex justify-center mt-6">
            <v-btn variant="outlined" @click="handleClose">Close</v-btn>
          </div>
        </div>

        <!-- Placeholder for other sections -->
        <div v-else class="text-center pa-4">
          <p class="text-h6 mb-4">{{ sectionTitle }} Form</p>
          <p class="text-medium-emphasis mb-6">
            This section will contain the full {{ sectionTitle.toLowerCase() }} form with all LAPS-compliant fields.
          </p>

          <!-- Show current data if any -->
          <div v-if="hasData" class="text-left">
            <div class="text-subtitle-2 mb-2">Current Data:</div>
            <v-sheet rounded class="pa-3 overflow-auto" style="max-height: 256px; background: rgba(0,0,0,0.2)">
              <pre class="text-caption">{{ JSON.stringify(data, null, 2) }}</pre>
            </v-sheet>
          </div>

          <p class="text-caption text-medium-emphasis mt-4 font-italic">
            Full form implementation coming soon...
          </p>

          <!-- Placeholder Footer for non-sample sections -->
          <div class="d-flex justify-center ga-3 mt-6">
            <v-btn variant="outlined" @click="handleClose">
              {{ readonly ? 'Close' : 'Cancel' }}
            </v-btn>
            <v-btn v-if="!readonly" color="primary" @click="handleSave">
              Save {{ sectionTitle }}
            </v-btn>
          </div>
        </div>
      </v-card-text>
    </v-card>
  </v-dialog>
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
