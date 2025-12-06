<template>
  <div>
    <PageHeader
      title="View Facility"
      back-link="/apparatus_repository"
    />

    <div class="text-center py-12 text-strabo-text-secondary">
      <p class="text-lg mb-4">Facility viewer coming in Phase 2</p>
      <p>Facility ID: {{ f }}</p>
      <div class="flex justify-center gap-4 mt-4">
        <router-link :to="`/edit_facility?f=${f}`" class="btn-primary">Edit</router-link>
        <router-link :to="`/add_apparatus?f=${f}`" class="btn-secondary">Add Apparatus</router-link>
        <router-link to="/apparatus_repository" class="btn-secondary">Back to Repository</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import PageHeader from '@/components/PageHeader.vue'

const props = defineProps({
  f: String
})
</script>
