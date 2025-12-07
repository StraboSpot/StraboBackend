<template>
  <div class="sample-view">
    <!-- Basic Information -->
    <section class="view-section">
      <h3 class="section-title">Basic Information</h3>
      <div class="info-grid">
        <InfoField label="Sample Name" :value="data.name" />
        <InfoField label="Sample ID" :value="data.id" />
        <InfoField label="IGSN" :value="data.igsn" />
        <InfoField label="Description" :value="data.description" class="col-span-2" />
      </div>

      <!-- Parent Sample -->
      <div v-if="hasParent" class="mt-4">
        <h4 class="subsection-title">Parent Sample</h4>
        <div class="info-grid">
          <InfoField label="Parent Name" :value="data.parent?.name" />
          <InfoField label="Parent ID" :value="data.parent?.id" />
          <InfoField label="Parent IGSN" :value="data.parent?.igsn" />
          <InfoField label="Parent Description" :value="data.parent?.description" />
        </div>
      </div>
    </section>

    <!-- Material -->
    <section v-if="hasMaterial" class="view-section">
      <h3 class="section-title">Material</h3>

      <!-- Material Type (nested object with type, name, state, note) -->
      <div v-if="hasMaterialType" class="info-grid mb-4">
        <InfoField label="Material Type" :value="data.material?.material?.type" />
        <InfoField :label="materialNameLabel" :value="formatMaterialName" />
        <InfoField label="State" :value="data.material?.material?.state" />
        <InfoField label="Note" :value="data.material?.material?.note" />
      </div>

      <!-- Mineralogy / Composition -->
      <div v-if="data.material?.composition?.length > 0" class="mt-4">
        <h4 class="subsection-title">Mineralogy / Composition</h4>
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>Mineral</th>
                <th>Fraction</th>
                <th>Unit</th>
                <th>Grain Size (um)</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(phase, idx) in data.material.composition" :key="idx">
                <td>{{ formatMineral(phase) }}</td>
                <td>{{ phase.fraction || '-' }}</td>
                <td>{{ phase.unit || '-' }}</td>
                <td>{{ phase.grainsize || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Provenance -->
      <div v-if="hasProvenance" class="mt-4">
        <h4 class="subsection-title">Provenance</h4>
        <div class="info-grid">
          <InfoField label="Formation" :value="data.material?.provenance?.formation" />
          <InfoField label="Member" :value="data.material?.provenance?.member" />
          <InfoField label="Submember" :value="data.material?.provenance?.submember" />
          <InfoField label="Source" :value="formatSource(data.material?.provenance)" />
        </div>

        <!-- Location (nested object within provenance) -->
        <div v-if="hasProvenanceLocation" class="mt-3">
          <h5 class="text-xs font-semibold text-strabo-text-secondary mb-2 uppercase">Location</h5>
          <div class="info-grid grid-cols-4">
            <InfoField label="Street" :value="provenanceLocation?.street" />
            <InfoField label="Building" :value="provenanceLocation?.building" />
            <InfoField label="City" :value="provenanceLocation?.city" />
            <InfoField label="State/Region" :value="provenanceLocation?.state" />
            <InfoField label="Postcode" :value="provenanceLocation?.postcode" />
            <InfoField label="Country" :value="provenanceLocation?.country" />
            <InfoField label="Latitude" :value="provenanceLocation?.latitude" />
            <InfoField label="Longitude" :value="provenanceLocation?.longitude" />
          </div>
        </div>
      </div>

      <!-- Texture -->
      <div v-if="hasTexture" class="mt-4">
        <h4 class="subsection-title">Texture</h4>
        <div class="info-grid grid-cols-4">
          <InfoField label="Bedding" :value="data.material?.texture?.bedding" />
          <InfoField label="Lineation" :value="data.material?.texture?.lineation" />
          <InfoField label="Foliation" :value="data.material?.texture?.foliation" />
          <InfoField label="Fault" :value="data.material?.texture?.fault" />
        </div>
      </div>
    </section>

    <!-- Parameters -->
    <section v-if="data.parameters?.length > 0" class="view-section">
      <h3 class="section-title">Parameters</h3>
      <div class="table-container">
        <table class="data-table">
          <thead>
            <tr>
              <th>Variable</th>
              <th>Value</th>
              <th>Unit</th>
              <th>Prefix</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(param, idx) in data.parameters" :key="idx">
              <td>{{ formatVariable(param) }}</td>
              <td>{{ param.value || '-' }}</td>
              <td>{{ param.unit || '-' }}</td>
              <td>{{ param.prefix || '-' }}</td>
              <td>{{ param.note || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Documents -->
    <section v-if="data.documents?.length > 0" class="view-section">
      <h3 class="section-title">Documents</h3>
      <div class="space-y-2">
        <div v-for="(doc, idx) in data.documents" :key="idx" class="p-3 bg-strabo-bg-tertiary rounded">
          <div class="flex justify-between items-start">
            <div>
              <span class="font-medium">{{ doc.id || doc.description || `Document ${idx + 1}` }}</span>
              <span v-if="doc.type" class="text-xs text-strabo-text-secondary ml-2">({{ doc.type }})</span>
            </div>
            <a v-if="doc.path" :href="doc.path" target="_blank" class="text-strabo-accent hover:underline text-sm">
              View
            </a>
          </div>
          <p v-if="doc.description" class="text-sm text-strabo-text-secondary mt-1">{{ doc.description }}</p>
        </div>
      </div>
    </section>

    <!-- No Data Message -->
    <div v-if="!hasAnyData" class="no-data">
      <p class="text-strabo-text-secondary">No sample data has been entered yet.</p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import InfoField from '@/components/InfoField.vue'
import { MATERIAL_NAME_LABELS, TEXT_INPUT_MATERIAL_TYPES } from '@/schemas/laps-enums'

const props = defineProps({
  data: {
    type: Object,
    default: () => ({})
  }
})

// Helper to check if a value is "Other" (case-insensitive)
const isOther = (value) => value && value.toLowerCase() === 'other'

// Get the label for material name field based on material type
const materialNameLabel = computed(() => {
  const type = props.data?.material?.material?.type
  return MATERIAL_NAME_LABELS[type] || 'Name'
})

// Format material name display - show "Other: custom value" if other is selected
const formatMaterialName = computed(() => {
  const material = props.data?.material?.material
  if (!material?.name) return null

  const type = material.type
  // For text input types, just show the value
  if (!type || TEXT_INPUT_MATERIAL_TYPES.includes(type)) {
    return material.name
  }

  // For dropdown types, check if "Other" and show custom value
  if (isOther(material.name) && material.other_name) {
    return `Other: ${material.other_name}`
  }
  return material.name
})

// Format mineral display - show "Other: custom value" if other is selected
const formatMineral = (phase) => {
  if (!phase.mineral) return '-'
  if (isOther(phase.mineral) && phase.other_mineral) {
    return `Other: ${phase.other_mineral}`
  }
  return phase.mineral
}

// Format source display - show "Other: custom value" if other is selected
const formatSource = (provenance) => {
  if (!provenance?.source) return null
  if (isOther(provenance.source) && provenance.other_source) {
    return `Other: ${provenance.other_source}`
  }
  return provenance.source
}

// Format variable display - show "Other: custom value" if other is selected
const formatVariable = (param) => {
  if (!param.control) return '-'
  if (isOther(param.control) && param.other_control) {
    return `Other: ${param.other_control}`
  }
  return param.control
}

// Helper to check if provenance.location is an object or a string
const provenanceLocation = computed(() => {
  const loc = props.data?.material?.provenance?.location
  if (typeof loc === 'object' && loc !== null) {
    return loc
  }
  return null
})

const hasParent = computed(() => {
  const p = props.data?.parent
  return p && (p.name || p.id || p.igsn || p.description)
})

const hasMaterialType = computed(() => {
  const m = props.data?.material?.material
  // Check if it's an object with properties
  if (typeof m === 'object' && m !== null) {
    return m.type || m.name || m.state || m.note
  }
  // Or just a string
  return typeof m === 'string' && m.length > 0
})

const hasMaterial = computed(() => {
  const m = props.data?.material
  return m && (hasMaterialType.value || m.composition?.length > 0 || hasProvenance.value || hasTexture.value)
})

const hasProvenance = computed(() => {
  const p = props.data?.material?.provenance
  return p && (p.formation || p.member || p.submember || p.source || p.location)
})

const hasProvenanceLocation = computed(() => {
  const loc = provenanceLocation.value
  return loc && (loc.street || loc.building || loc.city || loc.state || loc.postcode || loc.country || loc.latitude || loc.longitude)
})

const hasTexture = computed(() => {
  const t = props.data?.material?.texture
  return t && (t.bedding || t.lineation || t.foliation || t.fault)
})

const hasAnyData = computed(() => {
  return props.data?.name || props.data?.id || props.data?.igsn || props.data?.description ||
         hasMaterial.value || props.data?.parameters?.length > 0 || props.data?.documents?.length > 0
})
</script>

<style scoped>
.sample-view {
  max-width: 1100px;
  margin: 0 auto;
}

.view-section {
  margin-bottom: 1.5rem;
  padding-bottom: 1.5rem;
  border-bottom: 1px solid var(--p-surface-600);
}

.view-section:last-child {
  border-bottom: none;
  margin-bottom: 0;
}

.section-title {
  font-size: 1.125rem;
  font-weight: 600;
  color: #ffffff;
  margin-bottom: 1rem;
  text-transform: uppercase;
}

.subsection-title {
  font-size: 1rem;
  font-weight: 600;
  color: #d1d5db;
  margin-bottom: 0.75rem;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;
}

.info-grid.grid-cols-4 {
  grid-template-columns: repeat(4, 1fr);
}

.col-span-2 {
  grid-column: span 2;
}

.table-container {
  overflow-x: auto;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 1rem;
}

.data-table th,
.data-table td {
  padding: 0.625rem 0.75rem;
  text-align: left;
  border-bottom: 1px solid var(--p-surface-600);
}

.data-table th {
  background-color: var(--p-surface-700);
  font-weight: 600;
  color: #d1d5db;
}

.data-table td {
  color: #ffffff;
}

.no-data {
  text-align: center;
  padding: 2rem;
  color: var(--p-surface-400);
}

@media (max-width: 768px) {
  .info-grid {
    grid-template-columns: 1fr;
  }

  .info-grid.grid-cols-4 {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
