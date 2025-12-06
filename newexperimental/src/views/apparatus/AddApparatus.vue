<template>
  <div>
    <PageHeader
      title="New Apparatus"
      :back-link="`/view_facility?f=${f}`"
      subtitle="Add new equipment to facility"
    />

    <div class="text-center py-12 text-strabo-text-secondary">
      <p class="text-lg mb-4">Apparatus form coming in Phase 2</p>
      <p>Parent Facility ID: {{ f }}</p>
      <router-link :to="`/view_facility?f=${f}`" class="btn-secondary mt-4 inline-block">
        Back to Facility
      </router-link>
    </div>
  </div>
</template>

<script setup>
import PageHeader from '@/components/PageHeader.vue'

const props = defineProps({
  f: String
})
</script>
