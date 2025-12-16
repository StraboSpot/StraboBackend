<template>
  <div class="load-data-bar">
    <span class="load-data-label">Load Data:</span>

    <button
      class="load-data-btn"
      @click="$emit('load-from-previous')"
    >
      from Previous Experiment
    </button>

    <button
      class="load-data-btn"
      @click="triggerFileUpload"
    >
      From JSON File
    </button>

    <button
      v-if="showApparatusRepo"
      class="load-data-btn"
      @click="$emit('load-from-repository')"
    >
      From Apparatus Repository
    </button>

    <button
      class="load-data-btn"
      @click="handleClear"
    >
      Clear Interface
    </button>

    <!-- Hidden file input -->
    <input
      ref="fileInput"
      type="file"
      accept=".json,.txt"
      style="display: none"
      @change="handleFileSelect"
    />
  </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({
  section: {
    type: String,
    required: true
    // 'facilityApparatus', 'daq', 'sample', 'experiment', 'protocol', 'data'
  },
  showApparatusRepo: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['load-from-previous', 'load-from-json', 'load-from-repository', 'clear'])

const fileInput = ref(null)

const triggerFileUpload = () => {
  fileInput.value?.click()
}

const handleFileSelect = (event) => {
  const file = event.target.files[0]
  if (!file) return

  const reader = new FileReader()
  reader.onload = (e) => {
    try {
      const content = e.target.result
      const data = JSON.parse(content)

      // Validate JSON structure - must have at least one LAPS section
      if (!data.facility && !data.apparatus && !data.daq && !data.sample && !data.experiment && !data.data) {
        alert('Invalid JSON File. The file must contain at least one LAPS section (facility, apparatus, daq, sample, experiment, or data).')
        return
      }

      emit('load-from-json', data)
    } catch (err) {
      alert('Invalid JSON File. Could not parse the file contents.')
      console.error('JSON parse error:', err)
    }
  }
  reader.readAsText(file)

  // Reset the input so the same file can be selected again
  event.target.value = ''
}

const handleClear = () => {
  if (confirm('Are you sure you want to clear all data in this section?')) {
    emit('clear')
  }
}
</script>

<style scoped>
.load-data-bar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  background-color: var(--p-surface-800, #374151);
  border-radius: 4px;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}

.load-data-label {
  color: #d1d5db;
  font-size: 0.9rem;
  font-weight: 500;
  margin-right: 0.5rem;
}

.load-data-btn {
  padding: 0.5rem 1rem;
  background-color: var(--p-surface-700, #4b5563);
  color: #e5e7eb;
  border: 1px solid var(--p-surface-600, #6b7280);
  border-radius: 4px;
  font-size: 0.9rem;
  font-weight: 500;
  cursor: pointer;
  transition: background-color 0.2s, border-color 0.2s;
  white-space: nowrap;
}

.load-data-btn:hover {
  background-color: var(--p-surface-600, #6b7280);
  border-color: var(--p-surface-500, #9ca3af);
}

.load-data-btn:active {
  background-color: var(--p-surface-500, #6b7280);
}
</style>
