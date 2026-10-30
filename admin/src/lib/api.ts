import type { z } from 'zod';
import { readCookie } from './cookies';
import { ApiError, toApiError } from './problem';

/**
 * Same-origin client for the AlphaMaster API. The admin uses Sanctum's cookie session
 * (ADR-0003): no token is ever stored in JavaScript. Unsafe requests carry the
 * X-XSRF-TOKEN header that Laravel checks against the session.
 */
const API_BASE = '/api/v1';
const UNSAFE = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

let language = 'ar';
export function setApiLanguage(lang: string) {
  language = lang;
}

let onUnauthenticated: (() => void) | null = null;
/** Called when an authenticated request comes back 401 (expired or revoked session). */
export function setUnauthenticatedHandler(handler: (() => void) | null) {
  onUnauthenticated = handler;
}

export async function ensureCsrfCookie(): Promise<void> {
  let res: Response;
  try {
    res = await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  } catch {
    throw new ApiError(0, null);
  }
  if (!res.ok) throw toApiError(res.status, null);
}

type Query = Record<string, string | number | undefined | null>;

interface RequestOptions<S extends z.ZodType | undefined> {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  query?: Query;
  body?: unknown;
  schema?: S;
  signal?: AbortSignal;
  /** Login and the session probe handle 401 themselves. */
  skipAuthRedirect?: boolean;
}

function buildUrl(path: string, query?: Query) {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value));
  }
  const search = params.toString();
  return API_BASE + path + (search ? `?${search}` : '');
}

async function send(path: string, options: RequestOptions<z.ZodType | undefined>, retried = false): Promise<Response> {
  const method = options.method ?? 'GET';
  const unsafe = UNSAFE.has(method);
  if (unsafe && !readCookie('XSRF-TOKEN')) await ensureCsrfCookie();

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language': language,
    'X-Requested-With': 'XMLHttpRequest',
  };
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  if (unsafe) headers['X-XSRF-TOKEN'] = readCookie('XSRF-TOKEN') ?? '';

  let res: Response;
  try {
    res = await fetch(buildUrl(path, options.query), {
      method,
      headers,
      credentials: 'same-origin',
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: options.signal,
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error;
    throw new ApiError(0, null);
  }

  // A stale CSRF token (the session rotated): fetch a fresh one and retry once.
  if (res.status === 419 && unsafe && !retried) {
    await ensureCsrfCookie();
    return send(path, options, true);
  }
  return res;
}

export async function api<S extends z.ZodType | undefined = undefined>(
  path: string,
  options: RequestOptions<S> = {},
): Promise<S extends z.ZodType ? z.infer<S> : unknown> {
  const res = await send(path, options);

  if (!res.ok) {
    const body = await res.json().catch(() => null);
    const error = toApiError(res.status, body, res.headers.get('Retry-After'));
    if (res.status === 401 && !options.skipAuthRedirect) onUnauthenticated?.();
    throw error;
  }

  if (res.status === 204) return undefined as never;
  const json: unknown = await res.json();
  return (options.schema ? options.schema.parse(json) : json) as never;
}
