<template>
  <div class="bulk-load-bar">
    <button
      class="bulk-load-btn bulk-load-btn-gray"
      @click="$emit('load-from-previous')"
    >
      Load All Data from Previous Experiment
    </button>

    <button
      v-if="mode === 'add'"
      class="bulk-load-btn bulk-load-btn-green"
      @click="$emit('load-example')"
    >
      <span>New User?</span>
      <span>Load Example Data</span>
    </button>

    <button
      class="bulk-load-btn bulk-load-btn-gray"
      @click="triggerFileUpload"
    >
      Load All Data from JSON File
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
  mode: {
    type: String,
    default: 'add', // 'add' or 'edit'
    validator: (value) => ['add', 'edit'].includes(value)
  }
})

const emit = defineEmits(['load-from-previous', 'load-example', 'load-from-json'])

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
</script>

<style scoped>
.bulk-load-bar {
  display: flex;
  justify-content: center;
  gap: 1rem;
  padding: 1rem 0;
  flex-wrap: wrap;
}

.bulk-load-btn {
  padding: 0.75rem 1.25rem;
  border: none;
  border-radius: 4px;
  font-size: 0.9rem;
  font-weight: 500;
  cursor: pointer;
  transition: background-color 0.2s, transform 0.1s;
  min-width: 200px;
  text-align: center;
}

.bulk-load-btn:hover {
  transform: translateY(-1px);
}

.bulk-load-btn:active {
  transform: translateY(0);
}

.bulk-load-btn-gray {
  background-color: #6c757d;
  color: white;
}

.bulk-load-btn-gray:hover {
  background-color: #5a6268;
}

.bulk-load-btn-green {
  background-color: #28a745;
  color: white;
  display: flex;
  flex-direction: column;
  align-items: center;
  line-height: 1.3;
}

.bulk-load-btn-green:hover {
  background-color: #218838;
}

.bulk-load-btn-green span:first-child {
  font-size: 0.85rem;
}

.bulk-load-btn-green span:last-child {
  font-weight: 600;
}
</style>
