<template>
  <div class="sample-view">
    <!-- Basic Information -->
    <section class="view-section">
      <h3 class="section-title">Basic Information</h3>
      <div class="info-grid">
        <InfoField label="Sample Name" :value="data.name" />
        <InfoField label="Sample ID" :value="data.id" />
        <InfoField label="Description" :value="data.description" class="col-span-2" />
      </div>
    </section>

    <!-- Material -->
    <section v-if="hasMaterial" class="view-section">
      <h3 class="section-title">Material</h3>
      <div class="info-grid">
        <InfoField label="Material Type" :value="data.material?.material" />
        <InfoField label="Lithology" :value="data.material?.lithology" />
      </div>

      <!-- Mineralogy -->
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
                <td>{{ phase.mineral || '-' }}</td>
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
          <InfoField label="Location" :value="data.material?.provenance?.location" />
          <InfoField label="Latitude" :value="data.material?.provenance?.latitude" />
          <InfoField label="Longitude" :value="data.material?.provenance?.longitude" />
          <InfoField label="Notes" :value="data.material?.provenance?.note" />
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
              <td>{{ param.control || '-' }}</td>
              <td>{{ param.value || '-' }}</td>
              <td>{{ param.unit || '-' }}</td>
              <td>{{ param.prefix || '-' }}</td>
              <td>{{ param.note || '-' }}</td>
            </tr>
          </tbody>
        </table>
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

const props = defineProps({
  data: {
    type: Object,
    default: () => ({})
  }
})

const hasMaterial = computed(() => {
  const m = props.data?.material
  return m && (m.material || m.lithology || m.composition?.length > 0 || hasProvenance.value || hasTexture.value)
})

const hasProvenance = computed(() => {
  const p = props.data?.material?.provenance
  return p && (p.location || p.latitude || p.longitude || p.note)
})

const hasTexture = computed(() => {
  const t = props.data?.material?.texture
  return t && (t.bedding || t.lineation || t.foliation || t.fault)
})

const hasAnyData = computed(() => {
  return props.data?.name || props.data?.id || props.data?.description ||
         hasMaterial.value || props.data?.parameters?.length > 0
})
</script>

<style scoped>
.sample-view {
  max-width: 900px;
  margin: 0 auto;
}

.view-section {
  margin-bottom: 1.5rem;
  padding-bottom: 1.5rem;
  border-bottom: 1px solid var(--strabo-border);
}

.view-section:last-child {
  border-bottom: none;
  margin-bottom: 0;
}

.section-title {
  font-size: 1rem;
  font-weight: 600;
  color: var(--strabo-text-primary);
  margin-bottom: 1rem;
  text-transform: uppercase;
}

.subsection-title {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--strabo-text-secondary);
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
  font-size: 0.875rem;
}

.data-table th,
.data-table td {
  padding: 0.5rem 0.75rem;
  text-align: left;
  border-bottom: 1px solid var(--strabo-border);
}

.data-table th {
  background-color: var(--strabo-bg-tertiary);
  font-weight: 600;
  color: var(--strabo-text-secondary);
}

.data-table td {
  color: var(--strabo-text-primary);
}

.no-data {
  text-align: center;
  padding: 2rem;
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
