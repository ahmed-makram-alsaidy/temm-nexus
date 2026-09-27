import type { HttpClient } from './http.js';
import type { AuthSession, User } from './types.js';

export interface LoginInput {
  email: string;
  password: string;
  device_name?: string;
}

export interface RegisterInput extends LoginInput {
  name: string;
  password_confirmation?: string;
}

/** Token endpoints. Passwords are sent once over HTTPS and never stored. */
export class AuthModule {
  constructor(private readonly http: HttpClient) {}

  /** Laravel wraps a top-level JsonResource in `{data: …}` — accept both. */
  private static userOf(payload: unknown): User {
    if (payload && typeof payload === 'object' && 'data' in payload) {
      const inner = (payload as { data: unknown }).data;
      if (inner && typeof inner === 'object' && 'email' in inner) return inner as User;
    }
    return payload as User;
  }

  async register(input: RegisterInput): Promise<AuthSession> {
    const { data } = await this.http.post<{ user: User; token: string }>('/api/v1/auth/register', {
      name: input.name,
      email: input.email,
      password: input.password,
      password_confirmation: input.password_confirmation ?? input.password,
      ...(input.device_name ? { device_name: input.device_name } : {}),
    });
    await this.http.tokenStore.set(data.token);
    return { user: AuthModule.userOf(data.user), token: data.token };
  }

  async login(input: LoginInput): Promise<AuthSession> {
    const { data } = await this.http.post<{ user: User; token: string }>('/api/v1/auth/login', {
      email: input.email,
      password: input.password,
      ...(input.device_name ? { device_name: input.device_name } : {}),
    });
    await this.http.tokenStore.set(data.token);
    return { user: AuthModule.userOf(data.user), token: data.token };
  }

  async logout(): Promise<void> {
    try {
      await this.http.post('/api/v1/auth/logout', {});
    } finally {
      await this.http.tokenStore.clear();
    }
  }

  /** Current user. Throws UNAUTHENTICATED (401) when the token is missing/invalid. */
  async me(): Promise<User> {
    const { data } = await this.http.get<User>('/api/v1/user');
    return AuthModule.userOf(data);
  }

  /** Existence-safe: always resolves, never reveals whether the email exists. */
  async forgotPassword(email: string): Promise<void> {
    await this.http.post('/api/v1/auth/forgot-password', { email });
  }

  async resetPassword(input: { token: string; email: string; password: string; password_confirmation?: string }): Promise<void> {
    await this.http.post('/api/v1/auth/reset-password', {
      token: input.token,
      email: input.email,
      password: input.password,
      password_confirmation: input.password_confirmation ?? input.password,
    });
  }

  async refresh(): Promise<AuthSession> {
    const { data } = await this.http.post<{ user: User; token: string }>('/api/v1/auth/refresh', {});
    await this.http.tokenStore.set(data.token);
    return { user: AuthModule.userOf(data.user), token: data.token };
  }

  /** Revoke one (`id`) or all other sessions. Local token is cleared. */
  async revokeSessions(id?: string | number): Promise<void> {
    try {
      if (id === undefined) await this.http.delete('/api/v1/auth/sessions');
      else await this.http.delete(`/api/v1/auth/sessions/${id}`);
    } finally {
      await this.http.tokenStore.clear();
    }
  }

  async getToken(): Promise<string | null> {
    return this.http.currentToken();
  }

  async setToken(token: string | null): Promise<void> {
    if (token === null) await this.http.tokenStore.clear();
    else await this.http.tokenStore.set(token);
  }
}
