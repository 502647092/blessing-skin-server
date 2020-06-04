export default {
  admin: {
    players: {
      list: () => '/admin/players/list' as const,
      delete: (player: number) => `/admin/players/${player}`,
      name: (player: number) => `/admin/players/${player}/name`,
      owner: (player: number) => `/admin/players/${player}/owner`,
      texture: (player: number) => `/admin/players/${player}/textures`,
    },
    users: {
      list: () => '/admin/users/list' as const,
      delete: (user: number) => `/admin/users/${user}`,
      email: (user: number) => `/admin/users/${user}/email`,
      nickname: (user: number) => `/admin/users/${user}/nickname`,
      password: (user: number) => `/admin/users/${user}/password`,
      permission: (user: number) => `/admin/users/${user}/permission`,
      score: (user: number) => `/admin/users/${user}/score`,
      verification: (user: number) => `/admin/users/${user}/verification`,
    },
  },
  auth: {
    bind: () => '/auth/bind' as const,
    forgot: () => '/auth/forgot' as const,
    login: () => '/auth/login' as const,
    logout: () => '/auth/logout' as const,
    register: () => '/auth/register' as const,
    reset: (uid: number) => `/auth/reset/${uid}`,
    verify: (uid: number) => `/auth/verify/${uid}`,
  },
  skinlib: {
    home: () => '/skinlib' as const,
    info: (texture: number) => `/skinlib/info/${texture}`,
    list: () => '/skinlib/list' as const,
    show: (tid: number) => `/skinlib/show/${tid}`,
    upload: () => '/skinlib/upload' as const,
  },
  user: {
    home: () => '/user' as const,
    closet: {
      add: () => '/user/closet' as const,
      page: () => '/user/closet' as const,
      ids: () => '/user/closet/ids' as const,
      list: () => '/user/closet/list' as const,
      rename: (tid: number) => `/user/closet/${tid}`,
      remove: (tid: number) => `/user/closet/${tid}`,
    },
    notification: (id: number) => `/user/notifications/${id}`,
    player: {
      page: () => '/user/player' as const,
      add: () => '/user/player' as const,
      list: () => '/user/player/list' as const,
      delete: (player: number) => `/user/player/${player}`,
      rename: (player: number) => `/user/player/${player}/name`,
      clear: (player: number) => `/user/player/${player}/textures`,
      set: (player: number) => `/user/player/${player}/textures`,
    },
    profile: { avatar: () => '/user/profile/avatar' as const },
    score: () => '/user/score-info' as const,
    sign: () => '/user/sign' as const,
  },
}
