<template>
  <form @submit.prevent="handleSubmit" class="data-form">
    <!-- Datasets Section -->
    <fieldset class="form-section">
      <legend>
        Datasets
        <button type="button" class="add-btn" @click="addDataset">Add Dataset</button>
      </legend>

      <div v-if="localData.datasets && localData.datasets.length > 0" class="datasets-container">
        <!-- Dataset tabs on left -->
        <div class="dataset-tabs">
          <button
            v-for="(dataset, idx) in localData.datasets"
            :key="idx"
            type="button"
            class="dataset-tab"
            :class="{ active: activeDatasetIndex === idx }"
            @click="activeDatasetIndex = idx"
          >
            <span>{{ dataset.data || 'Dataset ' + (idx + 1) }}</span>
          </button>
        </div>

        <!-- Dataset content on right -->
        <div class="dataset-content">
          <div v-if="activeDataset" class="dataset-panel">
            <!-- Row 1: Data Source, Data Type, File -->
            <div class="form-row three-col">
              <div class="field">
                <label>Data<span class="required">*</span></label>
                <select v-model="activeDataset.data" @change="onDataSourceChange">
                  <option value="">Select...</option>
                  <option v-for="opt in DATA_SOURCE_TYPES" :key="opt" :value="opt">{{ opt }}</option>
                </select>
              </div>
              <div class="field">
                <label>Data Type<span class="required">*</span></label>
                <select v-model="activeDataset.type" @change="onDataTypeChange">
                  <option value="">Select...</option>
                  <option v-for="opt in DATA_TYPE_OPTIONS" :key="opt" :value="opt">{{ opt }}</option>
                </select>
                <input
                  v-if="activeDataset.type === 'Other'"
                  v-model="activeDataset.other_type"
                  type="text"
                  placeholder="enter other data type here..."
                  class="other-input"
                />
              </div>
              <div class="field">
                <label>Choose File<span class="required">*</span></label>
                <input type="file" @change="onFileChange" />
                <div v-if="activeDataset.path" class="file-path">{{ activeDataset.path }}</div>
              </div>
            </div>

            <!-- Row 2: Data ID, File Format, Data Quality -->
            <div class="form-row three-col">
              <div class="field">
                <label>Data ID</label>
                <input v-model="activeDataset.id" type="text" />
              </div>
              <div class="field">
                <label>File Format</label>
                <select v-model="activeDataset.format" @change="onFormatChange">
                  <option value="">Select...</option>
                  <option v-for="opt in DATA_FILE_FORMATS" :key="opt" :value="opt">{{ opt }}</option>
                </select>
                <input
                  v-if="activeDataset.format === 'Other'"
                  v-model="activeDataset.other_format"
                  type="text"
                  placeholder="enter other data format here..."
                  class="other-input"
                />
              </div>
              <div class="field">
                <label>Data Quality</label>
                <select v-model="activeDataset.rating">
                  <option value="">Select...</option>
                  <option v-for="opt in DATA_QUALITY_RATINGS" :key="opt" :value="opt">{{ opt }}</option>
                </select>
              </div>
            </div>

            <!-- Row 3: Description -->
            <div class="form-row">
              <div class="field full-width">
                <label>Description</label>
                <textarea v-model="activeDataset.description" rows="3"></textarea>
              </div>
            </div>

            <!-- Conditional: Parameters (when data === 'Parameters') -->
            <fieldset v-if="activeDataset.data === 'Parameters'" class="sub-section">
              <legend>
                Parameter List
                <button type="button" class="add-btn" @click="addParameter">Add Parameter</button>
              </legend>
              <div v-if="activeDataset.parameters && activeDataset.parameters.length > 0" class="parameters-table">
                <div class="table-header">
                  <div class="col-data">Data</div>
                  <div class="col-value">Value</div>
                  <div class="col-error">Error</div>
                  <div class="col-unit">Unit</div>
                  <div class="col-prefix">Prefix</div>
                  <div class="col-note">Note</div>
                  <div class="col-actions"></div>
                </div>
                <div
                  v-for="(param, pIdx) in activeDataset.parameters"
                  :key="pIdx"
                  class="table-row"
                >
                  <div class="col-data">
                    <select v-model="param.control">
                      <option v-for="opt in DATA_PARAMETER_CONTROLS" :key="opt" :value="opt">{{ opt }}</option>
                    </select>
                  </div>
                  <div class="col-value">
                    <input v-model="param.value" type="text" />
                  </div>
                  <div class="col-error">
                    <input v-model="param.error" type="text" />
                  </div>
                  <div class="col-unit">
                    <select v-model="param.unit">
                      <option v-for="opt in UNIT_TYPES" :key="opt" :value="opt">{{ opt }}</option>
                    </select>
                  </div>
                  <div class="col-prefix">
                    <select v-model="param.prefix">
                      <option v-for="opt in UNIT_PREFIXES" :key="opt" :value="opt">{{ opt }}</option>
                    </select>
                  </div>
                  <div class="col-note">
                    <input v-model="param.note" type="text" />
                  </div>
                  <div class="col-actions">
                    <button type="button" class="action-btn delete-btn" @click="deleteParameter(pIdx)" title="Delete">X</button>
                    <button type="button" class="action-btn" @click="moveParameterUp(pIdx)" :disabled="pIdx === 0" title="Move Up">^</button>
                    <button type="button" class="action-btn" @click="moveParameterDown(pIdx)" :disabled="pIdx === activeDataset.parameters.length - 1" title="Move Down">v</button>
                  </div>
                </div>
              </div>
              <div v-else class="empty-message">No parameters added yet.</div>
            </fieldset>

            <!-- Conditional: Time Series Headers (when data === 'Time Series') -->
            <fieldset v-if="activeDataset.data === 'Time Series'" class="sub-section">
              <legend>
                Data Headers
                <button type="button" class="add-btn" @click="addHeader">Add Header</button>
              </legend>
              <div v-if="activeDataset.headers && activeDataset.headers.length > 0" class="headers-container">
                <!-- Header tabs on left -->
                <div class="header-tabs">
                  <button
                    v-for="(hdr, hIdx) in activeDataset.headers"
                    :key="hIdx"
                    type="button"
                    class="header-tab"
                    :class="{ active: activeHeaderIndex === hIdx }"
                    @click="activeHeaderIndex = hIdx"
                  >
                    <span>{{ hdr.header?.header || 'Header ' + (hIdx + 1) }}</span>
                  </button>
                </div>

                <!-- Header content on right -->
                <div class="header-content">
                  <div v-if="activeHeader" class="header-panel">
                    <!-- Row 1: Header Type -->
                    <div class="form-row">
                      <div class="field">
                        <label>Header</label>
                        <select v-model="activeHeader.header.header" @change="onHeaderTypeChange">
                          <option v-for="opt in CHANNEL_HEADER_TYPES" :key="opt" :value="opt">{{ opt }}</option>
                        </select>
                      </div>
                    </div>

                    <!-- Row 2: Specifiers and Unit -->
                    <div class="form-row four-col">
                      <div class="field">
                        <label>Specifier A</label>
                        <select v-if="specAOptions.length > 0" v-model="activeHeader.header.spec_a">
                          <option v-for="opt in specAOptions" :key="opt" :value="opt">{{ opt }}</option>
                        </select>
                        <input v-else v-model="activeHeader.header.spec_a" type="text" />
                      </div>
                      <div class="field">
                        <label>Specifier B</label>
                        <select v-if="specBOptions.length > 0" v-model="activeHeader.header.spec_b">
                          <option v-for="opt in specBOptions" :key="opt" :value="opt">{{ opt }}</option>
                        </select>
                        <input v-else v-model="activeHeader.header.spec_b" type="text" />
                      </div>
                      <div class="field">
                        <label>Other Specifier</label>
                        <input v-model="activeHeader.header.spec_c" type="text" />
                      </div>
                      <div class="field">
                        <label>Unit</label>
                        <select v-if="unitOptions.length > 0" v-model="activeHeader.header.unit">
                          <option v-for="opt in unitOptions" :key="opt" :value="opt">{{ opt }}</option>
                        </select>
                        <input v-else v-model="activeHeader.header.unit" type="text" />
                      </div>
                    </div>

                    <!-- Row 3: Type, Channel #, Data Quality -->
                    <div class="form-row three-col">
                      <div class="field">
                        <label>Type</label>
                        <select v-model="activeHeader.type">
                          <option value=""></option>
                          <option v-for="opt in DATA_HEADER_CHANNEL_TYPES" :key="opt" :value="opt">{{ opt }}</option>
                        </select>
                      </div>
                      <div class="field">
                        <label>Channel #</label>
                        <select v-model="activeHeader.number">
                          <option v-for="n in 33" :key="n-1" :value="String(n-1)">{{ n - 1 }}</option>
                        </select>
                      </div>
                      <div class="field">
                        <label>Data Quality</label>
                        <select v-model="activeHeader.rating">
                          <option v-for="opt in DATA_QUALITY_RATINGS" :key="opt" :value="opt">{{ opt }}</option>
                        </select>
                      </div>
                    </div>

                    <!-- Row 4: Notes -->
                    <div class="form-row">
                      <div class="field full-width">
                        <label>Notes</label>
                        <textarea v-model="activeHeader.note" rows="2"></textarea>
                      </div>
                    </div>

                    <!-- Action buttons -->
                    <div class="header-actions">
                      <button type="button" class="action-btn delete-btn" @click="deleteHeader(activeHeaderIndex)" title="Delete">Delete</button>
                      <button type="button" class="action-btn" @click="moveHeaderUp(activeHeaderIndex)" :disabled="activeHeaderIndex === 0" title="Move Up">Up</button>
                      <button type="button" class="action-btn" @click="moveHeaderDown(activeHeaderIndex)" :disabled="activeHeaderIndex === activeDataset.headers.length - 1" title="Move Down">Down</button>
                    </div>
                  </div>
                </div>
              </div>
              <div v-else class="empty-message">No headers added yet.</div>
            </fieldset>

            <!-- Conditional: Pore Fluid Phases (when data === 'Pore Fluid') -->
            <fieldset v-if="activeDataset.data === 'Pore Fluid'" class="sub-section">
              <legend>
                Pore Fluid Phases
                <button type="button" class="add-btn" @click="addPhase">Add Phase</button>
              </legend>
              <div v-if="activeDataset.fluid?.phases && activeDataset.fluid.phases.length > 0" class="phases-container">
                <!-- Phase tabs on left -->
                <div class="phase-tabs">
                  <button
                    v-for="(phase, phIdx) in activeDataset.fluid.phases"
                    :key="phIdx"
                    type="button"
                    class="phase-tab"
                    :class="{ active: activePhaseIndex === phIdx }"
                    @click="activePhaseIndex = phIdx"
                  >
                    <span>Phase {{ phIdx + 1 }}</span>
                  </button>
                </div>

                <!-- Phase content on right -->
                <div class="phase-content">
                  <div v-if="activePhase" class="phase-panel">
                    <!-- Row 1: Component, Fraction, Activity -->
                    <div class="form-row three-col">
                      <div class="field">
                        <label>Component</label>
                        <input v-model="activePhase.component" type="text" />
                      </div>
                      <div class="field">
                        <label>Fraction</label>
                        <input v-model="activePhase.fraction" type="text" />
                      </div>
                      <div class="field">
                        <label>Activity</label>
                        <input v-model="activePhase.activity" type="text" />
                      </div>
                    </div>

                    <!-- Row 2: Fugacity, Unit, Chemistry Data -->
                    <div class="form-row three-col">
                      <div class="field">
                        <label>Fugacity</label>
                        <input v-model="activePhase.fugacity" type="text" />
                      </div>
                      <div class="field">
                        <label>Unit</label>
                        <select v-model="activePhase.unit">
                          <option v-for="opt in PHASE_UNIT_OPTIONS" :key="opt" :value="opt">{{ opt }}</option>
                        </select>
                      </div>
                      <div class="field">
                        <label>Chemistry Data</label>
                        <select v-model="activePhase.composition">
                          <option v-for="opt in CHEMISTRY_DATA_OPTIONS" :key="opt" :value="opt">{{ opt }}</option>
                        </select>
                      </div>
                    </div>

                    <!-- Chemistry Solutes (when composition === 'Chemistry') -->
                    <fieldset v-if="activePhase.composition === 'Chemistry'" class="solutes-section">
                      <legend>
                        Chemistry
                        <button type="button" class="add-btn" @click="addSolute">Add Solute</button>
                      </legend>
                      <div v-if="activePhase.solutes && activePhase.solutes.length > 0" class="solutes-table">
                        <div class="table-header">
                          <div class="col-component">Component</div>
                          <div class="col-value">Value</div>
                          <div class="col-error">Error</div>
                          <div class="col-unit">Unit</div>
                          <div class="col-actions"></div>
                        </div>
                        <div
                          v-for="(solute, sIdx) in activePhase.solutes"
                          :key="sIdx"
                          class="table-row"
                        >
                          <div class="col-component">
                            <select v-model="solute.component">
                              <option v-for="opt in SOLUTE_COMPONENTS" :key="opt" :value="opt">{{ opt }}</option>
                            </select>
                          </div>
                          <div class="col-value">
                            <input v-model="solute.value" type="text" />
                          </div>
                          <div class="col-error">
                            <input v-model="solute.error" type="text" />
                          </div>
                          <div class="col-unit">
                            <select v-model="solute.unit">
                              <option v-for="opt in SOLUTE_UNITS" :key="opt" :value="opt">{{ opt }}</option>
                            </select>
                          </div>
                          <div class="col-actions">
                            <button type="button" class="action-btn delete-btn" @click="deleteSolute(sIdx)" title="Delete">X</button>
                            <button type="button" class="action-btn" @click="moveSoluteUp(sIdx)" :disabled="sIdx === 0" title="Move Up">^</button>
                            <button type="button" class="action-btn" @click="moveSoluteDown(sIdx)" :disabled="sIdx === activePhase.solutes.length - 1" title="Move Down">v</button>
                          </div>
                        </div>
                      </div>
                      <div v-else class="empty-message">No solutes added yet.</div>
                    </fieldset>

                    <!-- Phase action buttons -->
                    <div class="phase-actions">
                      <button type="button" class="action-btn delete-btn" @click="deletePhase(activePhaseIndex)" title="Delete">Delete</button>
                      <button type="button" class="action-btn" @click="movePhaseUp(activePhaseIndex)" :disabled="activePhaseIndex === 0" title="Move Up">Up</button>
                      <button type="button" class="action-btn" @click="movePhaseDown(activePhaseIndex)" :disabled="activePhaseIndex === activeDataset.fluid.phases.length - 1" title="Move Down">Down</button>
                    </div>
                  </div>
                </div>
              </div>
              <div v-else class="empty-message">No phases added yet.</div>
            </fieldset>

            <!-- Dataset action buttons -->
            <div class="dataset-actions">
              <button type="button" class="action-btn delete-btn" @click="deleteDataset(activeDatasetIndex)" title="Delete Dataset">Delete Dataset</button>
              <button type="button" class="action-btn" @click="moveDatasetUp(activeDatasetIndex)" :disabled="activeDatasetIndex === 0" title="Move Up">Move Up</button>
              <button type="button" class="action-btn" @click="moveDatasetDown(activeDatasetIndex)" :disabled="activeDatasetIndex === localData.datasets.length - 1" title="Move Down">Move Down</button>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="empty-message">No datasets added yet. Click "Add Dataset" to begin.</div>
    </fieldset>

    <!-- Form Footer -->
    <div class="form-footer">
      <button type="button" class="btn-secondary" @click="$emit('cancel')">Cancel</button>
      <button type="submit" class="btn-primary">Save</button>
    </div>
  </form>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
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
  DAQ_DATA_FIELDS,
  getDAQOptionsForHeader
} from '@/schemas/laps-enums.js'

const props = defineProps({
  initialData: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits(['submit', 'cancel'])

// Local state
const localData = ref({
  keys: {
    facility: '',
    apparatus: '',
    sample: '',
    user: '',
    experiment: ''
  },
  datasets: []
})

const activeDatasetIndex = ref(0)
const activeHeaderIndex = ref(0)
const activePhaseIndex = ref(0)

// Computed: active dataset
const activeDataset = computed(() => {
  if (localData.value.datasets && localData.value.datasets.length > 0) {
    return localData.value.datasets[activeDatasetIndex.value]
  }
  return null
})

// Computed: active header (for Time Series)
const activeHeader = computed(() => {
  if (activeDataset.value?.headers && activeDataset.value.headers.length > 0) {
    return activeDataset.value.headers[activeHeaderIndex.value]
  }
  return null
})

// Computed: active phase (for Pore Fluid)
const activePhase = computed(() => {
  if (activeDataset.value?.fluid?.phases && activeDataset.value.fluid.phases.length > 0) {
    return activeDataset.value.fluid.phases[activePhaseIndex.value]
  }
  return null
})

// Computed: dynamic dropdown options for header specifiers
const specAOptions = computed(() => {
  if (!activeHeader.value?.header?.header) return []
  return getDAQOptionsForHeader(activeHeader.value.header.header, 'spec_a')
})

const specBOptions = computed(() => {
  if (!activeHeader.value?.header?.header) return []
  return getDAQOptionsForHeader(activeHeader.value.header.header, 'spec_b')
})

const unitOptions = computed(() => {
  if (!activeHeader.value?.header?.header) return []
  return getDAQOptionsForHeader(activeHeader.value.header.header, 'unit')
})

// Generate UUID
function generateUUID() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
    const r = Math.random() * 16 | 0
    const v = c === 'x' ? r : (r & 0x3 | 0x8)
    return v.toString(16)
  })
}

// Dataset management
function addDataset() {
  if (!localData.value.datasets) {
    localData.value.datasets = []
  }
  localData.value.datasets.push({
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
  activeDatasetIndex.value = localData.value.datasets.length - 1
  emitUpdate()
}

function deleteDataset(idx) {
  if (confirm('Are you sure you want to delete this dataset?\nThis cannot be undone.')) {
    localData.value.datasets.splice(idx, 1)
    if (activeDatasetIndex.value >= localData.value.datasets.length) {
      activeDatasetIndex.value = Math.max(0, localData.value.datasets.length - 1)
    }
    emitUpdate()
  }
}

function moveDatasetUp(idx) {
  if (idx > 0) {
    const temp = localData.value.datasets[idx]
    localData.value.datasets[idx] = localData.value.datasets[idx - 1]
    localData.value.datasets[idx - 1] = temp
    activeDatasetIndex.value = idx - 1
    emitUpdate()
  }
}

function moveDatasetDown(idx) {
  if (idx < localData.value.datasets.length - 1) {
    const temp = localData.value.datasets[idx]
    localData.value.datasets[idx] = localData.value.datasets[idx + 1]
    localData.value.datasets[idx + 1] = temp
    activeDatasetIndex.value = idx + 1
    emitUpdate()
  }
}

// Parameter management
function addParameter() {
  if (!activeDataset.value.parameters) {
    activeDataset.value.parameters = []
  }
  activeDataset.value.parameters.push({
    control: 'Weight',
    value: '',
    error: '',
    unit: 'degC',
    prefix: '-',
    note: ''
  })
  emitUpdate()
}

function deleteParameter(idx) {
  activeDataset.value.parameters.splice(idx, 1)
  emitUpdate()
}

function moveParameterUp(idx) {
  if (idx > 0) {
    const temp = activeDataset.value.parameters[idx]
    activeDataset.value.parameters[idx] = activeDataset.value.parameters[idx - 1]
    activeDataset.value.parameters[idx - 1] = temp
    emitUpdate()
  }
}

function moveParameterDown(idx) {
  if (idx < activeDataset.value.parameters.length - 1) {
    const temp = activeDataset.value.parameters[idx]
    activeDataset.value.parameters[idx] = activeDataset.value.parameters[idx + 1]
    activeDataset.value.parameters[idx + 1] = temp
    emitUpdate()
  }
}

// Header management (Time Series)
function addHeader() {
  if (!activeDataset.value.headers) {
    activeDataset.value.headers = []
  }
  activeDataset.value.headers.push({
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
    rating: 'Good'
  })
  activeHeaderIndex.value = activeDataset.value.headers.length - 1
  emitUpdate()
}

function deleteHeader(idx) {
  if (confirm('Are you sure you want to delete this header?')) {
    activeDataset.value.headers.splice(idx, 1)
    if (activeHeaderIndex.value >= activeDataset.value.headers.length) {
      activeHeaderIndex.value = Math.max(0, activeDataset.value.headers.length - 1)
    }
    emitUpdate()
  }
}

function moveHeaderUp(idx) {
  if (idx > 0) {
    const temp = activeDataset.value.headers[idx]
    activeDataset.value.headers[idx] = activeDataset.value.headers[idx - 1]
    activeDataset.value.headers[idx - 1] = temp
    activeHeaderIndex.value = idx - 1
    emitUpdate()
  }
}

function moveHeaderDown(idx) {
  if (idx < activeDataset.value.headers.length - 1) {
    const temp = activeDataset.value.headers[idx]
    activeDataset.value.headers[idx] = activeDataset.value.headers[idx + 1]
    activeDataset.value.headers[idx + 1] = temp
    activeHeaderIndex.value = idx + 1
    emitUpdate()
  }
}

// Phase management (Pore Fluid)
function addPhase() {
  if (!activeDataset.value.fluid) {
    activeDataset.value.fluid = { phases: [] }
  }
  if (!activeDataset.value.fluid.phases) {
    activeDataset.value.fluid.phases = []
  }
  activeDataset.value.fluid.phases.push({
    component: '',
    fraction: '',
    activity: '',
    fugacity: '',
    unit: 'Vol%',
    composition: 'Chemistry',
    solutes: []
  })
  activePhaseIndex.value = activeDataset.value.fluid.phases.length - 1
  emitUpdate()
}

function deletePhase(idx) {
  if (confirm('Are you sure you want to delete this phase?')) {
    activeDataset.value.fluid.phases.splice(idx, 1)
    if (activePhaseIndex.value >= activeDataset.value.fluid.phases.length) {
      activePhaseIndex.value = Math.max(0, activeDataset.value.fluid.phases.length - 1)
    }
    emitUpdate()
  }
}

function movePhaseUp(idx) {
  if (idx > 0) {
    const temp = activeDataset.value.fluid.phases[idx]
    activeDataset.value.fluid.phases[idx] = activeDataset.value.fluid.phases[idx - 1]
    activeDataset.value.fluid.phases[idx - 1] = temp
    activePhaseIndex.value = idx - 1
    emitUpdate()
  }
}

function movePhaseDown(idx) {
  if (idx < activeDataset.value.fluid.phases.length - 1) {
    const temp = activeDataset.value.fluid.phases[idx]
    activeDataset.value.fluid.phases[idx] = activeDataset.value.fluid.phases[idx + 1]
    activeDataset.value.fluid.phases[idx + 1] = temp
    activePhaseIndex.value = idx + 1
    emitUpdate()
  }
}

// Solute management
function addSolute() {
  if (!activePhase.value.solutes) {
    activePhase.value.solutes = []
  }
  activePhase.value.solutes.push({
    component: 'pH',
    value: '',
    error: '',
    unit: 'Vol%'
  })
  emitUpdate()
}

function deleteSolute(idx) {
  activePhase.value.solutes.splice(idx, 1)
  emitUpdate()
}

function moveSoluteUp(idx) {
  if (idx > 0) {
    const temp = activePhase.value.solutes[idx]
    activePhase.value.solutes[idx] = activePhase.value.solutes[idx - 1]
    activePhase.value.solutes[idx - 1] = temp
    emitUpdate()
  }
}

function moveSoluteDown(idx) {
  if (idx < activePhase.value.solutes.length - 1) {
    const temp = activePhase.value.solutes[idx]
    activePhase.value.solutes[idx] = activePhase.value.solutes[idx + 1]
    activePhase.value.solutes[idx + 1] = temp
    emitUpdate()
  }
}

// Event handlers
function onDataSourceChange() {
  // Reset conditional data when source changes
  activeHeaderIndex.value = 0
  activePhaseIndex.value = 0
  emitUpdate()
}

function onDataTypeChange() {
  if (activeDataset.value.type !== 'Other') {
    activeDataset.value.other_type = ''
  }
  emitUpdate()
}

function onFormatChange() {
  if (activeDataset.value.format !== 'Other') {
    activeDataset.value.other_format = ''
  }
  emitUpdate()
}

function onHeaderTypeChange() {
  // Clear specifiers when header type changes
  if (activeHeader.value) {
    activeHeader.value.header.spec_a = ''
    activeHeader.value.header.spec_b = ''
    activeHeader.value.header.unit = ''
  }
  emitUpdate()
}

function onFileChange(event) {
  const file = event.target.files[0]
  if (file) {
    activeDataset.value.path = file.name
    emitUpdate()
  }
}

// Emit updates (not used in modal pattern, but kept for compatibility)
function emitUpdate() {
  // No-op in modal pattern
}

// Handle form submission
function handleSubmit() {
  // Clean the data before submitting
  const cleanedData = JSON.parse(JSON.stringify(localData.value))

  // Clean up empty datasets
  if (cleanedData.datasets) {
    cleanedData.datasets = cleanedData.datasets.filter(ds => ds.data || ds.type || ds.id)
  }

  emit('submit', cleanedData)
}

// Initialize from props
onMounted(() => {
  if (props.initialData && Object.keys(props.initialData).length > 0) {
    localData.value = JSON.parse(JSON.stringify(props.initialData))
  }
  // Ensure structure
  if (!localData.value.keys) {
    localData.value.keys = { facility: '', apparatus: '', sample: '', user: '', experiment: '' }
  }
  if (!localData.value.datasets) {
    localData.value.datasets = []
  }
})

// Watch for prop changes
watch(() => props.initialData, (newVal) => {
  if (newVal && Object.keys(newVal).length > 0) {
    localData.value = JSON.parse(JSON.stringify(newVal))
    if (!localData.value.keys) {
      localData.value.keys = { facility: '', apparatus: '', sample: '', user: '', experiment: '' }
    }
    if (!localData.value.datasets) {
      localData.value.datasets = []
    }
  }
}, { deep: true })
</script>

<style scoped>
.data-form {
  font-size: 14px;
}

.form-section {
  border: 1px solid #444;
  border-radius: 4px;
  padding: 15px;
  margin-bottom: 15px;
  background: #2a2a2a;
}

.form-section > legend {
  color: #fff;
  font-weight: 600;
  padding: 0 10px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.sub-section {
  border: 1px solid #555;
  border-radius: 4px;
  padding: 10px;
  margin-top: 15px;
  background: #333;
}

.sub-section > legend {
  color: #ccc;
  font-weight: 500;
  padding: 0 8px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.solutes-section {
  border: 1px solid #666;
  border-radius: 4px;
  padding: 10px;
  margin-top: 10px;
  background: #3a3a3a;
}

.solutes-section > legend {
  color: #bbb;
  font-weight: 500;
  padding: 0 6px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.add-btn {
  background: #dc3545;
  color: white;
  border: none;
  padding: 4px 12px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 12px;
}

.add-btn:hover {
  background: #c82333;
}

.datasets-container,
.headers-container,
.phases-container {
  display: flex;
  gap: 10px;
}

.dataset-tabs,
.header-tabs,
.phase-tabs {
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-width: 120px;
}

.dataset-tab,
.header-tab,
.phase-tab {
  background: #444;
  color: #ccc;
  border: 1px solid #555;
  padding: 8px 12px;
  text-align: left;
  cursor: pointer;
  border-radius: 4px;
  font-size: 13px;
}

.dataset-tab:hover,
.header-tab:hover,
.phase-tab:hover {
  background: #555;
}

.dataset-tab.active,
.header-tab.active,
.phase-tab.active {
  background: #dc3545;
  color: white;
  border-color: #dc3545;
}

.dataset-content,
.header-content,
.phase-content {
  flex: 1;
  min-width: 0;
}

.dataset-panel,
.header-panel,
.phase-panel {
  background: #333;
  border: 1px solid #555;
  border-radius: 4px;
  padding: 15px;
}

.form-row {
  display: flex;
  gap: 15px;
  margin-bottom: 12px;
}

.form-row.three-col > .field {
  flex: 1;
}

.form-row.four-col > .field {
  flex: 1;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.field.full-width {
  flex: 1;
}

.field label {
  color: #aaa;
  font-size: 12px;
}

.field .required {
  color: #dc3545;
  margin-left: 2px;
}

.field input,
.field select,
.field textarea {
  background: #222;
  border: 1px solid #555;
  color: #fff;
  padding: 8px;
  border-radius: 4px;
  font-size: 13px;
}

.field input:focus,
.field select:focus,
.field textarea:focus {
  outline: none;
  border-color: #dc3545;
}

.field textarea {
  resize: vertical;
}

.other-input {
  margin-top: 5px;
}

.file-path {
  color: #888;
  font-size: 11px;
  margin-top: 3px;
}

/* Tables */
.parameters-table,
.solutes-table {
  width: 100%;
}

.table-header,
.table-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.table-header {
  font-weight: 600;
  color: #aaa;
  padding: 5px 0;
  border-bottom: 1px solid #555;
  font-size: 12px;
}

.table-row {
  padding: 5px 0;
  border-bottom: 1px solid #444;
}

/* Parameter table columns */
.col-data { flex: 2; }
.col-value { flex: 1; }
.col-error { flex: 1; }
.col-unit { flex: 1; }
.col-prefix { flex: 1; }
.col-note { flex: 1.5; }
.col-actions { width: 90px; display: flex; gap: 3px; }

/* Solute table columns */
.col-component { flex: 1.5; }

.table-row input,
.table-row select {
  width: 100%;
  background: #222;
  border: 1px solid #555;
  color: #fff;
  padding: 5px;
  border-radius: 3px;
  font-size: 12px;
}

.action-btn {
  background: #444;
  border: 1px solid #555;
  color: #ccc;
  cursor: pointer;
  padding: 3px 8px;
  border-radius: 3px;
  font-size: 11px;
}

.action-btn:hover:not(:disabled) {
  background: #555;
  color: #fff;
}

.action-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.action-btn.delete-btn {
  background: #8b0000;
  border-color: #a00;
}

.action-btn.delete-btn:hover:not(:disabled) {
  background: #a52a2a;
}

.dataset-actions,
.header-actions,
.phase-actions {
  display: flex;
  gap: 5px;
  margin-top: 15px;
  padding-top: 10px;
  border-top: 1px solid #555;
}

.empty-message {
  color: #888;
  font-style: italic;
  padding: 20px;
  text-align: center;
}

/* Form footer */
.form-footer {
  display: flex;
  justify-content: center;
  gap: 20px;
  margin-top: 20px;
  padding-top: 15px;
  border-top: 1px solid #444;
}

.btn-primary {
  background: #dc3545;
  color: white;
  border: none;
  padding: 10px 30px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 500;
}

.btn-primary:hover {
  background: #c82333;
}

.btn-secondary {
  background: transparent;
  color: #ccc;
  border: 1px solid #666;
  padding: 10px 30px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
}

.btn-secondary:hover {
  background: #444;
}
</style>
