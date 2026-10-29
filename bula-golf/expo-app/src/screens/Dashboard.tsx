import React, { useEffect, useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { api } from '../api';
import { C, Card, H, Loading, Mut, fail, money } from '../ui';

export default function Dashboard() {
  const [d, setD] = useState<any>(null);
  useEffect(() => { api('dashboard').then(setD).catch(fail); }, []);
  if (!d) return <Loading />;
  const st: Record<string, number> = Object.fromEntries(d.carts.map((x: any) => [x.status, +x.n]));
  const mx = Math.max(1, ...d.days.map((x: any) => +x.n));
  const stat = (v: string | number, l: string) => (
    <View style={{ backgroundColor: C.brand2, borderRadius: 10, padding: 12, minWidth: 140, flexGrow: 1 }}><Text style={{ fontSize: 26, fontWeight: '800', color: C.ink }}>{v}</Text><Text style={{ color: C.mut }}>{l}</Text></View>
  );
  return (
    <ScrollView contentContainerStyle={{ padding: 16 }}>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
        {stat(d.today.rounds, 'Check-ins today')}{stat(d.today.out_now ?? 0, 'On course now')}{stat(money(d.today.fees), 'Hire today')}
        {stat(st.available || 0, 'Carts available')}{stat((st.maintenance || 0) + (st.offline || 0) + (st.charging || 0), 'Carts unavailable')}
      </View>
      <Card style={{ marginTop: 14 }}>
        <H>Check-ins, last 14 days</H>
        <View style={{ flexDirection: 'row', alignItems: 'flex-end', height: 120, gap: 4 }}>
          {d.days.map((x: any) => <View key={x.d} style={{ flex: 1, height: `${Math.max(3, Math.round((100 * x.n) / mx))}%`, backgroundColor: C.brand, borderRadius: 3 }} />)}
        </View>
        {d.days.length === 0 && <Mut>No data yet</Mut>}
      </Card>
      <Card>
        <H>Recent damage & warnings</H>
        {d.issues.length === 0 ? <Mut>None 🎉</Mut> : d.issues.map((i: any) => <View key={i.id} style={{ marginBottom: 8 }}><Text style={{ fontWeight: '700', color: C.ink }}>{i.guest}</Text><Mut>{i.return_note}</Mut></View>)}
      </Card>
      <Mut>Fleet, terms, staff and exports are managed in the web admin.</Mut>
    </ScrollView>
  );
}
