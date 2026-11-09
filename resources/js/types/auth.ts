export type AuthUser = { id: string; name: string; firstName: string; email: string; isStaff: boolean; permissions: string[] };
export type SharedProps = { auth: { user: AuthUser | null }; flash: { status?: string | null } };
