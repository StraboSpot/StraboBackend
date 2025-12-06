<template>
  <div>
    <PageHeader
      title="New Experiment"
      :back-link="`/view_project?ppk=${ppk}`"
      subtitle="Create a new LAPS-compliant experiment"
    />

    <div class="text-center py-12 text-strabo-text-secondary">
      <p class="text-lg mb-4">Experiment form coming in Phase 4</p>
      <p>Parent Project ID: {{ ppk }}</p>
      <router-link :to="`/view_project?ppk=${ppk}`" class="btn-secondary mt-4 inline-block">
        Back to Project
      </router-link>
    </div>
  </div>
</template>

<script setup>
import PageHeader from '@/components/PageHeader.vue'

const props = defineProps({
  ppk: String
})
</script>
