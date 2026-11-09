export type AuthUser = { id: string; name: string; firstName: string; email: string };
export type SharedProps = { auth: { user: AuthUser | null }; flash: { status?: string | null } };
