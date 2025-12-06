<template>
  <div>
    <PageHeader
      title="Apparatus Repository"
      back-link="/"
      subtitle="Browse facilities and equipment"
    />

    <div class="flex justify-between items-center mb-6">
      <div class="flex-1 max-w-md">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Search facilities or apparatus..."
          class="form-input"
        />
      </div>
      <router-link to="/add_facility" class="btn-primary ml-4">
        + Add Facility
      </router-link>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="text-center py-12">
      <p class="text-strabo-text-secondary">Loading repository...</p>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="alert-error">
      {{ error }}
    </div>

    <!-- Empty State -->
    <div v-else-if="filteredFacilities.length === 0" class="text-center py-12">
      <p class="text-strabo-text-secondary text-lg mb-4">
        {{ searchQuery ? 'No facilities match your search.' : 'No facilities found in the repository.' }}
      </p>
      <router-link to="/add_facility" class="btn-primary">
        Add First Facility
      </router-link>
    </div>

    <!-- Facilities List -->
    <div v-else class="space-y-4">
      <FacilityCard
        v-for="facility in filteredFacilities"
        :key="facility.id"
        :facility="facility"
        :expanded="expandedFacility === facility.id"
        @toggle="toggleFacility(facility.id)"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import PageHeader from '@/components/PageHeader.vue'
import FacilityCard from '@/components/FacilityCard.vue'
import { facilityService } from '@/services/api'

const loading = ref(true)
const error = ref(null)
const facilities = ref([])
const searchQuery = ref('')
const expandedFacility = ref(null)

// Filter facilities based on search
const filteredFacilities = computed(() => {
  if (!searchQuery.value.trim()) {
    return facilities.value
  }

  const query = searchQuery.value.toLowerCase()
  return facilities.value.filter(facility => {
    // Search in facility name, institute, department
    const facilityMatch =
      facility.name?.toLowerCase().includes(query) ||
      facility.institute?.toLowerCase().includes(query) ||
      facility.department?.toLowerCase().includes(query)

    // Search in apparatus names and types
    const apparatusMatch = facility.apparatuses?.some(app =>
      app.name?.toLowerCase().includes(query) ||
      app.type?.toLowerCase().includes(query)
    )

    return facilityMatch || apparatusMatch
  })
})

function toggleFacility(id) {
  expandedFacility.value = expandedFacility.value === id ? null : id
}

onMounted(async () => {
  try {
    const data = await facilityService.listWithApparatuses()
    facilities.value = data.facilities || []
  } catch (err) {
    console.error('Failed to load facilities:', err)
    error.value = 'Failed to load repository. Please try again.'
  } finally {
    loading.value = false
  }
})
</script>
