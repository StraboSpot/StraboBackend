<template>
  <div class="home-page">
    <!-- Header -->
    <header class="text-center mb-10">
      <h1 class="site-title">STRABOEXPERIMENTAL</h1>
      <div class="title-divider"></div>
    </header>

    <!-- Navigation Tiles - 2x2 grid like legacy app -->
    <div class="tiles-container">
      <div class="tile-row">
        <!-- Start New Project -->
        <router-link to="/add_project" class="nav-tile">
          <div class="tile-content">
            <div class="icon-wrapper">
              <FlaskConical class="tile-icon main-icon" :stroke-width="1.2" />
              <Plus class="tile-icon accent-icon" :stroke-width="2" />
            </div>
            <span class="tile-label">Start New Project</span>
          </div>
        </router-link>

        <!-- Continue Project (My Projects) -->
        <a href="/my_experimental_data" class="nav-tile">
          <div class="tile-content">
            <div class="icon-wrapper">
              <LineChart class="tile-icon main-icon" :stroke-width="1.2" />
              <TrendingUp class="tile-icon secondary-icon" :stroke-width="1.2" />
            </div>
            <span class="tile-label">Continue Project</span>
          </div>
        </a>
      </div>

      <div class="tile-row">
        <!-- Search Database -->
        <a href="/fullsearch/" class="nav-tile">
          <div class="tile-content">
            <div class="icon-wrapper">
              <Globe class="tile-icon main-icon" :stroke-width="1.2" />
              <Search class="tile-icon accent-icon" :stroke-width="2" />
            </div>
            <span class="tile-label">Search Database</span>
          </div>
        </a>

        <!-- Apparatus Repository -->
        <router-link to="/apparatus_repository" class="nav-tile">
          <div class="tile-content">
            <div class="icon-wrapper">
              <Building2 class="tile-icon main-icon" :stroke-width="1.2" />
              <Wrench class="tile-icon secondary-icon" :stroke-width="1.2" />
            </div>
            <span class="tile-label">Apparatus Repository</span>
          </div>
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import {
  FlaskConical,
  Plus,
  LineChart,
  TrendingUp,
  Globe,
  Search,
  Building2,
  Wrench
} from 'lucide-vue-next'
</script>

<style scoped>
.home-page {
  padding: 2rem 1rem;
  min-height: calc(100vh - 200px);
}

.site-title {
  font-family: 'Raleway', sans-serif;
  font-size: 2rem;
  font-weight: 400;
  letter-spacing: 0.15em;
  color: #ffffff;
  text-transform: uppercase;
  margin-bottom: 1rem;
}

.title-divider {
  width: 420px;
  height: 2px;
  background-color: rgb(220, 53, 69);
  margin: 0 auto;
}

.tiles-container {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 520px;
  margin: 0 auto;
}

.tile-row {
  display: flex;
  justify-content: center;
  gap: 1rem;
}

.nav-tile {
  display: block;
  width: 250px;
  height: 250px;
  border: 2px solid #444;
  border-radius: 12px;
  background: linear-gradient(145deg, #2a2a2a 0%, #1f1f1f 100%);
  text-decoration: none;
  transition: all 0.25s ease;
  overflow: hidden;
}

.nav-tile:hover {
  transform: translateY(-4px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
  border-color: #f4511e;
  background: linear-gradient(145deg, #333 0%, #252525 100%);
}

.tile-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  padding: 1.5rem;
}

.icon-wrapper {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.25rem;
  margin-bottom: 1.25rem;
  position: relative;
}

.tile-icon {
  color: #e0e0e0;
  transition: color 0.25s ease;
}

.main-icon {
  width: 72px;
  height: 72px;
}

.secondary-icon {
  width: 48px;
  height: 48px;
  opacity: 0.7;
}

.accent-icon {
  width: 32px;
  height: 32px;
  position: absolute;
  bottom: -4px;
  right: -16px;
  color: #4caf50;
}

.nav-tile:hover .tile-icon {
  color: #ffffff;
}

.nav-tile:hover .secondary-icon {
  opacity: 1;
}

.nav-tile:hover .accent-icon {
  color: #66bb6a;
}

.tile-label {
  font-family: 'Roboto', sans-serif;
  font-size: 1.1rem;
  font-weight: 500;
  color: #b0b0b0;
  text-align: center;
  line-height: 1.3;
  transition: color 0.25s ease;
}

.nav-tile:hover .tile-label {
  color: #ffffff;
}

/* Responsive adjustments */
@media (max-width: 600px) {
  .tile-row {
    flex-direction: column;
    align-items: center;
  }

  .nav-tile {
    width: 220px;
    height: 220px;
  }

  .main-icon {
    width: 56px;
    height: 56px;
  }

  .secondary-icon {
    width: 40px;
    height: 40px;
  }

  .tile-label {
    font-size: 1rem;
  }
}
</style>
