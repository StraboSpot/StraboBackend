<template>
  <form @submit.prevent="handleSubmit" class="data-form">
    <!-- Datasets Section -->
    <fieldset class="form-section">
      <legend>Datasets <Button size="small" label="Add Dataset" @click="addDataset" /></legend>

      <div v-if="form.length > 0" class="data-layout">
        <!-- Left sidebar: Dataset buttons -->
        <div class="dataset-tabs">
          <Button
            v-for="(dataset, idx) in form"
            :key="idx"
            :label="getDatasetLabel(dataset, idx)"
            :severity="selectedDatasetIdx === idx ? undefined : 'secondary'"
            :outlined="selectedDatasetIdx !== idx"
            size="small"
            class="dataset-tab-btn"
            @click="selectedDatasetIdx = idx"
          />
        </div>

        <!-- Right side: Selected dataset detail -->
        <div v-if="selectedDatasetIdx !== null && form[selectedDatasetIdx]" class="dataset-detail">
          <div class="dataset-content">
            <!-- Row 1: Data Source, Data Type, File -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div class="field">
                <label class="text-sm">Data<span class="text-red-500">*</span></label>
                <Select
                  v-model="form[selectedDatasetIdx].data"
                  :options="DATA_SOURCE_TYPES"
                  placeholder="Select..."
                  showClear
                  class="w-full"
                  @change="onDataSourceChange"
                />
              </div>
              <div class="field">
                <label class="text-sm">Data Type<span class="text-red-500">*</span></label>
                <Select
                  v-model="form[selectedDatasetIdx].type"
                  :options="DATA_TYPE_OPTIONS"
                  placeholder="Select..."
                  showClear
                  class="w-full"
                />
                <InputText
                  v-if="form[selectedDatasetIdx].type === 'Other'"
                  v-model="form[selectedDatasetIdx].other_type"
                  placeholder="enter other data type here..."
                  class="w-full mt-2"
                />
              </div>
              <div class="field">
                <DocumentUploader
                  :uuid="form[selectedDatasetIdx].uuid"
                  v-model="form[selectedDatasetIdx].path"
                />
              </div>
            </div>

            <!-- Row 2: Data ID, File Format, Data Quality -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
              <div class="field">
                <label class="text-sm">Data ID</label>
                <InputText
                  v-model="form[selectedDatasetIdx].id"
                  class="w-full"
                />
              </div>
              <div class="field">
                <label class="text-sm">File Format</label>
                <Select
                  v-model="form[selectedDatasetIdx].format"
                  :options="DATA_FILE_FORMATS"
                  placeholder="Select..."
                  showClear
                  class="w-full"
                />
                <InputText
                  v-if="form[selectedDatasetIdx].format === 'Other'"
                  v-model="form[selectedDatasetIdx].other_format"
                  placeholder="enter other data format here..."
                  class="w-full mt-2"
                />
              </div>
              <div class="field">
                <label class="text-sm">Data Quality</label>
                <Select
                  v-model="form[selectedDatasetIdx].rating"
                  :options="DATA_QUALITY_RATINGS"
                  placeholder="Select..."
                  showClear
                  class="w-full"
                />
              </div>
            </div>

            <!-- Row 3: Description -->
            <div class="grid grid-cols-1 gap-4 mt-3">
              <div class="field">
                <label class="text-sm">Description</label>
                <Textarea
                  v-model="form[selectedDatasetIdx].description"
                  rows="3"
                  class="w-full"
                />
              </div>
            </div>

            <!-- Conditional: Parameters (when data === 'Parameters') -->
            <fieldset v-if="form[selectedDatasetIdx].data === 'Parameters'" class="parameters-fieldset">
              <legend>Parameter List <Button size="small" label="Add Parameter" @click="addParameter" /></legend>

              <div v-if="form[selectedDatasetIdx].parameters && form[selectedDatasetIdx].parameters.length > 0">
                <!-- Header row -->
                <div class="param-header-row">
                  <div class="param-col param-col-data">Data</div>
                  <div class="param-col param-col-value">Value</div>
                  <div class="param-col param-col-error">Error</div>
                  <div class="param-col param-col-unit">Unit</div>
                  <div class="param-col param-col-prefix">Prefix</div>
                  <div class="param-col param-col-note">Note</div>
                  <div class="param-col param-col-actions">&nbsp;</div>
                </div>

                <!-- Parameter rows -->
                <div
                  v-for="(param, pIdx) in form[selectedDatasetIdx].parameters"
                  :key="pIdx"
                  class="param-row"
                >
                  <div class="param-col param-col-data">
                    <Select
                      v-model="param.control"
                      :options="DATA_PARAMETER_CONTROLS"
                      placeholder="Select..."
                      showClear
                      class="w-full"
                    />
                  </div>
                  <div class="param-col param-col-value">
                    <InputText v-model="param.value" class="w-full" />
                  </div>
                  <div class="param-col param-col-error">
                    <InputText v-model="param.error" class="w-full" />
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
                  <div class="param-col param-col-prefix">
                    <Select
                      v-model="param.prefix"
                      :options="UNIT_PREFIXES"
                      placeholder="-"
                      showClear
                      class="w-full"
                    />
                  </div>
                  <div class="param-col param-col-note">
                    <InputText v-model="param.note" class="w-full" />
                  </div>
                  <div class="param-col param-col-actions">
                    <Button
                      icon="pi pi-trash"
                      severity="secondary"
                      outlined
                      size="small"
                      @click="deleteParameter(pIdx)"
                      v-tooltip.top="'Delete'"
                    />
                    <Button
                      icon="pi pi-arrow-up"
                      severity="secondary"
                      outlined
                      size="small"
                      :disabled="pIdx === 0"
                      @click="moveParameter(pIdx, -1)"
                      v-tooltip.top="'Move Up'"
                    />
                    <Button
                      icon="pi pi-arrow-down"
                      severity="secondary"
                      outlined
                      size="small"
                      :disabled="pIdx === form[selectedDatasetIdx].parameters.length - 1"
                      @click="moveParameter(pIdx, 1)"
                      v-tooltip.top="'Move Down'"
                    />
                  </div>
                </div>
              </div>
              <p v-else class="text-sm text-surface-500 py-2">No parameters added. Click "Add Parameter" to add one.</p>
            </fieldset>

            <!-- Conditional: Time Series Headers (when data === 'Time Series') -->
            <fieldset v-if="form[selectedDatasetIdx].data === 'Time Series'" class="parameters-fieldset">
              <legend>Data Headers <Button size="small" label="Add Header" @click="addHeader" /></legend>

              <div v-if="form[selectedDatasetIdx].headers && form[selectedDatasetIdx].headers.length > 0" class="headers-layout">
                <!-- Header tabs on left -->
                <div class="header-tabs">
                  <Button
                    v-for="(hdr, hIdx) in form[selectedDatasetIdx].headers"
                    :key="hIdx"
                    :label="getHeaderLabel(hdr, hIdx)"
                    :severity="selectedHeaderIdx === hIdx ? undefined : 'secondary'"
                    :outlined="selectedHeaderIdx !== hIdx"
                    size="small"
                    class="header-tab-btn"
                    @click="selectedHeaderIdx = hIdx"
                  />
                </div>

                <!-- Header content on right -->
                <div v-if="selectedHeaderIdx !== null && form[selectedDatasetIdx].headers[selectedHeaderIdx]" class="header-detail">
                  <div class="header-content">
                    <!-- Row 1: Header Type -->
                    <div class="grid grid-cols-1 gap-4">
                      <div class="field">
                        <label class="text-sm">Header</label>
                        <Select
                          v-model="form[selectedDatasetIdx].headers[selectedHeaderIdx].header.header"
                          :options="CHANNEL_HEADER_TYPES"
                          placeholder="Select..."
                          showClear
                          class="w-full"
                          @change="onHeaderTypeChange"
                        />
                      </div>
                    </div>

                    <!-- Row 2: Specifiers and Unit -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-3">
                      <div class="field">
                        <label class="text-sm">Specifier A</label>
                        <Select
                          v-if="specAOptions.length > 0"
                          v-model="form[selectedDatasetIdx].headers[selectedHeaderIdx].header.spec_a"
                          :options="specAOptions"
                          placeholder="Select..."
                          showClear
                          class="w-full"
                        />
                        <InputText
                          v-else
                          v-model="form[selectedDatasetIdx].headers[selectedHeaderIdx].header.spec_a"
                          class="w-full"
                        />
                      </div>
                      <div class="field">
                        <label class="text-sm">Specifier B</label>
                        <Select
                          v-if="specBOptions.length > 0"
                          v-model="form[selectedDatasetIdx].headers[selectedHeaderIdx].header.spec_b"
                          :options="specBOptions"
                          placeholder="Select..."
                          showClear
                          class="w-full"
                        />
                        <InputText
                          v-else
                          v-model="form[selectedDatasetIdx].headers[selectedHeaderIdx].header.spec_b"
                          class="w-full"
                        />
                      </div>
                      <div class="field">
                        <label class="text-sm">Other Specifier</label>
                        <InputText
                          v-model="form[selectedDatasetIdx].headers[selectedHeaderIdx].header.spec_c"
                          class="w-full"
                        />
                      </div>
                      <div class="field">
                        <label class="text-sm">Unit</label>
                        <Select
                          v-if="unitOptions.length > 0"
                          v-model="form[selectedDatasetIdx].headers[selectedHeaderIdx].header.unit"
                          :options="unitOptions"
                          placeholder="Select..."
                          showClear
                          class="w-full"
                        />
                        <InputText
                          v-else
                          v-model="form[selectedDatasetIdx].headers[selectedHeaderIdx].header.unit"
                          class="w-full"
                        />
                      </div>
                    </div>

                    <!-- Row 3: Type, Channel #, Data Quality -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                      <div class="field">
                        <label class="text-sm">Type</label>
                        <Select
                          v-model="form[selectedDatasetIdx].headers[selectedHeaderIdx].type"
                          :options="DATA_HEADER_CHANNEL_TYPES"
                          placeholder="Select..."
                          showClear
                          class="w-full"
                        />
                      </div>
                      <div class="field">
                        <label class="text-sm">Channel #</label>
                        <Select
                          v-model="form[selectedDatasetIdx].headers[selectedHeaderIdx].number"
                          :options="channelNumbers"
                          placeholder="Select..."
                          showClear
                          class="w-full"
                        />
                      </div>
                      <div class="field">
                        <label class="text-sm">Data Quality</label>
                        <Select
                          v-model="form[selectedDatasetIdx].headers[selectedHeaderIdx].rating"
                          :options="DATA_QUALITY_RATINGS"
                          placeholder="Select..."
                          showClear
                          class="w-full"
                        />
                      </div>
                    </div>

                    <!-- Row 4: Notes -->
                    <div class="grid grid-cols-1 gap-4 mt-3">
                      <div class="field">
                        <label class="text-sm">Notes</label>
                        <Textarea
                          v-model="form[selectedDatasetIdx].headers[selectedHeaderIdx].note"
                          rows="2"
                          class="w-full"
                        />
                      </div>
                    </div>
                  </div>

                  <!-- Header action buttons -->
                  <div class="header-actions">
                    <Button
                      icon="pi pi-trash"
                      severity="secondary"
                      outlined
                      size="small"
                      @click="deleteHeader(selectedHeaderIdx)"
                      v-tooltip.top="'Delete Header'"
                    />
                    <Button
                      icon="pi pi-arrow-up"
                      severity="secondary"
                      outlined
                      size="small"
                      :disabled="selectedHeaderIdx === 0"
                      @click="moveHeader(selectedHeaderIdx, -1)"
                      v-tooltip.top="'Move Up'"
                    />
                    <Button
                      icon="pi pi-arrow-down"
                      severity="secondary"
                      outlined
                      size="small"
                      :disabled="selectedHeaderIdx === form[selectedDatasetIdx].headers.length - 1"
                      @click="moveHeader(selectedHeaderIdx, 1)"
                      v-tooltip.top="'Move Down'"
                    />
                  </div>
                </div>
              </div>
              <p v-else class="text-sm text-surface-500 py-2">No headers added. Click "Add Header" to add one.</p>
            </fieldset>

            <!-- Conditional: Pore Fluid Phases (when data === 'Pore Fluid') -->
            <fieldset v-if="form[selectedDatasetIdx].data === 'Pore Fluid'" class="parameters-fieldset">
              <legend>Pore Fluid Phases <Button size="small" label="Add Phase" @click="addPhase" /></legend>

              <div v-if="form[selectedDatasetIdx].fluid?.phases && form[selectedDatasetIdx].fluid.phases.length > 0" class="phases-layout">
                <!-- Phase tabs on left -->
                <div class="phase-tabs">
                  <Button
                    v-for="(phase, phIdx) in form[selectedDatasetIdx].fluid.phases"
                    :key="phIdx"
                    :label="'Phase ' + (phIdx + 1)"
                    :severity="selectedPhaseIdx === phIdx ? undefined : 'secondary'"
                    :outlined="selectedPhaseIdx !== phIdx"
                    size="small"
                    class="phase-tab-btn"
                    @click="selectedPhaseIdx = phIdx"
                  />
                </div>

                <!-- Phase content on right -->
                <div v-if="selectedPhaseIdx !== null && form[selectedDatasetIdx].fluid.phases[selectedPhaseIdx]" class="phase-detail">
                  <div class="phase-content">
                    <!-- Row 1: Component, Fraction, Activity -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                      <div class="field">
                        <label class="text-sm">Component</label>
                        <InputText
                          v-model="form[selectedDatasetIdx].fluid.phases[selectedPhaseIdx].component"
                          class="w-full"
                        />
                      </div>
                      <div class="field">
                        <label class="text-sm">Fraction</label>
                        <InputText
                          v-model="form[selectedDatasetIdx].fluid.phases[selectedPhaseIdx].fraction"
                          class="w-full"
                        />
                      </div>
                      <div class="field">
                        <label class="text-sm">Activity</label>
                        <InputText
                          v-model="form[selectedDatasetIdx].fluid.phases[selectedPhaseIdx].activity"
                          class="w-full"
                        />
                      </div>
                    </div>

                    <!-- Row 2: Fugacity, Unit, Chemistry Data -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                      <div class="field">
                        <label class="text-sm">Fugacity</label>
                        <InputText
                          v-model="form[selectedDatasetIdx].fluid.phases[selectedPhaseIdx].fugacity"
                          class="w-full"
                        />
                      </div>
                      <div class="field">
                        <label class="text-sm">Unit</label>
                        <Select
                          v-model="form[selectedDatasetIdx].fluid.phases[selectedPhaseIdx].unit"
                          :options="PHASE_UNIT_OPTIONS"
                          placeholder="Select..."
                          showClear
                          class="w-full"
                        />
                      </div>
                      <div class="field">
                        <label class="text-sm">Chemistry Data</label>
                        <Select
                          v-model="form[selectedDatasetIdx].fluid.phases[selectedPhaseIdx].composition"
                          :options="CHEMISTRY_DATA_OPTIONS"
                          placeholder="Select..."
                          showClear
                          class="w-full"
                        />
                      </div>
                    </div>

                    <!-- Chemistry Solutes (when composition === 'Chemistry') -->
                    <fieldset v-if="form[selectedDatasetIdx].fluid.phases[selectedPhaseIdx].composition === 'Chemistry'" class="solutes-fieldset">
                      <legend>Chemistry <Button size="small" label="Add Solute" @click="addSolute" /></legend>

                      <div v-if="form[selectedDatasetIdx].fluid.phases[selectedPhaseIdx].solutes && form[selectedDatasetIdx].fluid.phases[selectedPhaseIdx].solutes.length > 0">
                        <!-- Header row -->
                        <div class="solute-header-row">
                          <div class="solute-col solute-col-component">Component</div>
                          <div class="solute-col solute-col-value">Value</div>
                          <div class="solute-col solute-col-error">Error</div>
                          <div class="solute-col solute-col-unit">Unit</div>
                          <div class="solute-col solute-col-actions">&nbsp;</div>
                        </div>

                        <!-- Solute rows -->
                        <div
                          v-for="(solute, sIdx) in form[selectedDatasetIdx].fluid.phases[selectedPhaseIdx].solutes"
                          :key="sIdx"
                          class="solute-row"
                        >
                          <div class="solute-col solute-col-component">
                            <Select
                              v-model="solute.component"
                              :options="SOLUTE_COMPONENTS"
                              placeholder="Select..."
                              showClear
                              class="w-full"
                            />
                          </div>
                          <div class="solute-col solute-col-value">
                            <InputText v-model="solute.value" class="w-full" />
                          </div>
                          <div class="solute-col solute-col-error">
                            <InputText v-model="solute.error" class="w-full" />
                          </div>
                          <div class="solute-col solute-col-unit">
                            <Select
                              v-model="solute.unit"
                              :options="SOLUTE_UNITS"
                              placeholder="Select..."
                              showClear
                              class="w-full"
                            />
                          </div>
                          <div class="solute-col solute-col-actions">
                            <Button
                              icon="pi pi-trash"
                              severity="secondary"
                              outlined
                              size="small"
                              @click="deleteSolute(sIdx)"
                              v-tooltip.top="'Delete'"
                            />
                            <Button
                              icon="pi pi-arrow-up"
                              severity="secondary"
                              outlined
                              size="small"
                              :disabled="sIdx === 0"
                              @click="moveSolute(sIdx, -1)"
                              v-tooltip.top="'Move Up'"
                            />
                            <Button
                              icon="pi pi-arrow-down"
                              severity="secondary"
                              outlined
                              size="small"
                              :disabled="sIdx === form[selectedDatasetIdx].fluid.phases[selectedPhaseIdx].solutes.length - 1"
                              @click="moveSolute(sIdx, 1)"
                              v-tooltip.top="'Move Down'"
                            />
                          </div>
                        </div>
                      </div>
                      <p v-else class="text-sm text-surface-500 py-2">No solutes added. Click "Add Solute" to add one.</p>
                    </fieldset>
                  </div>

                  <!-- Phase action buttons -->
                  <div class="phase-actions">
                    <Button
                      icon="pi pi-trash"
                      severity="secondary"
                      outlined
                      size="small"
                      @click="deletePhase(selectedPhaseIdx)"
                      v-tooltip.top="'Delete Phase'"
                    />
                    <Button
                      icon="pi pi-arrow-up"
                      severity="secondary"
                      outlined
                      size="small"
                      :disabled="selectedPhaseIdx === 0"
                      @click="movePhase(selectedPhaseIdx, -1)"
                      v-tooltip.top="'Move Up'"
                    />
                    <Button
                      icon="pi pi-arrow-down"
                      severity="secondary"
                      outlined
                      size="small"
                      :disabled="selectedPhaseIdx === form[selectedDatasetIdx].fluid.phases.length - 1"
                      @click="movePhase(selectedPhaseIdx, 1)"
                      v-tooltip.top="'Move Down'"
                    />
                  </div>
                </div>
              </div>
              <p v-else class="text-sm text-surface-500 py-2">No phases added. Click "Add Phase" to add one.</p>
            </fieldset>
          </div>

          <!-- Dataset action buttons (right side) -->
          <div class="dataset-actions">
            <Button
              icon="pi pi-trash"
              severity="secondary"
              outlined
              size="small"
              @click="deleteDataset(selectedDatasetIdx)"
              v-tooltip.top="'Delete Dataset'"
            />
            <Button
              icon="pi pi-arrow-up"
              severity="secondary"
              outlined
              size="small"
              :disabled="selectedDatasetIdx === 0"
              @click="moveDataset(selectedDatasetIdx, -1)"
              v-tooltip.top="'Move Up'"
            />
            <Button
              icon="pi pi-arrow-down"
              severity="secondary"
              outlined
              size="small"
              :disabled="selectedDatasetIdx === form.length - 1"
              @click="moveDataset(selectedDatasetIdx, 1)"
              v-tooltip.top="'Move Down'"
            />
          </div>
        </div>
      </div>
      <p v-else class="text-surface-500 text-center py-4">
        No datasets added. Click "Add Dataset" to begin.
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
        label="Save"
      />
    </div>
  </form>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DocumentUploader from '@/components/DocumentUploader.vue'
import {
  DATA_SOURCE_TYPES,
  DATA_TYPE_OPTIONS,
  DATA_FILE_FORMATS,
  DATA_QUALITY_RATINGS,
  DATA_PARAMETER_CONTROLS,
  UNIT_TYPES,
  UNIT_PREFIXES,
  CHANNEL_HEADER_TYPES,
  DATA_HEADER_CHANNEL_TYPES,
  PHASE_UNIT_OPTIONS,
  CHEMISTRY_DATA_OPTIONS,
  SOLUTE_COMPONENTS,
  SOLUTE_UNITS,
  getDAQOptionsForHeader
} from '@/schemas/laps-enums.js'

const props = defineProps({
  initialData: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits(['submit', 'cancel'])

const toast = useToast()

// Form is the datasets array
const form = ref([])

// Currently selected indices
const selectedDatasetIdx = ref(null)
const selectedHeaderIdx = ref(0)
const selectedPhaseIdx = ref(0)

// Channel numbers 0-32
const channelNumbers = Array.from({ length: 33 }, (_, i) => String(i))

// Computed: dynamic dropdown options for header specifiers
const specAOptions = computed(() => {
  if (selectedDatasetIdx.value === null) return []
  const dataset = form.value[selectedDatasetIdx.value]
  if (!dataset?.headers || selectedHeaderIdx.value === null) return []
  const header = dataset.headers[selectedHeaderIdx.value]
  if (!header?.header?.header) return []
  return getDAQOptionsForHeader(header.header.header, 'spec_a')
})

const specBOptions = computed(() => {
  if (selectedDatasetIdx.value === null) return []
  const dataset = form.value[selectedDatasetIdx.value]
  if (!dataset?.headers || selectedHeaderIdx.value === null) return []
  const header = dataset.headers[selectedHeaderIdx.value]
  if (!header?.header?.header) return []
  return getDAQOptionsForHeader(header.header.header, 'spec_b')
})

const unitOptions = computed(() => {
  if (selectedDatasetIdx.value === null) return []
  const dataset = form.value[selectedDatasetIdx.value]
  if (!dataset?.headers || selectedHeaderIdx.value === null) return []
  const header = dataset.headers[selectedHeaderIdx.value]
  if (!header?.header?.header) return []
  return getDAQOptionsForHeader(header.header.header, 'unit')
})

// Generate UUID
function generateUUID() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
    const r = Math.random() * 16 | 0
    const v = c === 'x' ? r : (r & 0x3 | 0x8)
    return v.toString(16)
  })
}

// Initialize form from initial data
const initForm = () => {
  if (props.initialData?.datasets && Array.isArray(props.initialData.datasets) && props.initialData.datasets.length > 0) {
    form.value = props.initialData.datasets.map(ds => ({
      data: ds.data || '',
      type: ds.type || '',
      other_type: ds.other_type || '',
      format: ds.format || '',
      other_format: ds.other_format || '',
      id: ds.id || '',
      uuid: ds.uuid || generateUUID(),
      path: ds.path || '',
      rating: ds.rating || '',
      description: ds.description || '',
      parameters: Array.isArray(ds.parameters) ? ds.parameters.map(p => ({ ...p })) : [],
      headers: Array.isArray(ds.headers) ? ds.headers.map(h => ({
        header: { ...h.header },
        type: h.type || '',
        number: h.number || '0',
        note: h.note || '',
        rating: h.rating || ''
      })) : [],
      fluid: ds.fluid ? {
        phases: Array.isArray(ds.fluid.phases) ? ds.fluid.phases.map(ph => ({
          ...ph,
          solutes: Array.isArray(ph.solutes) ? ph.solutes.map(s => ({ ...s })) : []
        })) : []
      } : { phases: [] }
    }))
    selectedDatasetIdx.value = 0
  } else {
    form.value = []
    selectedDatasetIdx.value = null
  }
}

initForm()

// Watch for prop changes
watch(() => props.initialData, initForm, { deep: true })

// Get dataset label for sidebar button
const getDatasetLabel = (dataset, idx) => {
  if (dataset.data) return dataset.data
  return `Dataset ${idx + 1}`
}

// Get header label for sidebar button
const getHeaderLabel = (header, idx) => {
  if (header?.header?.header) return header.header.header
  return `Header ${idx + 1}`
}

// Dataset management
const addDataset = () => {
  form.value.push({
    data: '',
    type: '',
    other_type: '',
    format: '',
    other_format: '',
    id: '',
    uuid: generateUUID(),
    path: '',
    rating: '',
    description: '',
    parameters: [],
    headers: [],
    fluid: { phases: [] }
  })
  selectedDatasetIdx.value = form.value.length - 1
}

const deleteDataset = (idx) => {
  if (form.value.length === 0) return
  form.value.splice(idx, 1)
  if (form.value.length === 0) {
    selectedDatasetIdx.value = null
  } else if (selectedDatasetIdx.value >= form.value.length) {
    selectedDatasetIdx.value = form.value.length - 1
  }
}

const moveDataset = (idx, direction) => {
  const newIdx = idx + direction
  if (newIdx < 0 || newIdx >= form.value.length) return
  const temp = form.value[idx]
  form.value[idx] = form.value[newIdx]
  form.value[newIdx] = temp
  selectedDatasetIdx.value = newIdx
}

// Parameter management
const addParameter = () => {
  const dataset = form.value[selectedDatasetIdx.value]
  if (!dataset.parameters) dataset.parameters = []
  dataset.parameters.push({
    control: '',
    value: '',
    error: '',
    unit: '',
    prefix: '-',
    note: ''
  })
}

const deleteParameter = (idx) => {
  form.value[selectedDatasetIdx.value].parameters.splice(idx, 1)
}

const moveParameter = (idx, direction) => {
  const params = form.value[selectedDatasetIdx.value].parameters
  const newIdx = idx + direction
  if (newIdx < 0 || newIdx >= params.length) return
  const temp = params[idx]
  params[idx] = params[newIdx]
  params[newIdx] = temp
}

// Header management (Time Series)
const addHeader = () => {
  const dataset = form.value[selectedDatasetIdx.value]
  if (!dataset.headers) dataset.headers = []
  dataset.headers.push({
    header: {
      header: 'Time',
      spec_a: '',
      spec_b: '',
      spec_c: '',
      unit: ''
    },
    type: '',
    number: '0',
    note: '',
    rating: ''
  })
  selectedHeaderIdx.value = dataset.headers.length - 1
}

const deleteHeader = (idx) => {
  const headers = form.value[selectedDatasetIdx.value].headers
  headers.splice(idx, 1)
  if (headers.length === 0) {
    selectedHeaderIdx.value = null
  } else if (selectedHeaderIdx.value >= headers.length) {
    selectedHeaderIdx.value = headers.length - 1
  }
}

const moveHeader = (idx, direction) => {
  const headers = form.value[selectedDatasetIdx.value].headers
  const newIdx = idx + direction
  if (newIdx < 0 || newIdx >= headers.length) return
  const temp = headers[idx]
  headers[idx] = headers[newIdx]
  headers[newIdx] = temp
  selectedHeaderIdx.value = newIdx
}

// Phase management (Pore Fluid)
const addPhase = () => {
  const dataset = form.value[selectedDatasetIdx.value]
  if (!dataset.fluid) dataset.fluid = { phases: [] }
  if (!dataset.fluid.phases) dataset.fluid.phases = []
  dataset.fluid.phases.push({
    component: '',
    fraction: '',
    activity: '',
    fugacity: '',
    unit: 'Vol%',
    composition: '',
    solutes: []
  })
  selectedPhaseIdx.value = dataset.fluid.phases.length - 1
}

const deletePhase = (idx) => {
  const phases = form.value[selectedDatasetIdx.value].fluid.phases
  phases.splice(idx, 1)
  if (phases.length === 0) {
    selectedPhaseIdx.value = null
  } else if (selectedPhaseIdx.value >= phases.length) {
    selectedPhaseIdx.value = phases.length - 1
  }
}

const movePhase = (idx, direction) => {
  const phases = form.value[selectedDatasetIdx.value].fluid.phases
  const newIdx = idx + direction
  if (newIdx < 0 || newIdx >= phases.length) return
  const temp = phases[idx]
  phases[idx] = phases[newIdx]
  phases[newIdx] = temp
  selectedPhaseIdx.value = newIdx
}

// Solute management
const addSolute = () => {
  const phase = form.value[selectedDatasetIdx.value].fluid.phases[selectedPhaseIdx.value]
  if (!phase.solutes) phase.solutes = []
  phase.solutes.push({
    component: '',
    value: '',
    error: '',
    unit: ''
  })
}

const deleteSolute = (idx) => {
  form.value[selectedDatasetIdx.value].fluid.phases[selectedPhaseIdx.value].solutes.splice(idx, 1)
}

const moveSolute = (idx, direction) => {
  const solutes = form.value[selectedDatasetIdx.value].fluid.phases[selectedPhaseIdx.value].solutes
  const newIdx = idx + direction
  if (newIdx < 0 || newIdx >= solutes.length) return
  const temp = solutes[idx]
  solutes[idx] = solutes[newIdx]
  solutes[newIdx] = temp
}

// Event handlers
const onDataSourceChange = () => {
  selectedHeaderIdx.value = 0
  selectedPhaseIdx.value = 0
}

const onHeaderTypeChange = () => {
  const header = form.value[selectedDatasetIdx.value].headers[selectedHeaderIdx.value]
  if (header) {
    header.header.spec_a = ''
    header.header.spec_b = ''
    header.header.unit = ''
  }
}

// Handle form submit
const handleSubmit = () => {
  // Validate
  const errors = []

  // Validate each dataset
  form.value.forEach((dataset, idx) => {
    // Data Source is required
    if (!dataset.data || dataset.data.trim() === '') {
      errors.push(`Data (source) cannot be blank for Dataset ${idx + 1}.`)
    }

    // Data Type is required
    if (!dataset.type || dataset.type.trim() === '') {
      errors.push(`Data Type cannot be blank for Dataset ${idx + 1}.`)
    }

    // File is required (path must exist)
    if (!dataset.path || dataset.path.trim() === '') {
      errors.push(`File cannot be blank for Dataset ${idx + 1}.`)
    }
  })

  if (errors.length > 0) {
    toast.add({
      severity: 'error',
      summary: 'Validation Error',
      detail: errors.join('\n'),
      life: 5000
    })
    return
  }

  const cleanedData = {
    keys: {
      facility: '',
      apparatus: '',
      sample: '',
      user: '',
      experiment: ''
    },
    datasets: form.value.filter(ds => ds.data || ds.type || ds.id)
  }
  emit('submit', cleanedData)
}
</script>

<style scoped>
.data-form {
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

.data-layout {
  display: flex;
  gap: 1rem;
  min-height: 300px;
}

.dataset-tabs {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 120px;
  max-width: 150px;
  flex-shrink: 0;
  padding-top: 0.25rem;
}

.dataset-tab-btn {
  width: 100%;
  justify-content: flex-start;
  text-align: left;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.dataset-detail {
  flex: 1;
  display: flex;
  gap: 0.75rem;
  border: 1px solid var(--p-surface-700);
  border-radius: 6px;
  padding: 1rem;
  background: var(--p-surface-900);
}

.dataset-content {
  flex: 1;
}

.dataset-actions {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  flex-shrink: 0;
}

.parameters-fieldset,
.solutes-fieldset {
  border: 1px solid var(--p-surface-700);
  border-radius: 6px;
  padding: 0.75rem;
  margin-top: 1rem;
}

.parameters-fieldset legend,
.solutes-fieldset legend {
  color: var(--p-surface-400);
  font-weight: 500;
  font-size: 0.875rem;
  padding: 0 0.5rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.param-header-row,
.solute-header-row {
  display: flex;
  gap: 0.5rem;
  padding: 0.5rem 0;
  font-weight: 600;
  font-size: 0.875rem;
  color: var(--p-surface-300);
  border-bottom: 1px solid var(--p-surface-700);
}

.param-row,
.solute-row {
  display: flex;
  gap: 0.5rem;
  padding: 0.5rem 0;
  align-items: center;
  border-bottom: 1px solid var(--p-surface-800);
}

.param-row:last-child,
.solute-row:last-child {
  border-bottom: none;
}

.param-col,
.solute-col {
  flex-shrink: 0;
}

.param-col-data { width: 150px; }
.param-col-value { width: 80px; }
.param-col-error { width: 80px; }
.param-col-unit { width: 100px; }
.param-col-prefix { width: 80px; }
.param-col-note { flex: 1; min-width: 120px; }
.param-col-actions { display: flex; gap: 0.25rem; width: auto; }

.solute-col-component { width: 140px; }
.solute-col-value { width: 100px; }
.solute-col-error { width: 100px; }
.solute-col-unit { width: 120px; }
.solute-col-actions { display: flex; gap: 0.25rem; width: auto; }

/* Headers layout (nested tabs) */
.headers-layout,
.phases-layout {
  display: flex;
  gap: 0.75rem;
}

.header-tabs,
.phase-tabs {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 100px;
  max-width: 120px;
  flex-shrink: 0;
}

.header-tab-btn,
.phase-tab-btn {
  width: 100%;
  justify-content: flex-start;
  text-align: left;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.header-detail,
.phase-detail {
  flex: 1;
  display: flex;
  gap: 0.5rem;
  border: 1px solid var(--p-surface-700);
  border-radius: 4px;
  padding: 0.75rem;
  background: var(--p-surface-800);
}

.header-content,
.phase-content {
  flex: 1;
}

.header-actions,
.phase-actions {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  flex-shrink: 0;
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
