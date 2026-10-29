import React, { useCallback, useEffect, useState } from 'react';
import { Modal, Pressable, ScrollView, Text, View } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import { manipulateAsync, SaveFormat } from 'expo-image-manipulator';
import { api, Checkin } from '../api';
import { Btn, C, Card, Field, H, Loading, Mut, fail, hm } from '../ui';

export default function Returns() {
  const [list, setList] = useState<Checkin[] | null>(null);
  const [sel, setSel] = useState<Checkin | null>(null);
  const load = useCallback(() => api<{ checkins: Checkin[] }>('checkins/active').then(r => setList(r.checkins)).catch(() => {}), []);
  useEffect(() => { load(); const t = setInterval(load, 10000); return () => clearInterval(t); }, [load]);
  if (!list) return <Loading />;
  return (
    <>
      <ScrollView contentContainerStyle={{ padding: 16 }}>
        <Card>
          <H>Tap an item to return it</H>
          {list.length === 0 && <Mut>Nothing is out right now.</Mut>}
          {list.map(c => (
            <Pressable key={c.id} onPress={() => setSel(c)} style={{ flexDirection: 'row', paddingVertical: 12, borderBottomWidth: 1, borderColor: C.line }}>
              <View style={{ flex: 1 }}>
                <Text style={{ fontWeight: '700', color: C.ink, fontSize: 17 }}>{c.guest}{c.golfers > 1 ? ` +${c.golfers - 1}` : ''}</Text>
                <Mut>{c.hotel} {c.room}</Mut>
              </View>
              <Text style={{ color: C.ink, width: 110 }}>{c.cart_name ?? '—'}{'\n'}{c.clubs_name ?? '—'}</Text>
              <Text style={{ color: C.mut }}>{hm(c.checked_in_at)}</Text>
            </Pressable>
          ))}
        </Card>
      </ScrollView>
      {sel && <ReturnDialog c={sel} onClose={() => setSel(null)} onDone={() => { setSel(null); load(); }} />}
    </>
  );
}

function ReturnDialog({ c, onClose, onDone }: { c: Checkin; onClose: () => void; onDone: () => void }) {
  const [mode, setMode] = useState<'pick' | 'damaged' | 'warning'>('pick');
  const [note, setNote] = useState(''), [photo, setPhoto] = useState(''), [busy, setBusy] = useState(false);
  const send = async (condition: string) => {
    setBusy(true);
    try { await api('checkins/return', { method: 'POST', body: { id: c.id, condition, note, photo } }); onDone(); } catch (e) { fail(e); setBusy(false); }
  };
  const snap = async () => {
    const perm = await ImagePicker.requestCameraPermissionsAsync();
    if (!perm.granted) return fail(new Error('Camera permission is needed to photograph damage'));
    const r = await ImagePicker.launchCameraAsync({ quality: 0.6 });
    if (r.canceled) return;
    const m = await manipulateAsync(r.assets[0].uri, [{ resize: { width: 900 } }], { compress: 0.7, format: SaveFormat.JPEG, base64: true });
    setPhoto(`data:image/jpeg;base64,${m.base64}`);
  };
  return (
    <Modal transparent animationType="fade" onRequestClose={onClose}>
      <View style={{ flex: 1, backgroundColor: '#0008', justifyContent: 'center', padding: 16 }}>
        <ScrollView style={{ maxHeight: '92%', flexGrow: 0 }} contentContainerStyle={{ backgroundColor: C.card, borderRadius: 14, padding: 18 }} keyboardShouldPersistTaps="handled">
          <H>Return: {c.guest}</H>
          <Mut>{c.cart_name ?? 'No cart'} · {c.clubs_name ?? 'No clubs'}</Mut>
          {mode === 'pick' ? (
            <View style={{ gap: 8, marginTop: 12 }}>
              <Btn title="✔ Returned – undamaged" kind="primary" onPress={() => send('ok')} disabled={busy} />
              <Btn title="⚠ Damaged" kind="danger" onPress={() => setMode('damaged')} />
              <Btn title="🚫 Raise patron warning" kind="danger" onPress={() => setMode('warning')} />
              <Btn title="Cancel" onPress={onClose} />
            </View>
          ) : (
            <View style={{ gap: 8, marginTop: 8 }}>
              <Field label="What happened? *" value={note} onChangeText={setNote} multiline style={{ minHeight: 80 }} />
              {mode === 'damaged' && <Btn title={photo ? '📷 Photo added – retake' : '📷 Take photo of damage'} onPress={snap} />}
              <Btn title={busy ? 'Saving…' : 'Save'} kind="danger" onPress={() => send(mode)} disabled={busy} />
              <Btn title="Cancel" onPress={onClose} />
            </View>
          )}
        </ScrollView>
      </View>
    </Modal>
  );
}
