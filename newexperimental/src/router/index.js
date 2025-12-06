import { createRouter, createWebHistory } from 'vue-router'

// Views - lazy loaded
const HomePage = () => import('@/views/HomePage.vue')
const AddProject = () => import('@/views/project/AddProject.vue')
const EditProject = () => import('@/views/project/EditProject.vue')
const ViewProject = () => import('@/views/project/ViewProject.vue')
const DeleteProject = () => import('@/views/project/DeleteProject.vue')
const AddExperiment = () => import('@/views/experiment/AddExperiment.vue')
const EditExperiment = () => import('@/views/experiment/EditExperiment.vue')
const ViewExperiment = () => import('@/views/experiment/ViewExperiment.vue')
const DeleteExperiment = () => import('@/views/experiment/DeleteExperiment.vue')
const AddFacility = () => import('@/views/facility/AddFacility.vue')
const EditFacility = () => import('@/views/facility/EditFacility.vue')
const ViewFacility = () => import('@/views/facility/ViewFacility.vue')
const AddApparatus = () => import('@/views/apparatus/AddApparatus.vue')
const EditApparatus = () => import('@/views/apparatus/EditApparatus.vue')
const ViewApparatus = () => import('@/views/apparatus/ViewApparatus.vue')
const ApparatusRepository = () => import('@/views/ApparatusRepository.vue')

const routes = [
  // Homepage
  {
    path: '/',
    name: 'home',
    component: HomePage
  },

  // Project routes - uses ?ppk= parameter
  {
    path: '/add_project',
    name: 'add-project',
    component: AddProject
  },
  {
    path: '/edit_project',
    name: 'edit-project',
    component: EditProject,
    props: route => ({ ppk: route.query.ppk })
  },
  {
    path: '/view_project',
    name: 'view-project',
    component: ViewProject,
    props: route => ({ ppk: route.query.ppk })
  },
  {
    path: '/delete_project',
    name: 'delete-project',
    component: DeleteProject,
    props: route => ({ ppk: route.query.ppk })
  },

  // Experiment routes - uses ?e= for experiment, ?ppk= for parent project
  {
    path: '/add_experiment',
    name: 'add-experiment',
    component: AddExperiment,
    props: route => ({ ppk: route.query.ppk })
  },
  {
    path: '/edit_experiment',
    name: 'edit-experiment',
    component: EditExperiment,
    props: route => ({ e: route.query.e })
  },
  {
    path: '/view_experiment',
    name: 'view-experiment',
    component: ViewExperiment,
    props: route => ({ e: route.query.e })
  },
  {
    path: '/delete_experiment',
    name: 'delete-experiment',
    component: DeleteExperiment,
    props: route => ({ e: route.query.e })
  },

  // Facility routes - uses ?f= parameter
  {
    path: '/add_facility',
    name: 'add-facility',
    component: AddFacility
  },
  {
    path: '/edit_facility',
    name: 'edit-facility',
    component: EditFacility,
    props: route => ({ f: route.query.f })
  },
  {
    path: '/view_facility',
    name: 'view-facility',
    component: ViewFacility,
    props: route => ({ f: route.query.f })
  },

  // Apparatus routes - uses ?a= for apparatus, ?f= for parent facility
  {
    path: '/add_apparatus',
    name: 'add-apparatus',
    component: AddApparatus,
    props: route => ({ f: route.query.f })
  },
  {
    path: '/edit_apparatus',
    name: 'edit-apparatus',
    component: EditApparatus,
    props: route => ({ a: route.query.a })
  },
  {
    path: '/view_apparatus',
    name: 'view-apparatus',
    component: ViewApparatus,
    props: route => ({ a: route.query.a })
  },

  // Repository
  {
    path: '/apparatus_repository',
    name: 'apparatus-repository',
    component: ApparatusRepository
  },

  // Catch-all redirect to home
  {
    path: '/:pathMatch(.*)*',
    redirect: '/'
  }
]

const router = createRouter({
  history: createWebHistory('/newexperimental/'),
  routes,
  scrollBehavior(to, from, savedPosition) {
    // If browser back/forward, restore saved position
    if (savedPosition) {
      return savedPosition
    }
    // Otherwise scroll to top
    return { top: 0 }
  }
})

export default router
