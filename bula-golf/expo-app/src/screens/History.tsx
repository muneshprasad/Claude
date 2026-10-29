import React, { useCallback, useEffect, useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { api, Checkin } from '../api';
import { C, Card, Field, H, Loading, Mut, fail, hm, money, today } from '../ui';

export default function History({ manager }: { manager: boolean }) {
  const [date, setDate] = useState(today());
  const [list, setList] = useState<Checkin[] | null>(null);
  const load = useCallback(() => api<{ checkins: Checkin[] }>('checkins/list', { params: { date } }).then(r => setList(r.checkins)).catch(fail), [date]);
  useEffect(() => { if (/^\d{4}-\d{2}-\d{2}$/.test(date)) load(); }, [date, load]);
  const total = (list ?? []).reduce((s, c) => s + Number(c.hire_fee), 0);
  return (
    <ScrollView contentContainerStyle={{ padding: 16 }}>
      <Card>
        <H>Check-ins</H>
        {manager ? <Field label="Date (YYYY-MM-DD)" value={date} onChangeText={setDate} keyboardType="numbers-and-punctuation" /> : <Mut>Today</Mut>}
        {!list ? <Loading /> : list.length === 0 ? <Mut>No check-ins.</Mut> : list.map(c => (
          <View key={c.id} style={{ flexDirection: 'row', paddingVertical: 10, borderBottomWidth: 1, borderColor: C.line }}>
            <Text style={{ width: 50, color: C.mut }}>{hm(c.checked_in_at)}</Text>
            <View style={{ flex: 1 }}>
              <Text style={{ fontWeight: '700', color: C.ink }}>{c.guest}{c.golfers > 1 ? ` +${c.golfers - 1}` : ''}</Text>
              <Mut>{c.mobile} {c.hotel}</Mut>
            </View>
            <Text style={{ width: 90, color: C.ink }}>{c.cart_name ?? '—'}{'\n'}{c.clubs_name ?? '—'}</Text>
            <Text style={{ width: 70, color: !c.returned_at ? C.amb : c.return_condition === 'ok' ? C.brand : C.warn }}>{!c.returned_at ? 'Out' : c.return_condition === 'ok' ? `Back ${hm(c.returned_at)}` : c.return_condition}</Text>
          </View>
        ))}
        {list && list.length > 0 && <Text style={{ fontWeight: '700', color: C.ink, marginTop: 12 }}>Hire total: {money(total)}</Text>}
      </Card>
    </ScrollView>
  );
}
