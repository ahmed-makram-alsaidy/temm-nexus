import { NextResponse } from 'next/server';

/**
 * POST /api/session { token } — mirrors the browser token into an HttpOnly
 * session cookie so Server Components can build per-request clients.
 * The token never renders into HTML; logout deletes the cookie.
 */
export async function POST(req: Request) {
  const { token } = (await req.json().catch(() => ({}))) as { token?: string };
  if (!token || typeof token !== 'string' || token.length > 500) {
    return NextResponse.json({ error: 'token required' }, { status: 400 });
  }
  const res = NextResponse.json({ ok: true });
  res.cookies.set('backend_token', token, {
    httpOnly: true,
    sameSite: 'lax',
    secure: process.env.NODE_ENV === 'production',
    path: '/',
    maxAge: 60 * 60 * 12,
  });
  return res;
}
