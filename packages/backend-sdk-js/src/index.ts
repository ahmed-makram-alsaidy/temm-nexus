export { BackendClient, type BackendClientOptions } from './client.js';
export { HttpClient, qs, buildQuery, type HttpConfig, type FetchFn, type CallOptions } from './http.js';
export { AuthModule, type LoginInput, type RegisterInput } from './auth.js';
export { UsersModule } from './users.js';
export { StorageModule } from './storage.js';
export { FunctionsModule, type InvokeOptions } from './functions.js';
export { RealtimeModule, type RealtimeConfig, type SubscribeOptions, type Subscription, type WsSocket, type WsFactory } from './realtime.js';
export { ApiError, toApiError, redactHeaders } from './errors.js';
export { MemoryTokenStore, BrowserTokenStore, type TokenStore } from './token-store.js';
export type {
  User, AuthSession, ApiErrorCode, PaginationMeta, Paginated, ListParams,
  StorageObject, FunctionResponse, RealtimeEvent, RequestOptions, UploadOptions,
} from './types.js';
