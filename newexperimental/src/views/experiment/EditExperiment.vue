<template>
  <div>
    <PageHeader
      title="Edit Experiment"
      back-href="/my_experimental_data"
      subtitle="Modify experiment data"
    />

    <div class="text-center py-12 text-strabo-text-secondary">
      <p class="text-lg mb-4">Experiment form coming in Phase 4</p>
      <p>Experiment ID: {{ e }}</p>
      <a href="/my_experimental_data" class="btn-secondary mt-4 inline-block">
        Back to My Data
      </a>
    </div>
  </div>
</template>

<script setup>
import PageHeader from '@/components/PageHeader.vue'

const props = defineProps({
  e: String
})
</script>
