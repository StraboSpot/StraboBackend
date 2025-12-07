<template>
  <div class="facility-apparatus-view">
    <!-- Facility Section -->
    <section v-if="hasFacility" class="view-section">
      <h3 class="section-title">Facility Information</h3>
      <div class="info-grid">
        <InfoField label="Facility Name" :value="data.facility?.name" />
        <InfoField label="Facility Type" :value="formatFacilityType" />
        <InfoField label="Institute" :value="data.facility?.institute" />
        <InfoField label="Department" :value="data.facility?.department" />
        <InfoField label="Facility ID" :value="data.facility?.id" />
        <InfoField label="Website" :value="data.facility?.website" />
        <InfoField label="Description" :value="data.facility?.description" class="col-span-2" />
      </div>

      <!-- Address -->
      <div v-if="hasFacilityAddress" class="mt-4">
        <h4 class="subsection-title">Address</h4>
        <div class="info-grid grid-cols-4">
          <InfoField label="Street" :value="data.facility?.address?.street" />
          <InfoField label="Building" :value="data.facility?.address?.building" />
          <InfoField label="City" :value="data.facility?.address?.city" />
          <InfoField label="State" :value="data.facility?.address?.state" />
          <InfoField label="Postal Code" :value="data.facility?.address?.postcode" />
          <InfoField label="Country" :value="data.facility?.address?.country" />
          <InfoField label="Latitude" :value="data.facility?.address?.latitude" />
          <InfoField label="Longitude" :value="data.facility?.address?.longitude" />
        </div>
      </div>

      <!-- Contact -->
      <div v-if="hasFacilityContact" class="mt-4">
        <h4 class="subsection-title">Contact</h4>
        <div class="info-grid grid-cols-4">
          <InfoField label="First Name" :value="data.facility?.contact?.firstname" />
          <InfoField label="Last Name" :value="data.facility?.contact?.lastname" />
          <InfoField label="Affiliation" :value="data.facility?.contact?.affiliation" />
          <InfoField label="Email" :value="data.facility?.contact?.email" />
          <InfoField label="Phone" :value="data.facility?.contact?.phone" />
          <InfoField label="Website" :value="data.facility?.contact?.website" />
          <InfoField label="ORCID ID" :value="data.facility?.contact?.id" />
        </div>
      </div>
    </section>

    <!-- Apparatus Section -->
    <section v-if="hasApparatus" class="view-section">
      <h3 class="section-title">Apparatus Information</h3>
      <div class="info-grid">
        <InfoField label="Apparatus Name" :value="data.apparatus?.name" />
        <InfoField label="Apparatus Type" :value="formatApparatusType" />
        <InfoField label="Location" :value="data.apparatus?.location" />
        <InfoField label="Apparatus ID" :value="data.apparatus?.id" />
        <InfoField label="Description" :value="data.apparatus?.description" class="col-span-2" />
      </div>

      <!-- Features -->
      <div v-if="data.apparatus?.features?.length > 0" class="mt-4">
        <h4 class="subsection-title">Features</h4>
        <div class="flex flex-wrap gap-2">
          <span
            v-for="feature in data.apparatus.features"
            :key="feature"
            class="feature-tag"
          >
            {{ feature }}
          </span>
        </div>
      </div>

      <!-- Parameters -->
      <div v-if="data.apparatus?.parameters?.length > 0" class="mt-4">
        <h4 class="subsection-title">Parameters</h4>
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Min</th>
                <th>Max</th>
                <th>Unit</th>
                <th>Prefix</th>
                <th>Note</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(param, idx) in data.apparatus.parameters" :key="idx">
                <td>{{ param.type || '-' }}</td>
                <td>{{ param.min || '-' }}</td>
                <td>{{ param.max || '-' }}</td>
                <td>{{ param.unit || '-' }}</td>
                <td>{{ param.prefix || '-' }}</td>
                <td>{{ param.note || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- No Data Message -->
    <div v-if="!hasFacility && !hasApparatus" class="no-data">
      <p class="text-strabo-text-secondary">No facility or apparatus data has been entered yet.</p>
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

// Helper to check if a value is "Other"
const isOther = (value) => value && value.toLowerCase() === 'other'
const isOtherApparatus = (value) => value && value.toLowerCase() === 'other apparatus'

// Format facility type - show "Other: custom value" if other is selected
const formatFacilityType = computed(() => {
  const type = props.data?.facility?.type
  if (!type) return null
  if (isOther(type) && props.data?.facility?.other_type) {
    return `Other: ${props.data.facility.other_type}`
  }
  return type
})

// Format apparatus type - show "Other: custom value" if other is selected
const formatApparatusType = computed(() => {
  const type = props.data?.apparatus?.type
  if (!type) return null
  if (isOtherApparatus(type) && props.data?.apparatus?.other_type) {
    return `Other: ${props.data.apparatus.other_type}`
  }
  return type
})

const hasFacility = computed(() => {
  const f = props.data?.facility
  return f && (f.name || f.type || f.institute || f.department)
})

const hasFacilityAddress = computed(() => {
  const a = props.data?.facility?.address
  return a && (a.street || a.building || a.city || a.state || a.postcode || a.country || a.latitude || a.longitude)
})

const hasFacilityContact = computed(() => {
  const c = props.data?.facility?.contact
  return c && (c.firstname || c.lastname || c.affiliation || c.email || c.phone || c.website || c.id)
})

const hasApparatus = computed(() => {
  const a = props.data?.apparatus
  return a && (a.name || a.type || a.location || a.id || a.description || a.features?.length > 0 || a.parameters?.length > 0)
})
</script>

<style scoped>
.facility-apparatus-view {
  max-width: 900px;
  margin: 0 auto;
}

.view-section {
  margin-bottom: 1.5rem;
  padding-bottom: 1.5rem;
  border-bottom: 1px solid var(--strabo-border, #4b5563);
}

.view-section:last-child {
  border-bottom: none;
  margin-bottom: 0;
}

.section-title {
  font-size: 1.125rem; /* 18px */
  font-weight: 600;
  color: #ffffff;
  margin-bottom: 1rem;
  text-transform: uppercase;
}

.subsection-title {
  font-size: 1rem; /* 16px */
  font-weight: 600;
  color: #d1d5db; /* gray-300 */
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

.feature-tag {
  display: inline-block;
  padding: 0.375rem 0.625rem;
  background-color: var(--strabo-bg-tertiary, #374151);
  border: 1px solid var(--strabo-border, #4b5563);
  border-radius: 4px;
  font-size: 0.875rem; /* 14px */
  color: #ffffff;
}

.table-container {
  overflow-x: auto;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 1rem; /* 16px */
}

.data-table th,
.data-table td {
  padding: 0.625rem 0.75rem;
  text-align: left;
  border-bottom: 1px solid var(--strabo-border, #4b5563);
}

.data-table th {
  background-color: var(--strabo-bg-tertiary, #374151);
  font-weight: 600;
  color: #d1d5db; /* gray-300 */
}

.data-table td {
  color: #ffffff; /* white for better readability */
}

.no-data {
  text-align: center;
  padding: 2rem;
  color: #9ca3af;
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
