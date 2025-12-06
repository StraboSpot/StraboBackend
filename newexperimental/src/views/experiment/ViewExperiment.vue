<template>
  <div>
    <PageHeader
      title="View Experiment"
      back-href="/my_experimental_data"
    />

    <div class="text-center py-12 text-strabo-text-secondary">
      <p class="text-lg mb-4">Experiment viewer coming in Phase 4</p>
      <p>Experiment ID: {{ e }}</p>
      <div class="flex justify-center gap-4 mt-4">
        <router-link :to="`/edit_experiment?e=${e}`" class="btn-primary">Edit</router-link>
        <a href="/my_experimental_data" class="btn-secondary">Back to My Data</a>
      </div>
    </div>
  </div>
</template>

<script setup>
import PageHeader from '@/components/PageHeader.vue'

const props = defineProps({
  e: String
})
</script>
