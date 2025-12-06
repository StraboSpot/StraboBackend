<template>
  <div class="min-h-screen bg-strabo-bg-primary">
    <main class="container mx-auto px-4 py-6">
      <router-view v-slot="{ Component }">
        <KeepAlive include="ApparatusRepository">
          <component :is="Component" />
        </KeepAlive>
      </router-view>
    </main>
  </div>
</template>

<script setup>
// App-level setup can go here
</script>
