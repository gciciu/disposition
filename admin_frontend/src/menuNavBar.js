import {
  mdiAccount,
  mdiCogOutline,
  mdiEmail,
  mdiLogout,
  mdiThemeLightDark,
} from '@mdi/js'

export default [
  {
    isCurrentUser: true,
    menu: [
      {
        icon: mdiAccount,
        labelKey: 'menu.profile',
        to: '/profile',
      },
      {
        icon: mdiCogOutline,
        labelKey: 'menu.settings',
      },
      {
        icon: mdiEmail,
        labelKey: 'menu.messages',
      },
      {
        isDivider: true,
      },
      {
        icon: mdiLogout,
        labelKey: 'menu.logout',
        isLogout: true,
      },
    ],
  },
  {
    icon: mdiThemeLightDark,
    labelKey: 'menu.theme',
    isDesktopNoLabel: true,
    isToggleLightDark: true,
  },
  {
    icon: mdiLogout,
    labelKey: 'menu.logout',
    isDesktopNoLabel: true,
    isLogout: true,
  },
]
