<template>
  <div class="py-8">
    <header class="text-center mb-12">
      <h1 class="text-3xl font-bold text-strabo-text-primary mb-2">StraboExperimental</h1>
      <p class="text-strabo-text-secondary">Laboratory Experimental Data Management</p>
    </header>

    <!-- Navigation Tiles -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 max-w-5xl mx-auto">
      <router-link to="/add_project" class="nav-tile">
        <div class="nav-tile-icon">+</div>
        <div class="nav-tile-label">New Project</div>
      </router-link>

      <a href="/my_experimental_data" class="nav-tile">
        <div class="nav-tile-icon">&#128193;</div>
        <div class="nav-tile-label">My Projects</div>
      </a>

      <router-link to="/apparatus_repository" class="nav-tile">
        <div class="nav-tile-icon">&#128269;</div>
        <div class="nav-tile-label">Search Database</div>
      </router-link>

      <router-link to="/apparatus_repository" class="nav-tile">
        <div class="nav-tile-icon">&#9881;</div>
        <div class="nav-tile-label">Apparatus Repository</div>
      </router-link>
    </div>
  </div>
</template>

<script setup>
// HomePage logic
</script>
