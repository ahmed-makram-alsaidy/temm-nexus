import { qs } from './http.js';
import type { HttpClient } from './http.js';
import type { ListParams, Paginated, User } from './types.js';

/** Typed helpers over project user endpoints (auth:sanctum). */
export class UsersModule {
  constructor(private readonly http: HttpClient) {}

  async get(id: number | string): Promise<User> {
    const { data } = await this.http.get<User | { data: User }>(`/api/v1/users/${id}`);
    return (data as { data?: User }).data && typeof (data as { data?: User }).data === 'object'
      ? (data as { data: User }).data
      : (data as User);
  }

  async list(params?: ListParams): Promise<Paginated<User>> {
    const { data } = await this.http.get<Paginated<User>>('/api/v1/users', {
      query: qs({
        page: params?.page,
        perPage: params?.perPage,
        search: params?.search,
        sort: params?.sort,
        filter: params?.filter,
      }),
    });
    return data;
  }
}
