# Migrating from Supabase (helper mappings — Phase 22P)

Preparation only. Do NOT begin migrating real apps in this phase. When a
Supabase-to-TEMM Nexus conversion starts, these are the mechanical translations
(Supabase-js on the left, this platform on the right).

## Auth

```ts
// Supabase
const { data } = await supabase.auth.signInWithPassword({ email, password });
// Platform
const session = await backend.auth.login({ email, password }); // { user, token }
```

| Supabase | Platform | Notes |
|---|---|---|
| `signUp()` | `auth.register()` | 201 `{user,token}` |
| `signInWithPassword()` | `auth.login()` | 403 disabled, 422 bad shape |
| `signOut()` | `auth.logout()` | Revokes current token, clears store |
| `getUser()` / `onAuthStateChange()` | `auth.me()` + your own listener | No built-in broadcast yet; poll `me()` or wrap the store |
| `resetPasswordForEmail()` | `auth.forgotPassword()` | Existence-safe both sides |
| `updateUser()` | project `PATCH /api/v1/user` | Per-project endpoint |
| `refreshSession()` | `auth.refresh()` | Only for expiring-token projects |

## Data

```ts
// Supabase
const { data } = await supabase.from('orders').select('*').eq('status', 'open').order('created_at', { ascending: false });
// Platform (contract §5–6: Laravel paginator + filter/sort conventions)
const page = await backend.http.get('/api/v1/orders', {
  query: qs({ filter: { status: 'open' }, sort: '-created_at', page: 1, perPage: 25 }),
});
```

There is no generic `from(table)` — project tables get typed endpoints
(`users.list()` is the reference). RLS moves server-side (policies/middleware),
not into client filters.

## Storage

```ts
// Supabase
await supabase.storage.from('avatars').upload('u/1/p.jpg', file);
supabase.storage.from('avatars').getPublicUrl('u/1/p.jpg');
// Platform
await backend.storage.upload(file, { bucket: 'avatars', key: 'u/1/p.jpg' });
await backend.storage.signedUrl('avatars', 'u/1/p.jpg');
```

## Functions

```ts
// Supabase
await supabase.functions.invoke('hello', { body: { name } });
// Platform
await backend.functions.invoke('hello-platform', { body: { name } }); // { data, status, requestId }
```

## Realtime

```ts
// Supabase
supabase.channel('orders').on('broadcast', { event: 'created' }, cb).subscribe();
// Platform
await backend.realtime.subscribe({ channel: 'orders', event: 'created', onEvent: cb });
```

Pusher-protocol channels (`private-`/`presence-` need the API key) replace
Supabase `postgres_changes`; server-side changefeeds become project broadcast
events. Payload cap 4000 chars both directions — chunk larger payloads via Storage.
