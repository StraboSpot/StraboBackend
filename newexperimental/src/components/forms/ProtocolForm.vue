<template>
  <form @submit.prevent="handleSubmit" class="protocol-form">
    <!-- Protocol Steps Section -->
    <fieldset class="form-section">
      <legend>Protocol <Button size="small" label="Add Step" @click="addStep" /></legend>

      <div v-if="form.length > 0" class="protocol-layout">
        <!-- Left sidebar: Step buttons -->
        <div class="step-tabs">
          <Button
            v-for="(step, idx) in form"
            :key="idx"
            :label="getStepLabel(step, idx)"
            :severity="selectedStepIdx === idx ? undefined : 'secondary'"
            :outlined="selectedStepIdx !== idx"
            size="small"
            class="step-tab-btn"
            @click="selectedStepIdx = idx"
          />
        </div>

        <!-- Right side: Selected step detail -->
        <div v-if="selectedStepIdx !== null && form[selectedStepIdx]" class="step-detail">
          <div class="step-content">
            <!-- Step fields row 1: Step dropdown + Objective -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div class="field">
                <label class="text-sm">Step</label>
                <Select
                  v-model="form[selectedStepIdx].test"
                  :options="availableFeatures"
                  optionLabel="label"
                  optionValue="value"
                  placeholder="Select step type..."
                  showClear
                  class="w-full"
                />
              </div>
              <div class="field md:col-span-2">
                <label class="text-sm">Objective</label>
                <InputText
                  v-model="form[selectedStepIdx].objective"
                  placeholder="Enter the objective for this step..."
                  class="w-full"
                />
              </div>
            </div>

            <!-- Step fields row 2: Description (full width) -->
            <div class="grid grid-cols-1 gap-4 mt-3">
              <div class="field">
                <label class="text-sm">Description</label>
                <InputText
                  v-model="form[selectedStepIdx].description"
                  placeholder="Enter a description for this step..."
                  class="w-full"
                />
              </div>
            </div>

            <!-- Parameters section -->
            <fieldset class="parameters-fieldset">
              <legend>Parameters <Button size="small" label="Add Parameter" @click="addParameter(selectedStepIdx)" /></legend>

              <div v-if="form[selectedStepIdx].parameters && form[selectedStepIdx].parameters.length > 0">
                <!-- Header row -->
                <div class="param-header-row">
                  <div class="param-col param-col-variable">Variable</div>
                  <div class="param-col param-col-value">Value</div>
                  <div class="param-col param-col-unit">Unit</div>
                  <div class="param-col param-col-note">Note</div>
                  <div class="param-col param-col-actions">&nbsp;</div>
                </div>

                <!-- Parameter rows -->
                <div
                  v-for="(param, pIdx) in form[selectedStepIdx].parameters"
                  :key="pIdx"
                  class="param-row"
                >
                  <div class="param-col param-col-variable">
                    <Select
                      v-model="param.control"
                      :options="PROTOCOL_CONTROL_VARIABLES"
                      placeholder="Select..."
                      showClear
                      class="w-full"
                    />
                  </div>
                  <div class="param-col param-col-value">
                    <InputText
                      v-model="param.value"
                      placeholder="Value"
                      class="w-full"
                    />
                  </div>
                  <div class="param-col param-col-unit">
                    <Select
                      v-model="param.unit"
                      :options="UNIT_TYPES"
                      placeholder="Unit"
                      showClear
                      class="w-full"
                    />
                  </div>
                  <div class="param-col param-col-note">
                    <InputText
                      v-model="param.note"
                      placeholder="Note"
                      class="w-full"
                    />
                  </div>
                  <div class="param-col param-col-actions">
                    <Button
                      icon="pi pi-trash"
                      severity="secondary"
                      outlined
                      size="small"
                      @click="deleteParameter(selectedStepIdx, pIdx)"
                      v-tooltip.top="'Delete Parameter'"
                    />
                    <Button
                      icon="pi pi-arrow-up"
                      severity="secondary"
                      outlined
                      size="small"
                      :disabled="pIdx === 0"
                      @click="moveParameter(selectedStepIdx, pIdx, -1)"
                      v-tooltip.top="'Move Up'"
                    />
                    <Button
                      icon="pi pi-arrow-down"
                      severity="secondary"
                      outlined
                      size="small"
                      :disabled="pIdx === form[selectedStepIdx].parameters.length - 1"
                      @click="moveParameter(selectedStepIdx, pIdx, 1)"
                      v-tooltip.top="'Move Down'"
                    />
                  </div>
                </div>
              </div>
              <p v-else class="text-sm text-surface-500 py-2">No parameters added. Click "Add Parameter" to add one.</p>
            </fieldset>
          </div>

          <!-- Step action buttons (right side) -->
          <div class="step-actions">
            <Button
              icon="pi pi-trash"
              severity="secondary"
              outlined
              size="small"
              @click="deleteStep(selectedStepIdx)"
              v-tooltip.top="'Delete Step'"
            />
            <Button
              icon="pi pi-arrow-up"
              severity="secondary"
              outlined
              size="small"
              :disabled="selectedStepIdx === 0"
              @click="moveStep(selectedStepIdx, -1)"
              v-tooltip.top="'Move Up'"
            />
            <Button
              icon="pi pi-arrow-down"
              severity="secondary"
              outlined
              size="small"
              :disabled="selectedStepIdx === form.length - 1"
              @click="moveStep(selectedStepIdx, 1)"
              v-tooltip.top="'Move Down'"
            />
          </div>
        </div>
      </div>
      <p v-else class="text-surface-500 text-center py-4">
        No protocol steps added. Click "Add Step" to define your experimental protocol.
      </p>
    </fieldset>

    <!-- Actions -->
    <div class="flex justify-center gap-3 mt-6">
      <Button
        type="button"
        severity="secondary"
        outlined
        label="Cancel"
        @click="$emit('cancel')"
      />
      <Button
        type="submit"
        label="Save Protocol"
      />
    </div>
  </form>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import { PROTOCOL_CONTROL_VARIABLES, UNIT_TYPES, APPARATUS_FEATURES } from '@/schemas/laps-enums.js'

const props = defineProps({
  initialData: {
    type: Array,
    default: () => []
  },
  // Features selected in the experiment (from experimental setup)
  selectedFeatures: {
    type: Array,
    default: () => []
  }
})

const emit = defineEmits(['submit', 'cancel'])

// Form is an array of protocol steps
const form = ref([])

// Currently selected step index
const selectedStepIdx = ref(null)

// Compute available features for Step dropdown
// In old app, this comes from selected apparatus features in experimental setup
// If no features provided, fall back to all apparatus features
const availableFeatures = computed(() => {
  let features = APPARATUS_FEATURES
  if (props.selectedFeatures && props.selectedFeatures.length > 0) {
    features = props.selectedFeatures
  }
  // Convert to object format to avoid PrimeVue rendering issues with special characters
  return features.map(f => ({ label: f, value: f }))
})

// Initialize form from initial data
const initForm = () => {
  if (props.initialData && Array.isArray(props.initialData) && props.initialData.length > 0) {
    // Deep copy with default parameter arrays
    form.value = props.initialData.map(step => ({
      test: step.test || '',
      objective: step.objective || '',
      description: step.description || '',
      parameters: Array.isArray(step.parameters) ? step.parameters.map(p => ({
        control: p.control || '',
        value: p.value !== undefined ? String(p.value) : '',
        unit: p.unit || '',
        note: p.note || ''
      })) : []
    }))
    selectedStepIdx.value = 0
  } else {
    form.value = []
    selectedStepIdx.value = null
  }
}

initForm()

// Watch for prop changes
watch(() => props.initialData, initForm, { deep: true })

// Get step label for sidebar button
const getStepLabel = (step, idx) => {
  if (step.test) {
    return step.test
  }
  return `Step ${idx + 1}`
}

// Add a new protocol step
const addStep = () => {
  form.value.push({
    test: '',
    objective: '',
    description: '',
    parameters: []
  })
  // Select the new step
  selectedStepIdx.value = form.value.length - 1
}

// Delete a step
const deleteStep = (idx) => {
  if (form.value.length === 0) return
  form.value.splice(idx, 1)
  // Adjust selection
  if (form.value.length === 0) {
    selectedStepIdx.value = null
  } else if (selectedStepIdx.value >= form.value.length) {
    selectedStepIdx.value = form.value.length - 1
  }
}

// Move a step up or down
const moveStep = (idx, direction) => {
  const newIdx = idx + direction
  if (newIdx < 0 || newIdx >= form.value.length) return
  const temp = form.value[idx]
  form.value[idx] = form.value[newIdx]
  form.value[newIdx] = temp
  selectedStepIdx.value = newIdx
}

// Add a parameter to a step
const addParameter = (stepIdx) => {
  if (!form.value[stepIdx].parameters) {
    form.value[stepIdx].parameters = []
  }
  form.value[stepIdx].parameters.push({
    control: '',
    value: '',
    unit: '',
    note: ''
  })
}

// Delete a parameter
const deleteParameter = (stepIdx, paramIdx) => {
  form.value[stepIdx].parameters.splice(paramIdx, 1)
}

// Move a parameter up or down
const moveParameter = (stepIdx, paramIdx, direction) => {
  const params = form.value[stepIdx].parameters
  const newIdx = paramIdx + direction
  if (newIdx < 0 || newIdx >= params.length) return
  const temp = params[paramIdx]
  params[paramIdx] = params[newIdx]
  params[newIdx] = temp
}

// Handle form submit
const handleSubmit = () => {
  // Clean up the data before emitting
  const cleanedData = form.value.map(step => {
    const cleaned = {
      test: step.test || undefined,
      objective: step.objective || undefined,
      description: step.description || undefined
    }

    // Only include parameters if there are any
    if (step.parameters && step.parameters.length > 0) {
      cleaned.parameters = step.parameters.map(p => ({
        control: p.control || undefined,
        value: p.value !== '' ? p.value : undefined,
        unit: p.unit || undefined,
        note: p.note || undefined
      })).filter(p => p.control || p.value || p.unit || p.note) // Filter empty params
    }

    return cleaned
  }).filter(step => step.test || step.objective || step.description || (step.parameters && step.parameters.length > 0))

  emit('submit', cleanedData)
}
</script>

<style scoped>
.protocol-form {
  max-height: 70vh;
  overflow-y: auto;
}

.form-section {
  border: 1px solid var(--p-surface-700);
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 1rem;
}

.form-section legend {
  color: var(--p-surface-300);
  font-weight: 600;
  padding: 0 0.5rem;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.protocol-layout {
  display: flex;
  gap: 1rem;
  min-height: 300px;
}

.step-tabs {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 120px;
  max-width: 150px;
  flex-shrink: 0;
  padding-top: 0.25rem;
}

.step-tab-btn {
  width: 100%;
  justify-content: flex-start;
  text-align: left;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.step-detail {
  flex: 1;
  display: flex;
  gap: 0.75rem;
  border: 1px solid var(--p-surface-700);
  border-radius: 6px;
  padding: 1rem;
  background: var(--p-surface-900);
}

.step-content {
  flex: 1;
}

.step-actions {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  flex-shrink: 0;
}

.parameters-fieldset {
  border: 1px solid var(--p-surface-700);
  border-radius: 6px;
  padding: 0.75rem;
  margin-top: 1rem;
}

.parameters-fieldset legend {
  color: var(--p-surface-400);
  font-weight: 500;
  font-size: 0.875rem;
  padding: 0 0.5rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.param-header-row {
  display: flex;
  gap: 0.5rem;
  padding: 0.5rem 0;
  font-weight: 600;
  font-size: 0.875rem;
  color: var(--p-surface-300);
  border-bottom: 1px solid var(--p-surface-700);
}

.param-row {
  display: flex;
  gap: 0.5rem;
  padding: 0.5rem 0;
  align-items: center;
  border-bottom: 1px solid var(--p-surface-800);
}

.param-row:last-child {
  border-bottom: none;
}

.param-col {
  flex-shrink: 0;
}

.param-col-variable {
  width: 180px;
}

.param-col-value {
  width: 100px;
}

.param-col-unit {
  width: 120px;
}

.param-col-note {
  flex: 1;
  min-width: 150px;
}

.param-col-actions {
  display: flex;
  gap: 0.25rem;
  width: auto;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.field label {
  color: var(--p-surface-400);
}
</style>
