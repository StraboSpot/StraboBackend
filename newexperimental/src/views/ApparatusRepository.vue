<template>
  <div>
    <PageHeader
      title="Apparatus Repository"
      back-link="/"
      subtitle="Browse facilities and equipment"
    />

    <div class="flex justify-end mb-6">
      <router-link to="/add_facility" class="btn-primary">
        + Add Facility
      </router-link>
    </div>

    <div class="text-center py-12 text-strabo-text-secondary">
      <p class="text-lg mb-4">Repository browser coming in Phase 2</p>
      <p>This will display a searchable list of facilities and their apparatus.</p>
    </div>
  </div>
</template>

<script setup>
import PageHeader from '@/components/PageHeader.vue'
</script>
