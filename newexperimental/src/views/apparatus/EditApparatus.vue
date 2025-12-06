<template>
  <div>
    <PageHeader
      title="Edit Apparatus"
      :back-link="`/view_apparatus?a=${a}`"
    />

    <div class="text-center py-12 text-strabo-text-secondary">
      <p class="text-lg mb-4">Apparatus form coming in Phase 2</p>
      <p>Apparatus ID: {{ a }}</p>
      <router-link :to="`/view_apparatus?a=${a}`" class="btn-secondary mt-4 inline-block">
        Back to Apparatus
      </router-link>
    </div>
  </div>
</template>

<script setup>
import PageHeader from '@/components/PageHeader.vue'

const props = defineProps({
  a: String
})
</script>
