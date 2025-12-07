<template>
  <div class="data-view">
    <div v-if="hasData">
      <!-- Datasets Summary -->
      <div class="section">
        <div class="section-title">Datasets</div>
        <div v-for="(dataset, idx) in modelValue.datasets" :key="idx" class="dataset-card">
          <div class="dataset-header">
            <span class="dataset-name">{{ dataset.data || 'Dataset ' + (idx + 1) }}</span>
            <span class="dataset-type">{{ getDisplayType(dataset) }}</span>
          </div>

          <!-- Basic Info -->
          <div class="info-grid">
            <div class="info-item" v-if="dataset.id">
              <span class="info-label">Data ID</span>
              <span class="info-value">{{ dataset.id }}</span>
            </div>
            <div class="info-item" v-if="dataset.format">
              <span class="info-label">Format</span>
              <span class="info-value">{{ dataset.format === 'Other' ? dataset.other_format : dataset.format }}</span>
            </div>
            <div class="info-item" v-if="dataset.rating">
              <span class="info-label">Quality</span>
              <span class="info-value">{{ dataset.rating }}</span>
            </div>
            <div class="info-item" v-if="dataset.path">
              <span class="info-label">File</span>
              <span class="info-value">{{ dataset.path }}</span>
            </div>
          </div>

          <div class="info-item full-width" v-if="dataset.description">
            <span class="info-label">Description</span>
            <span class="info-value description">{{ dataset.description }}</span>
          </div>

          <!-- Parameters (if data === 'Parameters') -->
          <div v-if="dataset.data === 'Parameters' && dataset.parameters && dataset.parameters.length > 0" class="subsection">
            <div class="subsection-title">Parameters</div>
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
                  <td>{{ param.control }}</td>
                  <td>{{ param.value }}</td>
                  <td>{{ param.error }}</td>
                  <td>{{ param.unit }}</td>
                  <td>{{ param.prefix }}</td>
                  <td>{{ param.note }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Time Series Headers (if data === 'Time Series') -->
          <div v-if="dataset.data === 'Time Series' && dataset.headers && dataset.headers.length > 0" class="subsection">
            <div class="subsection-title">Data Headers</div>
            <div v-for="(hdr, hIdx) in dataset.headers" :key="hIdx" class="header-card">
              <div class="header-title">{{ hdr.header?.header || 'Header ' + (hIdx + 1) }}</div>
              <div class="info-grid">
                <div class="info-item" v-if="hdr.header?.spec_a">
                  <span class="info-label">Specifier A</span>
                  <span class="info-value">{{ hdr.header.spec_a }}</span>
                </div>
                <div class="info-item" v-if="hdr.header?.spec_b">
                  <span class="info-label">Specifier B</span>
                  <span class="info-value">{{ hdr.header.spec_b }}</span>
                </div>
                <div class="info-item" v-if="hdr.header?.spec_c">
                  <span class="info-label">Other Specifier</span>
                  <span class="info-value">{{ hdr.header.spec_c }}</span>
                </div>
                <div class="info-item" v-if="hdr.header?.unit">
                  <span class="info-label">Unit</span>
                  <span class="info-value">{{ hdr.header.unit }}</span>
                </div>
                <div class="info-item" v-if="hdr.type">
                  <span class="info-label">Type</span>
                  <span class="info-value">{{ hdr.type }}</span>
                </div>
                <div class="info-item" v-if="hdr.number">
                  <span class="info-label">Channel #</span>
                  <span class="info-value">{{ hdr.number }}</span>
                </div>
                <div class="info-item" v-if="hdr.rating">
                  <span class="info-label">Quality</span>
                  <span class="info-value">{{ hdr.rating }}</span>
                </div>
              </div>
              <div class="info-item full-width" v-if="hdr.note">
                <span class="info-label">Notes</span>
                <span class="info-value">{{ hdr.note }}</span>
              </div>
            </div>
          </div>

          <!-- Pore Fluid Phases (if data === 'Pore Fluid') -->
          <div v-if="dataset.data === 'Pore Fluid' && dataset.fluid?.phases && dataset.fluid.phases.length > 0" class="subsection">
            <div class="subsection-title">Pore Fluid Phases</div>
            <div v-for="(phase, phIdx) in dataset.fluid.phases" :key="phIdx" class="phase-card">
              <div class="phase-title">Phase {{ phIdx + 1 }}</div>
              <div class="info-grid">
                <div class="info-item" v-if="phase.component">
                  <span class="info-label">Component</span>
                  <span class="info-value">{{ phase.component }}</span>
                </div>
                <div class="info-item" v-if="phase.fraction">
                  <span class="info-label">Fraction</span>
                  <span class="info-value">{{ phase.fraction }}</span>
                </div>
                <div class="info-item" v-if="phase.activity">
                  <span class="info-label">Activity</span>
                  <span class="info-value">{{ phase.activity }}</span>
                </div>
                <div class="info-item" v-if="phase.fugacity">
                  <span class="info-label">Fugacity</span>
                  <span class="info-value">{{ phase.fugacity }}</span>
                </div>
                <div class="info-item" v-if="phase.unit">
                  <span class="info-label">Unit</span>
                  <span class="info-value">{{ phase.unit }}</span>
                </div>
                <div class="info-item" v-if="phase.composition">
                  <span class="info-label">Chemistry Data</span>
                  <span class="info-value">{{ phase.composition }}</span>
                </div>
              </div>

              <!-- Solutes -->
              <div v-if="phase.composition === 'Chemistry' && phase.solutes && phase.solutes.length > 0" class="solutes-section">
                <div class="solutes-title">Chemistry</div>
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
                      <td>{{ solute.component }}</td>
                      <td>{{ solute.value }}</td>
                      <td>{{ solute.error }}</td>
                      <td>{{ solute.unit }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div v-else class="empty-message">
      No data information available.
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

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
</script>

<style scoped>
.data-view {
  color: #fff;
}

.section {
  margin-bottom: 20px;
}

.section-title {
  font-size: 14px;
  font-weight: 600;
  color: #aaa;
  text-transform: uppercase;
  margin-bottom: 10px;
  padding-bottom: 5px;
  border-bottom: 1px solid #444;
}

.dataset-card {
  background: #333;
  border: 1px solid #555;
  border-radius: 6px;
  padding: 15px;
  margin-bottom: 15px;
}

.dataset-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  padding-bottom: 10px;
  border-bottom: 1px solid #444;
}

.dataset-name {
  font-size: 16px;
  font-weight: 600;
  color: #fff;
}

.dataset-type {
  font-size: 13px;
  color: #888;
  background: #444;
  padding: 3px 10px;
  border-radius: 12px;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 12px;
  margin-bottom: 10px;
}

.info-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.info-item.full-width {
  grid-column: 1 / -1;
}

.info-label {
  font-size: 12px;
  color: #888;
}

.info-value {
  font-size: 14px;
  color: #fff;
}

.info-value.description {
  white-space: pre-wrap;
  line-height: 1.4;
}

.subsection {
  margin-top: 15px;
  padding-top: 12px;
  border-top: 1px solid #444;
}

.subsection-title {
  font-size: 13px;
  font-weight: 600;
  color: #aaa;
  margin-bottom: 10px;
}

.header-card,
.phase-card {
  background: #3a3a3a;
  border: 1px solid #555;
  border-radius: 4px;
  padding: 12px;
  margin-bottom: 10px;
}

.header-title,
.phase-title {
  font-size: 14px;
  font-weight: 500;
  color: #dc3545;
  margin-bottom: 10px;
}

.solutes-section {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid #555;
}

.solutes-title {
  font-size: 12px;
  font-weight: 500;
  color: #aaa;
  margin-bottom: 8px;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.data-table th {
  text-align: left;
  color: #888;
  font-weight: 500;
  padding: 6px 8px;
  border-bottom: 1px solid #555;
}

.data-table td {
  padding: 6px 8px;
  color: #fff;
  border-bottom: 1px solid #444;
}

.data-table tr:last-child td {
  border-bottom: none;
}

.empty-message {
  color: #888;
  font-style: italic;
  text-align: center;
  padding: 30px;
}
</style>
