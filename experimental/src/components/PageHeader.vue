<template>
  <div class="mb-6">
    <div class="flex items-center gap-4 mb-2">
      <!-- Use showBack prop with @back event for browser history navigation -->
      <button
        v-if="showBack"
        @click="$emit('back')"
        class="text-strabo-text-secondary hover:text-strabo-accent transition-colors cursor-pointer"
      >
        &larr; Back
      </button>
      <!-- Use backLink for Vue Router navigation -->
      <router-link
        v-else-if="backLink"
        :to="backLink"
        class="text-strabo-text-secondary hover:text-strabo-accent transition-colors"
      >
        &larr; Back
      </router-link>
      <!-- Use backHref for external links -->
      <a
        v-else-if="backHref"
        :href="backHref"
        class="text-strabo-text-secondary hover:text-strabo-accent transition-colors"
      >
        &larr; Back
      </a>
    </div>
    <h1 class="text-2xl font-bold text-strabo-text-primary">{{ title }}</h1>
    <p v-if="subtitle" class="text-strabo-text-secondary mt-1">{{ subtitle }}</p>
  </div>
</template>

<script setup>
defineProps({
  title: {
    type: String,
    required: true
  },
  subtitle: {
    type: String,
    default: ''
  },
  backLink: {
    type: String,
    default: ''
  },
  backHref: {
    type: String,
    default: ''
  },
  showBack: {
    type: Boolean,
    default: false
  }
})

defineEmits(['back'])
</script>
