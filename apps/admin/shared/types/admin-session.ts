import type { paths } from '@elzara/api-client'

type SessionResponse = paths['/admin/auth/session']['get']['responses'][200]['content']['application/json']

export type AdminSession = Omit<SessionResponse, 'user'> & {
  user: Pick<SessionResponse['user'], 'user_id' | 'name'>
}
