'use client';

/**
 * Login page: SDK init → login → me → route to the protected page.
 * Errors render from ApiError (message + field errors on 422).
 */
import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { ApiError } from '@platform/backend-sdk';
import { getBrowserClient } from '../../lib/backend';

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      const backend = getBrowserClient();
      await backend.auth.login({ email, password, device_name: 'web' });
      await backend.auth.me(); // proves the token works before navigating
      // Mirror the token into the HttpOnly session cookie for Server Components.
      await fetch('/api/session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: await backend.auth.getToken() }),
      });
      router.push('/dashboard');
    } catch (err) {
      if (err instanceof ApiError) {
        setError(`${err.message} (${err.code})`);
        if (err.errors) setFieldErrors(err.errors);
      } else {
        setError('Login failed.');
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <main>
      <h1>Sign in</h1>
      <form onSubmit={onSubmit}>
        <label>Email<input value={email} onChange={(e) => setEmail(e.target.value)} type="email" required /></label>
        {fieldErrors.email?.map((m) => <p key={m}>{m}</p>)}
        <label>Password<input value={password} onChange={(e) => setPassword(e.target.value)} type="password" required /></label>
        {fieldErrors.password?.map((m) => <p key={m}>{m}</p>)}
        {error && <p role="alert">{error}</p>}
        <button disabled={busy} type="submit">{busy ? 'Signing in…' : 'Sign in'}</button>
      </form>
    </main>
  );
}
