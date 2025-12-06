<template>
  <div>
    <PageHeader
      title="New Facility"
      back-link="/apparatus_repository"
      subtitle="Add a new research facility"
    />

    <div class="text-center py-12 text-strabo-text-secondary">
      <p class="text-lg mb-4">Facility form coming in Phase 2</p>
      <router-link to="/apparatus_repository" class="btn-secondary mt-4 inline-block">
        Back to Repository
      </router-link>
    </div>
  </div>
</template>

<script setup>
import PageHeader from '@/components/PageHeader.vue'
</script>
