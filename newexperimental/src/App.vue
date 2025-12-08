<template>
  <div class="min-h-screen bg-strabo-bg-primary dark-mode">
    <!-- Global Header -->
    <header class="app-header">
      <router-link to="/" class="header-link">
        <h1 class="site-title">STRABOEXPERIMENTAL</h1>
      </router-link>
      <div class="title-divider"></div>
    </header>

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

<style scoped>
.app-header {
  text-align: center;
  padding: 2rem 1rem 1.5rem;
}

.header-link {
  text-decoration: none;
}

.site-title {
  font-family: 'Raleway', sans-serif;
  font-size: 2rem;
  font-weight: 400;
  letter-spacing: 0.15em;
  color: #ffffff;
  text-transform: uppercase;
  margin-bottom: 1rem;
  transition: color 0.2s ease;
}

.header-link:hover .site-title {
  color: #e0e0e0;
}

.title-divider {
  width: 520px;
  max-width: 90%;
  height: 2px;
  background-color: rgb(220, 53, 69);
  margin: 0 auto;
}
</style>
