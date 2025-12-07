<template>
  <Dialog
    :visible="true"
    modal
    :header="dialogHeader"
    :style="{ width: hasForm ? '1100px' : '700px' }"
    :maximizable="false"
    :closable="true"
    @update:visible="handleClose"
    :pt="{
      root: { class: 'dark-mode' },
      header: { class: 'border-b border-surface-700' },
      headerTitle: { class: 'text-xl flex-1 text-center' },
      content: { class: 'p-4' }
    }"
  >
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
      <div class="flex justify-center mt-6">
        <Button label="Close" outlined @click="handleClose" />
      </div>
    </div>

    <!-- Facility & Apparatus Form -->
    <FacilityApparatusForm
      v-else-if="section === 'facilityApparatus' && !readonly"
      :initial-data="data"
      @submit="handleFormSubmit"
      @cancel="handleClose"
    />

    <!-- Facility & Apparatus View (readonly) -->
    <div v-else-if="section === 'facilityApparatus' && readonly">
      <FacilityApparatusView :data="data" />
      <div class="flex justify-center mt-6">
        <Button label="Close" outlined @click="handleClose" />
      </div>
    </div>

    <!-- Experimental Setup Form -->
    <ExperimentalSetupForm
      v-else-if="section === 'experiment' && !readonly"
      :initial-data="data"
      @submit="handleFormSubmit"
      @cancel="handleClose"
    />

    <!-- Experimental Setup View (readonly) -->
    <div v-else-if="section === 'experiment' && readonly">
      <ExperimentalSetupView :data="data" />
      <div class="flex justify-center mt-6">
        <Button label="Close" outlined @click="handleClose" />
      </div>
    </div>

    <!-- DAQ Form -->
    <DAQForm
      v-else-if="section === 'daq' && !readonly"
      :initial-data="data"
      @submit="handleFormSubmit"
      @cancel="handleClose"
    />

    <!-- DAQ View (readonly) -->
    <div v-else-if="section === 'daq' && readonly">
      <DAQView :data="data" />
      <div class="flex justify-center mt-6">
        <Button label="Close" outlined @click="handleClose" />
      </div>
    </div>

    <!-- Placeholder for other sections -->
    <div v-else class="text-center p-4">
      <p class="text-lg mb-4">{{ sectionTitle }} Form</p>
      <p class="text-surface-400 mb-6">
        This section will contain the full {{ sectionTitle.toLowerCase() }} form with all LAPS-compliant fields.
      </p>

      <!-- Show current data if any -->
      <div v-if="hasData" class="text-left">
        <div class="text-sm font-semibold mb-2 text-surface-400">Current Data:</div>
        <div class="p-3 rounded-lg overflow-auto max-h-64 bg-surface-900">
          <pre class="text-xs">{{ JSON.stringify(data, null, 2) }}</pre>
        </div>
      </div>

      <p class="text-sm text-surface-500 mt-4 italic">
        Full form implementation coming soon...
      </p>

      <!-- Placeholder Footer for non-sample sections -->
      <div class="flex justify-center gap-3 mt-6">
        <Button
          :label="readonly ? 'Close' : 'Cancel'"
          severity="secondary"
          outlined
          @click="handleClose"
        />
        <Button
          v-if="!readonly"
          :label="'Save ' + sectionTitle"
          @click="handleSave"
        />
      </div>
    </div>
  </Dialog>
</template>

<script setup>
import { computed } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import SampleForm from '@/components/forms/SampleForm.vue'
import SampleView from '@/components/views/SampleView.vue'
import FacilityApparatusForm from '@/components/forms/FacilityApparatusForm.vue'
import FacilityApparatusView from '@/components/views/FacilityApparatusView.vue'
import ExperimentalSetupForm from '@/components/forms/ExperimentalSetupForm.vue'
import ExperimentalSetupView from '@/components/views/ExperimentalSetupView.vue'
import DAQForm from '@/components/forms/DAQForm.vue'
import DAQView from '@/components/views/DAQView.vue'

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

// Dialog headers (uppercase, stylized)
const dialogHeaders = {
  sample: 'SAMPLE INFO',
  facilityApparatus: 'FACILITY & APPARATUS',
  experiment: 'EXPERIMENTAL SETUP',
  daq: 'DATA ACQUISITION',
  protocol: 'PROTOCOL & CONTROLLED VARIABLES',
  data: 'DATA'
}

// Sections that have real form implementations
const sectionsWithForms = ['sample', 'facilityApparatus', 'experiment', 'daq']

const sectionTitle = computed(() => sectionTitles[props.section] || props.section)
const dialogHeader = computed(() => dialogHeaders[props.section] || props.section.toUpperCase())

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
