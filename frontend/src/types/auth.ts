/** The signed-in user, as GET /api/user returns it (UserResource). */
export interface User {
  id: number
  name: string
  email: string
  avatar: string | null
}
