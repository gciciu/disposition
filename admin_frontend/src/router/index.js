import { createRouter, createWebHashHistory } from 'vue-router'
import Home from '@/views/HomeView.vue'
import { ensureFreshAccessToken } from '@/api.js'
import { useAuthStore } from '@/stores/auth.js'

const routes = [
  {
    path: '/',
    redirect: '/dashboard',
  },
  {
    meta: {
      titleKey: 'routes.dashboard',
      requiresAuth: true,
      requiresAdmin: true,
    },
    path: '/dashboard',
    name: 'dashboard',
    component: Home,
  },
  {
    meta: {
      titleKey: 'routes.deliveryRoutes',
      requiresAuth: true,
      requiresAdmin: true,
    },
    path: '/routes',
    name: 'routes',
    component: () => import('@/views/RoutesView.vue'),
  },
  {
    meta: {
      titleKey: 'routes.deliveryRouteDetail',
      requiresAuth: true,
      requiresAdmin: true,
    },
    path: '/routes/:id',
    name: 'route-detail',
    component: () => import('@/views/RouteDetailView.vue'),
  },
  {
    meta: {
      titleKey: 'routes.couriers',
      requiresAuth: true,
      requiresAdmin: true,
    },
    path: '/couriers',
    name: 'couriers',
    component: () => import('@/views/CouriersView.vue'),
  },
  {
    meta: {
      titleKey: 'routes.courierNew',
      requiresAuth: true,
      requiresAdmin: true,
    },
    path: '/couriers/new',
    name: 'courier-new',
    component: () => import('@/views/CourierCardView.vue'),
  },
  {
    meta: {
      titleKey: 'routes.courier',
      requiresAuth: true,
      requiresAdmin: true,
    },
    path: '/couriers/:id',
    name: 'courier',
    component: () => import('@/views/CourierCardView.vue'),
  },
  {
    meta: {
      titleKey: 'routes.tables',
      requiresAuth: true,
      requiresAdmin: true,
    },
    path: '/tables',
    name: 'tables',
    component: () => import('@/views/TablesView.vue'),
  },
  {
    meta: {
      titleKey: 'routes.forms',
      requiresAuth: true,
      requiresAdmin: true,
    },
    path: '/forms',
    name: 'forms',
    component: () => import('@/views/FormsView.vue'),
  },
  {
    meta: {
      titleKey: 'routes.profile',
      requiresAuth: true,
      requiresAdmin: true,
    },
    path: '/profile',
    name: 'profile',
    component: () => import('@/views/ProfileView.vue'),
  },
  {
    meta: {
      titleKey: 'routes.ui',
      requiresAuth: true,
      requiresAdmin: true,
    },
    path: '/ui',
    name: 'ui',
    component: () => import('@/views/UiView.vue'),
  },
  {
    meta: {
      titleKey: 'routes.responsive',
      requiresAuth: true,
      requiresAdmin: true,
    },
    path: '/responsive',
    name: 'responsive',
    component: () => import('@/views/ResponsiveView.vue'),
  },
  {
    meta: {
      titleKey: 'routes.login',
      guestOnly: true,
    },
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
  },
  {
    meta: {
      titleKey: 'routes.error',
    },
    path: '/error',
    name: 'error',
    component: () => import('@/views/ErrorView.vue'),
  },
  {
    meta: {
      titleKey: 'routes.styles',
      requiresAuth: true,
      requiresAdmin: true,
    },
    path: '/styles',
    name: 'style',
    component: () => import('@/views/StyleView.vue'),
  },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
  scrollBehavior(to, from, savedPosition) {
    return savedPosition || { top: 0 }
  },
})

router.beforeEach(async (to) => {
  const authStore = useAuthStore()

  if (to.meta.requiresAuth || to.meta.requiresAdmin) {
    if (!authStore.isAuthenticated) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }

    try {
      await ensureFreshAccessToken()
    } catch {
      await authStore.logout()
      return {
        name: 'login',
        query: { expired: '1', redirect: to.fullPath },
      }
    }

    if (!authStore.user) {
      try {
        await authStore.fetchMe()
      } catch (error) {
        await authStore.logout()
        if (error.response?.status === 401) {
          return {
            name: 'login',
            query: { expired: '1', redirect: to.fullPath },
          }
        }
        return { name: 'login' }
      }
    }

    if (to.meta.requiresAdmin && !authStore.isAdmin) {
      await authStore.logout()
      return { name: 'login' }
    }
  }

  if (to.meta.guestOnly && authStore.isAuthenticated && authStore.isAdmin) {
    return { name: 'dashboard' }
  }

  return true
})

export default router
