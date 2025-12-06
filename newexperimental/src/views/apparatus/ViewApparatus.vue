<template>
  <div>
    <PageHeader
      title="View Apparatus"
      back-link="/apparatus_repository"
    />

    <div class="text-center py-12 text-strabo-text-secondary">
      <p class="text-lg mb-4">Apparatus viewer coming in Phase 2</p>
      <p>Apparatus ID: {{ a }}</p>
      <div class="flex justify-center gap-4 mt-4">
        <router-link :to="`/edit_apparatus?a=${a}`" class="btn-primary">Edit</router-link>
        <router-link to="/apparatus_repository" class="btn-secondary">Back to Repository</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import PageHeader from '@/components/PageHeader.vue'

const props = defineProps({
  a: String
})
</script>
