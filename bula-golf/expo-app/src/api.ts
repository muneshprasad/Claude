import * as SecureStore from 'expo-secure-store';

const K_URL = 'bula.url', K_TOKEN = 'bula.token';
let base = (process.env.EXPO_PUBLIC_API_URL ?? '').replace(/\/+$/, '');
let token = '';

const get = async (k: string) => { try { return await SecureStore.getItemAsync(k); } catch { return null; } };
const set = async (k: string, v: string | null) => { try { v ? await SecureStore.setItemAsync(k, v) : await SecureStore.deleteItemAsync(k); } catch {} };

export const getBase = () => base;
export async function loadSession() {
  base = (await get(K_URL)) || base;
  token = (await get(K_TOKEN)) || '';
}
export async function setBase(url: string) {
  base = url.trim().replace(/\/+$/, '').replace(/\/api\/index\.php$/, '');
  await set(K_URL, base);
}
export async function setToken(t: string) { token = t; await set(K_TOKEN, t || null); }
export const hasToken = () => !!token;

export class ApiError extends Error { constructor(m: string, public status = 0) { super(m); } }

export async function api<T = any>(route: string, opts: { method?: string; body?: unknown; params?: Record<string, string | number> } = {}): Promise<T> {
  if (!base) throw new ApiError('Enter the server address first');
  const qs = new URLSearchParams({ r: route, ...Object.fromEntries(Object.entries(opts.params ?? {}).map(([k, v]) => [k, String(v)])) });
  let res: Response;
  try {
    res = await fetch(`${base}/api/index.php?${qs}`, {
      method: opts.method ?? 'GET',
      headers: { 'X-Requested-With': 'bula', 'X-Bula-Token': token, ...(opts.body ? { 'Content-Type': 'application/json' } : {}) },
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
  } catch { throw new ApiError('No connection to the server. Check Wi-Fi and try again.'); }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(data.error || 'Something went wrong', res.status);
  return data as T;
}

export type Me = { id: number; name: string; role: 'staff' | 'manager' };
export type Asset = { id: number; kind: 'cart' | 'clubs'; name: string; description: string | null; fee: string; status: string; gps_battery: number | null };
export type Golfer = {
  mobile: string; first_name: string; last_name: string; email: string; country: string; hotel: string; room: string;
  dob: string; guest_type: 'guest' | 'member'; marketing_optin: boolean; guardian_name: string; signature?: string;
};
export type Checkin = {
  id: number; checked_in_at: string; returned_at: string | null; holes: number; hire_fee: string; return_condition: string | null;
  guest: string; mobile: string; hotel: string | null; room: string | null; golfers: number; cart_name: string | null; clubs_name: string | null;
};
