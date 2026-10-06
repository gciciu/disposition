import {
  mdiAccountCircle,
  mdiAccountMultiple,
  mdiTruckDelivery,
  mdiClipboardListOutline,
  mdiLogout,
  mdiMonitor,
  mdiRoutes,
  mdiSquareEditOutline,
  mdiTable,
} from '@mdi/js'

export const menuAsideMain = [
  {
    to: '/dashboard',
    icon: mdiMonitor,
    labelKey: 'menu.dashboard',
  },
  {
    to: '/routes',
    labelKey: 'menu.deliveryRoutes',
    icon: mdiRoutes,
  },
  {
    to: '/tables',
    labelKey: 'menu.orders',
    icon: mdiClipboardListOutline,
  },
  {
    to: '/tables',
    labelKey: 'menu.clients',
    icon: mdiAccountMultiple,
  },
  {
    to: '/couriers',
    labelKey: 'menu.couriers',
    icon: mdiTruckDelivery,
  },
  {
    to: '/forms',
    labelKey: 'menu.forms',
    icon: mdiSquareEditOutline,
  },
  {
    to: '/tables',
    labelKey: 'menu.tables',
    icon: mdiTable,
  },
  {
    to: '/profile',
    labelKey: 'menu.profile',
    icon: mdiAccountCircle,
  },
]

export const menuAsideBottom = [
  {
    labelKey: 'menu.logout',
    icon: mdiLogout,
    color: 'info',
    isLogout: true,
  },
]
