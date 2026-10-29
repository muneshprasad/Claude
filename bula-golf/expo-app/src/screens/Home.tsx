import React, { useCallback, useEffect, useState } from 'react';
import { ScrollView } from 'react-native';
import { api, Asset } from '../api';
import { Big, Loading } from '../ui';
import type { Nav } from '../../App';

export default function Home({ nav, manager }: { nav: Nav; manager: boolean }) {
  const [info, setInfo] = useState<{ free: number; carts: number; out: number } | null>(null);
  const load = useCallback(async () => {
    try {
      const [a, o] = await Promise.all([api<{ assets: Asset[] }>('assets'), api<{ checkins: unknown[] }>('checkins/active')]);
      const carts = a.assets.filter(x => x.kind === 'cart');
      setInfo({ carts: carts.length, free: carts.filter(x => x.status === 'available').length, out: o.checkins.length });
    } catch {}
  }, []);
  useEffect(() => { load(); const t = setInterval(load, 15000); return () => clearInterval(t); }, [load]);
  if (!info) return <Loading />;
  return (
    <ScrollView contentContainerStyle={{ padding: 16 }}>
      <Big title="➕ New check-in" sub={`${info.free} of ${info.carts} carts available`} onPress={() => nav.go('checkin')} />
      <Big alt title="↩ Return cart / clubs" sub={`${info.out} on the course now`} onPress={() => nav.go('returns')} />
      <Big alt title="📋 Today's check-ins" onPress={() => nav.go('history')} />
      {manager && <Big alt title="📊 Dashboard" onPress={() => nav.go('dashboard')} />}
    </ScrollView>
  );
}
