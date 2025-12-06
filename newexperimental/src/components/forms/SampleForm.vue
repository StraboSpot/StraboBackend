<template>
  <form @submit.prevent="handleSubmit" class="sample-form">
    <!-- Basic Information -->
    <Accordion :value="openPanels" multiple>
      <AccordionPanel value="basic">
        <AccordionHeader>Basic Information</AccordionHeader>
        <AccordionContent>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex flex-col gap-2">
              <label for="sampleName">Sample Name *</label>
              <InputText
                id="sampleName"
                v-model="form.name"
                placeholder="e.g., Carrara Marble #1"
                :invalid="!form.name"
              />
            </div>
            <div class="flex flex-col gap-2">
              <label for="sampleId">Sample ID</label>
              <InputText
                id="sampleId"
                v-model="form.id"
                placeholder="Internal sample identifier"
              />
            </div>
            <div class="flex flex-col gap-2">
              <label for="igsn">IGSN</label>
              <InputText
                id="igsn"
                v-model="form.igsn"
                placeholder="International Geo Sample Number"
              />
            </div>
            <div class="flex flex-col gap-2 md:col-span-2">
              <label for="description">Description</label>
              <Textarea
                id="description"
                v-model="form.description"
                placeholder="Brief description of the sample..."
                rows="3"
                autoResize
              />
            </div>
          </div>

          <!-- Parent Sample -->
          <Divider />
          <div class="text-sm font-semibold mb-3">Parent Sample (Optional)</div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex flex-col gap-2">
              <label class="text-sm">Parent Name</label>
              <InputText v-model="form.parent.name" placeholder="Parent sample name" />
            </div>
            <div class="flex flex-col gap-2">
              <label class="text-sm">Parent ID</label>
              <InputText v-model="form.parent.id" placeholder="Parent sample ID" />
            </div>
            <div class="flex flex-col gap-2">
              <label class="text-sm">Parent IGSN</label>
              <InputText v-model="form.parent.igsn" placeholder="Parent IGSN" />
            </div>
            <div class="flex flex-col gap-2">
              <label class="text-sm">Parent Description</label>
              <InputText v-model="form.parent.description" placeholder="Parent description" />
            </div>
          </div>
        </AccordionContent>
      </AccordionPanel>

      <!-- Material -->
      <AccordionPanel value="material">
        <AccordionHeader>Material</AccordionHeader>
        <AccordionContent>
          <!-- Material Type -->
          <div class="text-sm font-semibold mb-3">Material Type</div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div class="flex flex-col gap-2">
              <label class="text-sm">Type</label>
              <Select
                v-model="form.material.material.type"
                :options="MATERIAL_TYPES"
                placeholder="Select type..."
                showClear
              />
            </div>
            <div class="flex flex-col gap-2">
              <label class="text-sm">Name</label>
              <InputText v-model="form.material.material.name" placeholder="e.g., Carrara Marble" />
            </div>
            <div class="flex flex-col gap-2">
              <label class="text-sm">State</label>
              <InputText v-model="form.material.material.state" placeholder="e.g., Homogeneous" />
            </div>
            <div class="flex flex-col gap-2">
              <label class="text-sm">Note</label>
              <InputText v-model="form.material.material.note" placeholder="Additional notes" />
            </div>
          </div>

          <!-- Mineralogy / Composition -->
          <Divider />
          <div class="text-sm font-semibold mb-2">Mineralogy / Composition</div>
          <p class="text-xs text-surface-400 mb-3">
            Define the mineral phases and their proportions in this sample.
          </p>

          <div
            v-for="(phase, idx) in form.material.composition"
            :key="idx"
            class="p-3 mb-3 border border-surface-700 rounded-lg"
          >
            <div class="flex justify-between items-center mb-2">
              <span class="text-xs text-surface-400">Phase {{ idx + 1 }}</span>
              <Button
                size="small"
                severity="danger"
                text
                label="Remove"
                @click="removePhase(idx)"
              />
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <div class="flex flex-col gap-1">
                <label class="text-xs">Mineral</label>
                <Select v-model="phase.mineral" :options="MINERAL_TYPES" placeholder="Select..." showClear size="small" />
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-xs">Fraction</label>
                <InputText v-model="phase.fraction" placeholder="e.g., 0.99" size="small" />
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-xs">Unit</label>
                <Select v-model="phase.unit" :options="FRACTION_UNITS" size="small" />
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-xs">Grain Size (um)</label>
                <InputText v-model="phase.grainsize" placeholder="e.g., 150" size="small" />
              </div>
            </div>
          </div>

          <Button
            size="small"
            outlined
            icon="pi pi-plus"
            label="Add Mineral Phase"
            @click="addPhase"
          />

          <!-- Provenance -->
          <Divider />
          <div class="text-sm font-semibold mb-3">Provenance</div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex flex-col gap-2">
              <label class="text-sm">Formation</label>
              <InputText v-model="form.material.provenance.formation" placeholder="e.g., Carrara (Italy)" />
            </div>
            <div class="flex flex-col gap-2">
              <label class="text-sm">Member</label>
              <InputText v-model="form.material.provenance.member" placeholder="e.g., Hettangian" />
            </div>
            <div class="flex flex-col gap-2">
              <label class="text-sm">Submember</label>
              <InputText v-model="form.material.provenance.submember" placeholder="Submember name" />
            </div>
            <div class="flex flex-col gap-2">
              <label class="text-sm">Source</label>
              <InputText v-model="form.material.provenance.source" placeholder="e.g., Quarry" />
            </div>
          </div>

          <!-- Location -->
          <div class="text-xs font-semibold text-surface-400 uppercase mt-4 mb-2">Location</div>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="flex flex-col gap-1">
              <label class="text-xs">Street</label>
              <InputText v-model="form.material.provenance.location.street" size="small" />
            </div>
            <div class="flex flex-col gap-1">
              <label class="text-xs">Building</label>
              <InputText v-model="form.material.provenance.location.building" size="small" />
            </div>
            <div class="flex flex-col gap-1">
              <label class="text-xs">City</label>
              <InputText v-model="form.material.provenance.location.city" size="small" />
            </div>
            <div class="flex flex-col gap-1">
              <label class="text-xs">State/Region</label>
              <InputText v-model="form.material.provenance.location.state" size="small" />
            </div>
            <div class="flex flex-col gap-1">
              <label class="text-xs">Postcode</label>
              <InputText v-model="form.material.provenance.location.postcode" size="small" />
            </div>
            <div class="flex flex-col gap-1">
              <label class="text-xs">Country</label>
              <InputText v-model="form.material.provenance.location.country" size="small" />
            </div>
            <div class="flex flex-col gap-1">
              <label class="text-xs">Latitude</label>
              <InputText v-model="form.material.provenance.location.latitude" placeholder="e.g., 44.0793" size="small" />
            </div>
            <div class="flex flex-col gap-1">
              <label class="text-xs">Longitude</label>
              <InputText v-model="form.material.provenance.location.longitude" placeholder="e.g., 10.0979" size="small" />
            </div>
          </div>

          <!-- Texture -->
          <Divider />
          <div class="text-sm font-semibold mb-3">Texture</div>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="flex flex-col gap-2">
              <label class="text-sm">Bedding</label>
              <InputText v-model="form.material.texture.bedding" placeholder="e.g., no bedding" />
            </div>
            <div class="flex flex-col gap-2">
              <label class="text-sm">Lineation</label>
              <InputText v-model="form.material.texture.lineation" placeholder="e.g., no apparent lineation" />
            </div>
            <div class="flex flex-col gap-2">
              <label class="text-sm">Foliation</label>
              <InputText v-model="form.material.texture.foliation" placeholder="e.g., no foliation" />
            </div>
            <div class="flex flex-col gap-2">
              <label class="text-sm">Fault</label>
              <InputText v-model="form.material.texture.fault" placeholder="e.g., no faults" />
            </div>
          </div>
        </AccordionContent>
      </AccordionPanel>

      <!-- Parameters -->
      <AccordionPanel value="parameters">
        <AccordionHeader>Parameters</AccordionHeader>
        <AccordionContent>
          <p class="text-sm text-surface-400 mb-4">
            Pre-experimental sample parameters (weight, porosity, density, etc.).
          </p>

          <div
            v-for="(param, idx) in form.parameters"
            :key="idx"
            class="p-4 mb-3 border border-surface-700 rounded-lg"
          >
            <div class="flex justify-between items-center mb-3">
              <span class="text-sm font-medium">Parameter {{ idx + 1 }}</span>
              <Button
                size="small"
                severity="danger"
                text
                label="Remove"
                @click="removeParameter(idx)"
              />
            </div>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
              <div class="col-span-2 md:col-span-1 flex flex-col gap-1">
                <label class="text-xs">Variable</label>
                <Select v-model="param.control" :options="SAMPLE_PARAMETER_TYPES" placeholder="Select..." showClear size="small" />
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-xs">Value</label>
                <InputText v-model="param.value" size="small" />
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-xs">Unit</label>
                <Select v-model="param.unit" :options="UNIT_TYPES" showClear size="small" />
              </div>
              <div class="flex flex-col gap-1">
                <label class="text-xs">Prefix</label>
                <Select v-model="param.prefix" :options="prefixOptions" size="small" />
              </div>
              <div class="col-span-2 md:col-span-5 flex flex-col gap-1">
                <label class="text-xs">Note (Measurement and Treatment)</label>
                <InputText v-model="param.note" placeholder="Optional note" size="small" />
              </div>
            </div>
          </div>

          <Button
            size="small"
            outlined
            icon="pi pi-plus"
            label="Add Parameter"
            @click="addParameter"
          />
        </AccordionContent>
      </AccordionPanel>

      <!-- Documents -->
      <AccordionPanel value="documents">
        <AccordionHeader>Documents</AccordionHeader>
        <AccordionContent>
          <p class="text-sm text-surface-400 mb-4">
            Upload sample images, thin section photos, or other documentation.
          </p>
          <div class="flex items-center justify-center p-8 border-2 border-dashed border-surface-600 rounded-lg">
            <span class="text-surface-500">Document upload coming soon</span>
          </div>
        </AccordionContent>
      </AccordionPanel>
    </Accordion>

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
        label="Save Sample"
        :disabled="!isValid"
      />
    </div>
  </form>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import Accordion from 'primevue/accordion'
import AccordionPanel from 'primevue/accordionpanel'
import AccordionHeader from 'primevue/accordionheader'
import AccordionContent from 'primevue/accordioncontent'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Divider from 'primevue/divider'
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
