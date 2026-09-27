/** Shared public types for @platform/backend-sdk (Platform API v1). */

export interface User {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
  created_at: string;
}

export interface AuthSession {
  user: User;
  /** Sanctum token in `id|plain` form. Store via the configured TokenStore. */
  token: string;
  tokenType?: string;
}

export type ApiErrorCode =
  | 'BAD_REQUEST'
  | 'UNAUTHENTICATED'
  | 'FORBIDDEN'
  | 'NOT_FOUND'
  | 'METHOD_NOT_ALLOWED'
  | 'CONFLICT'
  | 'VALIDATION_ERROR'
  | 'RATE_LIMITED'
  | 'SERVER_ERROR'
  | 'UNAVAILABLE'
  | 'NETWORK_ERROR'
  | 'TIMEOUT'
  | 'ABORTED';

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number | null;
  to?: number | null;
}

export interface Paginated<T> {
  data: T[];
  meta: PaginationMeta;
}

export interface ListParams {
  page?: number;
  perPage?: number;
  search?: string;
  sort?: string | string[];
  filter?: Record<string, string | number | boolean>;
}

export interface StorageObject {
  bucket: string;
  key: string;
  size?: number;
  mime?: string;
  url?: string;
  temporary_url?: string | null;
  etag?: string;
}

export interface FunctionResponse<T = unknown> {
  data: T;
  status: number;
  requestId: string;
  durationMs?: number;
  version?: number;
}

export interface RealtimeEvent<T = unknown> {
  channel: string;
  event: string;
  data: T;
}

export interface RequestOptions {
  query?: Record<string, string | number | boolean | undefined | null>;
  headers?: Record<string, string>;
  timeoutMs?: number;
  signal?: AbortSignal;
  /** Override stored credentials for this call only. */
  token?: string | null;
  apiKey?: string | null;
}

export interface UploadOptions extends RequestOptions {
  bucket: string;
  /** Destination key/prefix. Server may ignore and mint its own. */
  key?: string;
  fileName?: string;
  mime?: string;
  visibility?: 'public' | 'private';
}
