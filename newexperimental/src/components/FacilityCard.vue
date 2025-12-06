<template>
  <div class="card">
    <!-- Header (always visible) -->
    <div
      class="flex items-center justify-between cursor-pointer"
      @click="$emit('toggle')"
    >
      <div class="flex-1">
        <h3 class="text-lg font-semibold text-strabo-text-primary">
          {{ facility.name }}
        </h3>
        <p class="text-sm text-strabo-text-secondary">
          {{ facility.institute }}
          <span v-if="facility.department"> &mdash; {{ facility.department }}</span>
        </p>
      </div>

      <div class="flex items-center gap-4">
        <span class="text-sm text-strabo-text-secondary">
          {{ facility.apparatuses?.length || 0 }} apparatus{{ facility.apparatuses?.length !== 1 ? 'es' : '' }}
        </span>
        <span class="text-strabo-accent text-xl">
          {{ expanded ? '▼' : '▶' }}
        </span>
      </div>
    </div>

    <!-- Expanded Content -->
    <div v-if="expanded" class="mt-4 pt-4 border-t border-strabo-border">
      <!-- Facility Details -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div v-if="facility.address">
          <span class="text-xs text-strabo-text-secondary uppercase">Location</span>
          <p class="text-strabo-text-primary">
            {{ formatAddress(facility.address) }}
          </p>
        </div>
        <div v-if="facility.contact">
          <span class="text-xs text-strabo-text-secondary uppercase">Contact</span>
          <p class="text-strabo-text-primary">
            {{ facility.contact.firstname }} {{ facility.contact.lastname }}
            <a v-if="facility.contact.email" :href="`mailto:${facility.contact.email}`" class="text-strabo-accent ml-2">
              {{ facility.contact.email }}
            </a>
          </p>
        </div>
        <div v-if="facility.website">
          <span class="text-xs text-strabo-text-secondary uppercase">Website</span>
          <p>
            <a :href="facility.website" target="_blank" class="text-strabo-accent hover:underline">
              {{ facility.website }}
            </a>
          </p>
        </div>
        <div v-if="facility.description">
          <span class="text-xs text-strabo-text-secondary uppercase">Description</span>
          <p class="text-strabo-text-primary">{{ facility.description }}</p>
        </div>
      </div>

      <!-- Facility Actions -->
      <div class="flex gap-2 mb-4">
        <router-link :to="`/view_facility?f=${facility.id}`" class="btn-secondary text-sm">
          View Details
        </router-link>
        <router-link v-if="facility.can_edit" :to="`/edit_facility?f=${facility.id}`" class="btn-secondary text-sm">
          Edit
        </router-link>
        <router-link v-if="facility.can_add_apparatus" :to="`/add_apparatus?f=${facility.id}`" class="btn-primary text-sm">
          + Add Apparatus
        </router-link>
      </div>

      <!-- Apparatus List -->
      <div v-if="facility.apparatuses?.length > 0">
        <h4 class="text-sm font-medium text-strabo-text-secondary uppercase mb-2">
          Apparatus
        </h4>
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Features</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="app in facility.apparatuses" :key="app.id">
              <td>{{ app.name }}</td>
              <td>{{ app.type }}</td>
              <td>
                <span v-if="app.features?.length" class="text-sm">
                  {{ app.features.slice(0, 3).join(', ') }}
                  <span v-if="app.features.length > 3" class="text-strabo-text-secondary">
                    +{{ app.features.length - 3 }} more
                  </span>
                </span>
                <span v-else class="text-strabo-text-secondary">—</span>
              </td>
              <td>
                <div class="flex gap-2">
                  <router-link
                    :to="`/view_apparatus?a=${app.id}`"
                    class="text-strabo-accent hover:underline text-sm"
                  >
                    View
                  </router-link>
                  <router-link
                    v-if="app.can_edit"
                    :to="`/edit_apparatus?a=${app.id}`"
                    class="text-strabo-accent hover:underline text-sm"
                  >
                    Edit
                  </router-link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else class="text-center py-4 text-strabo-text-secondary">
        No apparatus registered for this facility.
        <router-link v-if="facility.can_add_apparatus" :to="`/add_apparatus?f=${facility.id}`" class="text-strabo-accent hover:underline ml-1">
          Add one now
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  facility: {
    type: Object,
    required: true
  },
  expanded: {
    type: Boolean,
    default: false
  }
})

defineEmits(['toggle'])

function formatAddress(address) {
  const parts = []
  if (address.building) parts.push(address.building)
  if (address.street) parts.push(address.street)
  if (address.city) parts.push(address.city)
  if (address.state) parts.push(address.state)
  if (address.country) parts.push(address.country)
  return parts.join(', ') || 'No address provided'
}
</script>
