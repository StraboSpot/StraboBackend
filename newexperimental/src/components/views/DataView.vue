<template>
  <div class="data-view">
    <div v-if="hasData">
      <!-- Datasets -->
      <section class="view-section" v-for="(dataset, idx) in modelValue.datasets" :key="idx">
        <h3 class="section-title">
          {{ dataset.data || 'Dataset ' + (idx + 1) }}
          <span v-if="getDisplayType(dataset)" class="dataset-type-badge">{{ getDisplayType(dataset) }}</span>
        </h3>

        <!-- Basic Info -->
        <div class="info-grid">
          <InfoField label="Data ID" :value="dataset.id" />
          <InfoField label="Format" :value="dataset.format === 'Other' ? dataset.other_format : dataset.format" />
          <InfoField label="Quality" :value="dataset.rating" />
          <InfoField v-if="dataset.path" label="File" :value="getFilename(dataset.path)" />
        </div>

        <div v-if="dataset.description" class="mt-3">
          <InfoField label="Description" :value="dataset.description" />
        </div>

        <!-- Parameters (if data === 'Parameters') -->
        <div v-if="dataset.data === 'Parameters' && dataset.parameters?.length > 0" class="mt-4">
          <h4 class="subsection-title">Parameters</h4>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Data</th>
                  <th>Value</th>
                  <th>Error</th>
                  <th>Unit</th>
                  <th>Prefix</th>
                  <th>Note</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(param, pIdx) in dataset.parameters" :key="pIdx">
                  <td>{{ param.control || '-' }}</td>
                  <td>{{ param.value || '-' }}</td>
                  <td>{{ param.error || '-' }}</td>
                  <td>{{ param.unit || '-' }}</td>
                  <td>{{ param.prefix || '-' }}</td>
                  <td>{{ param.note || '-' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Time Series Headers (if data === 'Time Series') -->
        <div v-if="dataset.data === 'Time Series' && dataset.headers?.length > 0" class="mt-4">
          <h4 class="subsection-title">Data Headers</h4>
          <div v-for="(hdr, hIdx) in dataset.headers" :key="hIdx" class="nested-card">
            <div class="nested-card-title">{{ hdr.header?.header || 'Header ' + (hIdx + 1) }}</div>
            <div class="info-grid">
              <InfoField label="Specifier A" :value="hdr.header?.spec_a" />
              <InfoField label="Specifier B" :value="hdr.header?.spec_b" />
              <InfoField v-if="hdr.header?.spec_c" label="Other Specifier" :value="hdr.header.spec_c" />
              <InfoField label="Unit" :value="hdr.header?.unit" />
              <InfoField label="Type" :value="hdr.type" />
              <InfoField label="Channel #" :value="hdr.number" />
              <InfoField label="Quality" :value="hdr.rating" />
            </div>
            <div v-if="hdr.note" class="mt-2">
              <InfoField label="Notes" :value="hdr.note" />
            </div>
          </div>
        </div>

        <!-- Pore Fluid Phases (if data === 'Pore Fluid') -->
        <div v-if="dataset.data === 'Pore Fluid' && dataset.fluid?.phases?.length > 0" class="mt-4">
          <h4 class="subsection-title">Pore Fluid Phases</h4>
          <div v-for="(phase, phIdx) in dataset.fluid.phases" :key="phIdx" class="nested-card">
            <div class="nested-card-title">Phase {{ phIdx + 1 }}</div>
            <div class="info-grid">
              <InfoField label="Component" :value="phase.component" />
              <InfoField label="Fraction" :value="phase.fraction" />
              <InfoField label="Activity" :value="phase.activity" />
              <InfoField label="Fugacity" :value="phase.fugacity" />
              <InfoField label="Unit" :value="phase.unit" />
              <InfoField label="Chemistry Data" :value="phase.composition" />
            </div>

            <!-- Solutes -->
            <div v-if="phase.composition === 'Chemistry' && phase.solutes?.length > 0" class="mt-3">
              <h5 class="text-sm font-semibold text-surface-400 mb-2">Chemistry</h5>
              <div class="table-container">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Component</th>
                      <th>Value</th>
                      <th>Error</th>
                      <th>Unit</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(solute, sIdx) in phase.solutes" :key="sIdx">
                      <td>{{ solute.component || '-' }}</td>
                      <td>{{ solute.value || '-' }}</td>
                      <td>{{ solute.error || '-' }}</td>
                      <td>{{ solute.unit || '-' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
    <div v-else class="no-data">
      <p class="text-surface-400">No data information available.</p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import InfoField from '@/components/InfoField.vue'

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({})
  }
})

const hasData = computed(() => {
  return props.modelValue?.datasets && props.modelValue.datasets.length > 0
})

function getDisplayType(dataset) {
  if (dataset.type === 'Other' && dataset.other_type) {
    return dataset.other_type
  }
  return dataset.type || ''
}

function getFilename(path) {
  if (!path) return null
  // Try to extract filename from URL
  try {
    const url = new URL(path, window.location.origin)
    const filenameParam = url.searchParams.get('filename')
    if (filenameParam) return decodeURIComponent(filenameParam)
  } catch (e) {
    // Fallback
  }
  const parts = path.split('/')
  return parts[parts.length - 1] || path
}
</script>

<style scoped>
.data-view {
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
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.dataset-type-badge {
  font-size: 0.75rem;
  font-weight: 400;
  color: var(--p-surface-400);
  background: var(--p-surface-700);
  padding: 0.25rem 0.75rem;
  border-radius: 1rem;
  text-transform: none;
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

.nested-card {
  background: var(--p-surface-800);
  border: 1px solid var(--p-surface-600);
  border-radius: 0.375rem;
  padding: 1rem;
  margin-bottom: 0.75rem;
}

.nested-card:last-child {
  margin-bottom: 0;
}

.nested-card-title {
  font-size: 0.875rem;
  font-weight: 600;
  color: #dc3545;
  margin-bottom: 0.75rem;
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

.data-table tr:last-child td {
  border-bottom: none;
}

.no-data {
  text-align: center;
  padding: 2rem;
}

@media (max-width: 768px) {
  .info-grid {
    grid-template-columns: 1fr;
  }
}
</style>
