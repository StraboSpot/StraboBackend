<template>
  <div class="experiment-view">
    <!-- Basic Information -->
    <section class="view-section">
      <h3 class="section-title">Experiment Information</h3>
      <div class="info-grid">
        <InfoField label="Title" :value="data.title" class="col-span-2" />
        <InfoField label="Project" :value="data.project" />
        <InfoField label="Experiment ID" :value="data.id" />
        <InfoField label="IEDA ID" :value="data.ieda" />
        <InfoField label="Start Date/Time" :value="formatDate(data.start_date)" />
        <InfoField label="End Date/Time" :value="formatDate(data.end_date)" />
      </div>
      <div v-if="data.description" class="mt-4">
        <InfoField label="Description" :value="data.description" />
      </div>
    </section>

    <!-- Test Features -->
    <section v-if="data.features?.length > 0" class="view-section">
      <h3 class="section-title">Test Features</h3>
      <div class="features-display">
        <span
          v-for="feature in data.features"
          :key="feature"
          class="feature-tag"
        >
          {{ feature }}
        </span>
      </div>
    </section>

    <!-- Author -->
    <section v-if="hasAuthor" class="view-section">
      <h3 class="section-title">Author</h3>
      <div class="info-grid grid-cols-4">
        <InfoField label="First Name" :value="data.author?.firstname" />
        <InfoField label="Last Name" :value="data.author?.lastname" />
        <InfoField label="Affiliation" :value="data.author?.affiliation" />
        <InfoField label="Email" :value="data.author?.email" />
        <InfoField label="Phone" :value="data.author?.phone" />
        <InfoField label="Website" :value="data.author?.website" />
        <InfoField label="ORCID ID" :value="data.author?.id" />
      </div>
    </section>

    <!-- Geometry -->
    <section v-if="data.geometry?.length > 0" class="view-section">
      <h3 class="section-title">Geometry</h3>
      <div class="space-y-4">
        <div
          v-for="(geo, geoIdx) in data.geometry"
          :key="geoIdx"
          class="geometry-card"
        >
          <div class="geometry-header">
            {{ geo.type || 'Component' }} #{{ geo.order || geoIdx + 1 }}
          </div>
          <div class="geometry-body">
            <div class="info-grid grid-cols-4">
              <InfoField label="Type" :value="geo.type" />
              <InfoField label="Geometry" :value="geo.geometry" />
              <InfoField label="Material" :value="geo.material" />
            </div>

            <!-- Dimensions Table -->
            <div v-if="geo.dimensions?.length > 0" class="mt-3">
              <h5 class="text-xs font-semibold text-surface-400 mb-2 uppercase">Dimensions</h5>
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
                    <tr v-for="(dim, dimIdx) in geo.dimensions" :key="dimIdx">
                      <td>{{ dim.variable || '-' }}</td>
                      <td>{{ dim.value || '-' }}</td>
                      <td>{{ dim.unit || '-' }}</td>
                      <td>{{ dim.prefix || '-' }}</td>
                      <td>{{ dim.note || '-' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Documents -->
    <section v-if="data.documents?.length > 0" class="view-section">
      <h3 class="section-title">Documents</h3>
      <div class="space-y-2">
        <div v-for="(doc, idx) in data.documents" :key="idx" class="p-3 bg-surface-700 rounded">
          <div class="flex justify-between items-start">
            <div>
              <span class="font-medium">{{ doc.id || doc.description || `Document ${idx + 1}` }}</span>
              <span v-if="doc.type" class="text-xs text-surface-400 ml-2">({{ doc.type }})</span>
            </div>
            <a v-if="doc.path" :href="doc.path" target="_blank" class="text-red-400 hover:underline text-sm">
              View
            </a>
          </div>
          <p v-if="doc.description" class="text-sm text-surface-400 mt-1">{{ doc.description }}</p>
        </div>
      </div>
    </section>

    <!-- No Data Message -->
    <div v-if="!hasAnyData" class="no-data">
      <p class="text-surface-400">No experimental setup data has been entered yet.</p>
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

function formatDate(dateStr) {
  if (!dateStr) return null
  try {
    const date = new Date(dateStr)
    return date.toLocaleString('en-US', {
      month: '2-digit',
      day: '2-digit',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true
    })
  } catch {
    return dateStr
  }
}

const hasAuthor = computed(() => {
  const a = props.data?.author
  return a && (a.firstname || a.lastname || a.affiliation || a.email || a.phone || a.website || a.id)
})

const hasAnyData = computed(() => {
  return props.data?.title ||
         props.data?.project ||
         props.data?.id ||
         props.data?.ieda ||
         props.data?.start_date ||
         props.data?.end_date ||
         props.data?.description ||
         props.data?.features?.length > 0 ||
         hasAuthor.value ||
         props.data?.geometry?.length > 0 ||
         props.data?.documents?.length > 0
})
</script>

<style scoped>
.experiment-view {
  max-width: 900px;
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

.features-display {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.feature-tag {
  display: inline-block;
  padding: 6px 12px;
  background-color: #dc3545;
  color: #ffffff;
  border-radius: 9999px;
  font-size: 0.875rem;
}

.geometry-card {
  border: 1px solid var(--p-surface-600);
  border-radius: 4px;
  overflow: hidden;
}

.geometry-header {
  background-color: var(--p-surface-700);
  padding: 0.75rem 1rem;
  font-weight: 600;
  font-size: 0.875rem;
  text-transform: uppercase;
}

.geometry-body {
  padding: 1rem;
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
