<template>
  <v-form @submit.prevent="handleSubmit" class="sample-form">
    <!-- Basic Information -->
    <v-expansion-panels v-model="openPanels" multiple>
      <v-expansion-panel value="basic">
        <v-expansion-panel-title>Basic Information</v-expansion-panel-title>
        <v-expansion-panel-text>
          <v-row>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.name"
                label="Sample Name *"
                placeholder="e.g., Carrara Marble #1"
                required
                :rules="[v => !!v || 'Sample name is required']"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.id"
                label="Sample ID"
                placeholder="Internal sample identifier"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.igsn"
                label="IGSN"
                placeholder="International Geo Sample Number"
              />
            </v-col>
            <v-col cols="12">
              <v-textarea
                v-model="form.description"
                label="Description"
                placeholder="Brief description of the sample..."
                rows="3"
              />
            </v-col>
          </v-row>

          <!-- Parent Sample -->
          <v-divider class="my-4" />
          <div class="text-subtitle-2 mb-3">Parent Sample (Optional)</div>
          <v-row>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.parent.name"
                label="Parent Name"
                placeholder="Parent sample name"
                density="compact"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.parent.id"
                label="Parent ID"
                placeholder="Parent sample ID"
                density="compact"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.parent.igsn"
                label="Parent IGSN"
                placeholder="Parent IGSN"
                density="compact"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.parent.description"
                label="Parent Description"
                placeholder="Parent description"
                density="compact"
              />
            </v-col>
          </v-row>
        </v-expansion-panel-text>
      </v-expansion-panel>

      <!-- Material -->
      <v-expansion-panel value="material">
        <v-expansion-panel-title>Material</v-expansion-panel-title>
        <v-expansion-panel-text>
          <!-- Material Type -->
          <div class="text-subtitle-2 mb-3">Material Type</div>
          <v-row>
            <v-col cols="12" md="6">
              <v-select
                v-model="form.material.material.type"
                :items="MATERIAL_TYPES"
                label="Type"
                placeholder="Select type..."
                clearable
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.material.material.name"
                label="Name"
                placeholder="e.g., Carrara Marble"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.material.material.state"
                label="State"
                placeholder="e.g., Homogeneous"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.material.material.note"
                label="Note"
                placeholder="Additional notes"
              />
            </v-col>
          </v-row>

          <!-- Mineralogy / Composition -->
          <v-divider class="my-4" />
          <div class="text-subtitle-2 mb-2">Mineralogy / Composition</div>
          <div class="text-caption text-medium-emphasis mb-3">
            Define the mineral phases and their proportions in this sample.
          </div>

          <v-card
            v-for="(phase, idx) in form.material.composition"
            :key="idx"
            variant="outlined"
            class="mb-3 pa-3"
          >
            <div class="d-flex justify-space-between align-center mb-2">
              <span class="text-caption">Phase {{ idx + 1 }}</span>
              <v-btn
                size="small"
                color="error"
                variant="text"
                @click="removePhase(idx)"
              >
                Remove
              </v-btn>
            </div>
            <v-row dense>
              <v-col cols="6" md="3">
                <v-select
                  v-model="phase.mineral"
                  :items="MINERAL_TYPES"
                  label="Mineral"
                  density="compact"
                  clearable
                />
              </v-col>
              <v-col cols="6" md="3">
                <v-text-field
                  v-model="phase.fraction"
                  label="Fraction"
                  placeholder="e.g., 0.99"
                  density="compact"
                />
              </v-col>
              <v-col cols="6" md="3">
                <v-select
                  v-model="phase.unit"
                  :items="FRACTION_UNITS"
                  label="Unit"
                  density="compact"
                />
              </v-col>
              <v-col cols="6" md="3">
                <v-text-field
                  v-model="phase.grainsize"
                  label="Grain Size (um)"
                  placeholder="e.g., 150"
                  density="compact"
                />
              </v-col>
            </v-row>
          </v-card>

          <v-btn
            variant="outlined"
            size="small"
            @click="addPhase"
            prepend-icon="mdi-plus"
          >
            Add Mineral Phase
          </v-btn>

          <!-- Provenance -->
          <v-divider class="my-4" />
          <div class="text-subtitle-2 mb-3">Provenance</div>
          <v-row>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.material.provenance.formation"
                label="Formation"
                placeholder="e.g., Carrara (Italy)"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.material.provenance.member"
                label="Member"
                placeholder="e.g., Hettangian"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.material.provenance.submember"
                label="Submember"
                placeholder="Submember name"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.material.provenance.source"
                label="Source"
                placeholder="e.g., Quarry"
              />
            </v-col>
          </v-row>

          <!-- Location -->
          <div class="text-overline mt-4 mb-2">Location</div>
          <v-row dense>
            <v-col cols="6" md="3">
              <v-text-field
                v-model="form.material.provenance.location.street"
                label="Street"
                density="compact"
              />
            </v-col>
            <v-col cols="6" md="3">
              <v-text-field
                v-model="form.material.provenance.location.building"
                label="Building"
                density="compact"
              />
            </v-col>
            <v-col cols="6" md="3">
              <v-text-field
                v-model="form.material.provenance.location.city"
                label="City"
                density="compact"
              />
            </v-col>
            <v-col cols="6" md="3">
              <v-text-field
                v-model="form.material.provenance.location.state"
                label="State/Region"
                density="compact"
              />
            </v-col>
            <v-col cols="6" md="3">
              <v-text-field
                v-model="form.material.provenance.location.postcode"
                label="Postcode"
                density="compact"
              />
            </v-col>
            <v-col cols="6" md="3">
              <v-text-field
                v-model="form.material.provenance.location.country"
                label="Country"
                density="compact"
              />
            </v-col>
            <v-col cols="6" md="3">
              <v-text-field
                v-model="form.material.provenance.location.latitude"
                label="Latitude"
                placeholder="e.g., 44.0793"
                density="compact"
              />
            </v-col>
            <v-col cols="6" md="3">
              <v-text-field
                v-model="form.material.provenance.location.longitude"
                label="Longitude"
                placeholder="e.g., 10.0979"
                density="compact"
              />
            </v-col>
          </v-row>

          <!-- Texture -->
          <v-divider class="my-4" />
          <div class="text-subtitle-2 mb-3">Texture</div>
          <v-row>
            <v-col cols="6" md="3">
              <v-text-field
                v-model="form.material.texture.bedding"
                label="Bedding"
                placeholder="e.g., no bedding"
              />
            </v-col>
            <v-col cols="6" md="3">
              <v-text-field
                v-model="form.material.texture.lineation"
                label="Lineation"
                placeholder="e.g., no apparent lineation"
              />
            </v-col>
            <v-col cols="6" md="3">
              <v-text-field
                v-model="form.material.texture.foliation"
                label="Foliation"
                placeholder="e.g., no foliation"
              />
            </v-col>
            <v-col cols="6" md="3">
              <v-text-field
                v-model="form.material.texture.fault"
                label="Fault"
                placeholder="e.g., no faults"
              />
            </v-col>
          </v-row>
        </v-expansion-panel-text>
      </v-expansion-panel>

      <!-- Parameters -->
      <v-expansion-panel value="parameters">
        <v-expansion-panel-title>Parameters</v-expansion-panel-title>
        <v-expansion-panel-text>
          <div class="text-caption text-medium-emphasis mb-4">
            Pre-experimental sample parameters (weight, porosity, density, etc.).
          </div>

          <v-card
            v-for="(param, idx) in form.parameters"
            :key="idx"
            variant="outlined"
            class="mb-3 pa-3"
          >
            <div class="d-flex justify-space-between align-center mb-2">
              <span class="text-body-2 font-weight-medium">Parameter {{ idx + 1 }}</span>
              <v-btn
                size="small"
                color="error"
                variant="text"
                @click="removeParameter(idx)"
              >
                Remove
              </v-btn>
            </div>
            <v-row dense>
              <v-col cols="12" md="3">
                <v-select
                  v-model="param.control"
                  :items="SAMPLE_PARAMETER_TYPES"
                  label="Variable"
                  density="compact"
                  clearable
                />
              </v-col>
              <v-col cols="6" md="2">
                <v-text-field
                  v-model="param.value"
                  label="Value"
                  density="compact"
                />
              </v-col>
              <v-col cols="6" md="2">
                <v-select
                  v-model="param.unit"
                  :items="UNIT_TYPES"
                  label="Unit"
                  density="compact"
                  clearable
                />
              </v-col>
              <v-col cols="6" md="2">
                <v-select
                  v-model="param.prefix"
                  :items="prefixOptions"
                  label="Prefix"
                  density="compact"
                />
              </v-col>
              <v-col cols="12">
                <v-text-field
                  v-model="param.note"
                  label="Note (Measurement and Treatment)"
                  placeholder="Optional note"
                  density="compact"
                />
              </v-col>
            </v-row>
          </v-card>

          <v-btn
            variant="outlined"
            size="small"
            @click="addParameter"
            prepend-icon="mdi-plus"
          >
            Add Parameter
          </v-btn>
        </v-expansion-panel-text>
      </v-expansion-panel>

      <!-- Documents -->
      <v-expansion-panel value="documents">
        <v-expansion-panel-title>Documents</v-expansion-panel-title>
        <v-expansion-panel-text>
          <div class="text-caption text-medium-emphasis mb-4">
            Upload sample images, thin section photos, or other documentation.
          </div>
          <v-sheet
            rounded
            class="d-flex align-center justify-center pa-8"
            style="border: 2px dashed rgba(255,255,255,0.2)"
          >
            <span class="text-medium-emphasis">Document upload coming soon</span>
          </v-sheet>
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>

    <!-- Actions -->
    <div class="d-flex justify-center ga-3 mt-6">
      <v-btn
        variant="outlined"
        @click="$emit('cancel')"
      >
        Cancel
      </v-btn>
      <v-btn
        type="submit"
        color="primary"
        :disabled="!isValid"
      >
        Save Sample
      </v-btn>
    </div>
  </v-form>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import {
  MATERIAL_TYPES,
  MINERAL_TYPES,
  SAMPLE_PARAMETER_TYPES,
  FRACTION_UNITS,
  UNIT_TYPES,
  UNIT_PREFIXES
} from '@/schemas/laps-enums'

const props = defineProps({
  initialData: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits(['submit', 'cancel'])

const openPanels = ref(['basic'])

const prefixOptions = computed(() => ['-', ...UNIT_PREFIXES])

const createEmptyForm = () => ({
  name: '',
  id: '',
  igsn: '',
  description: '',
  parent: {
    name: '',
    id: '',
    igsn: '',
    description: ''
  },
  material: {
    material: {
      type: '',
      name: '',
      state: '',
      note: ''
    },
    composition: [],
    provenance: {
      formation: '',
      member: '',
      submember: '',
      source: '',
      location: {
        street: '',
        building: '',
        postcode: '',
        city: '',
        state: '',
        country: '',
        latitude: '',
        longitude: ''
      }
    },
    texture: {
      bedding: '',
      lineation: '',
      foliation: '',
      fault: ''
    }
  },
  parameters: [],
  documents: []
})

const form = ref(createEmptyForm())

// Populate form with initial data
watch(() => props.initialData, (data) => {
  if (data && Object.keys(data).length > 0) {
    form.value = {
      name: data.name || '',
      id: data.id || '',
      igsn: data.igsn || '',
      description: data.description || '',
      parent: {
        name: data.parent?.name || '',
        id: data.parent?.id || '',
        igsn: data.parent?.igsn || '',
        description: data.parent?.description || ''
      },
      material: {
        material: {
          type: data.material?.material?.type || '',
          name: data.material?.material?.name || '',
          state: data.material?.material?.state || '',
          note: data.material?.material?.note || ''
        },
        composition: data.material?.composition?.map(c => ({ ...c })) || [],
        provenance: {
          formation: data.material?.provenance?.formation || '',
          member: data.material?.provenance?.member || '',
          submember: data.material?.provenance?.submember || '',
          source: data.material?.provenance?.source || '',
          location: {
            street: data.material?.provenance?.location?.street || '',
            building: data.material?.provenance?.location?.building || '',
            postcode: data.material?.provenance?.location?.postcode || '',
            city: data.material?.provenance?.location?.city || '',
            state: data.material?.provenance?.location?.state || '',
            country: data.material?.provenance?.location?.country || '',
            latitude: data.material?.provenance?.location?.latitude || '',
            longitude: data.material?.provenance?.location?.longitude || ''
          }
        },
        texture: {
          bedding: data.material?.texture?.bedding || '',
          lineation: data.material?.texture?.lineation || '',
          foliation: data.material?.texture?.foliation || '',
          fault: data.material?.texture?.fault || ''
        }
      },
      parameters: data.parameters?.map(p => ({ ...p })) || [],
      documents: data.documents?.map(d => ({ ...d })) || []
    }
  }
}, { immediate: true, deep: true })

const isValid = computed(() => {
  return form.value.name.trim().length > 0
})

function addPhase() {
  form.value.material.composition.push({
    mineral: '',
    fraction: '',
    unit: 'Vol%',
    grainsize: ''
  })
}

function removePhase(idx) {
  form.value.material.composition.splice(idx, 1)
}

function addParameter() {
  form.value.parameters.push({
    control: '',
    value: '',
    unit: '',
    prefix: '-',
    note: ''
  })
}

function removeParameter(idx) {
  form.value.parameters.splice(idx, 1)
}

function handleSubmit() {
  if (!isValid.value) return
  emit('submit', form.value)
}
</script>

<style scoped>
.sample-form {
  max-width: 900px;
  margin: 0 auto;
}
</style>
