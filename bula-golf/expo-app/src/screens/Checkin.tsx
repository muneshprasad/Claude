import React, { useEffect, useRef, useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import Signature, { SignatureViewRef } from 'react-native-signature-canvas';
import { api, Asset, Golfer } from '../api';
import { Btn, C, Card, Field, H, Loading, Mut, Tile, fail, money } from '../ui';
import type { Nav } from '../../App';

const blank = (m = '', hotel = '', room = ''): Golfer => ({ mobile: m, first_name: '', last_name: '', email: '', country: '', hotel, room, dob: '', guest_type: 'guest', marketing_optin: false, guardian_name: '' });
const under18 = (dob: string) => /^\d{4}-\d{2}-\d{2}$/.test(dob) && (Date.now() - new Date(dob).getTime()) / 31557600000 < 18;

type Step = 'guest' | 'sign' | 'assets' | 'done';

export default function Checkin({ nav }: { nav: Nav }) {
  const [step, setStep] = useState<Step>('guest');
  const [golfers, setGolfers] = useState<Golfer[]>([]);
  const [signIdx, setSignIdx] = useState(0);
  const [terms, setTerms] = useState<{ version: number; body: string } | null>(null);
  const [result, setResult] = useState<{ name: string; cart?: string; clubs?: string; fee: number } | null>(null);
  useEffect(() => { api('terms/current').then(setTerms).catch(fail); }, []);

  if (step === 'guest') return <GuestStep golfers={golfers} onCancel={nav.back} onBack={() => setGolfers(g => g.slice(0, -1))} onAdd={g => setGolfers(x => [...x, g])} onNext={g => { setGolfers(x => [...x, g]); setSignIdx(0); setStep('sign'); }} />;
  if (step === 'sign') return terms ? (
    <SignStep key={signIdx} golfer={golfers[signIdx]} idx={signIdx} total={golfers.length} terms={terms.body}
      onBack={() => (signIdx ? setSignIdx(signIdx - 1) : (setGolfers(g => g.slice(0, -1)), setStep('guest')))}
      onSigned={sig => {
        setGolfers(g => g.map((x, i) => (i === signIdx ? { ...x, signature: sig } : x)));
        signIdx + 1 < golfers.length ? setSignIdx(signIdx + 1) : setStep('assets');
      }} />
  ) : <Loading />;
  if (step === 'assets') return <AssetStep golfers={golfers} onBack={() => { setSignIdx(golfers.length - 1); setStep('sign'); }} onDone={r => { setResult(r); setStep('done'); }} />;
  return (
    <ScrollView contentContainerStyle={{ padding: 16 }}>
      <Card style={{ alignItems: 'center' }}>
        <H>✅ Checked in</H>
        <Text style={{ fontSize: 24, fontWeight: '700', color: C.ink }}>{result?.name}</Text>
        <Text style={{ fontSize: 30, marginVertical: 8 }}>{result?.cart ? `🛺 ${result.cart}` : 'Walking'}</Text>
        <Text style={{ fontSize: 20, color: C.ink }}>{result?.clubs ? `🏌 ${result.clubs}` : 'Own clubs'}</Text>
        <Text style={{ marginVertical: 10, color: C.ink }}>Hire total: {money(result?.fee ?? 0)}</Text>
        <Btn title="Done" kind="primary" onPress={nav.back} style={{ alignSelf: 'stretch' }} />
      </Card>
    </ScrollView>
  );
}

function GuestStep({ golfers, onAdd, onNext, onBack, onCancel }: { golfers: Golfer[]; onAdd: (g: Golfer) => void; onNext: (g: Golfer) => void; onBack: () => void; onCancel: () => void }) {
  const main = golfers[0];
  const [g, setG] = useState<Golfer>(blank(main?.mobile, main?.hotel, main?.room));
  const [found, setFound] = useState<any[]>([]);
  const set = (k: keyof Golfer) => (v: string | boolean) => setG(x => ({ ...x, [k]: v }));
  const lookup = async () => {
    if (g.mobile.replace(/\D/g, '').length < 6) return;
    try { const r = await api<{ guests: any[] }>('guests/lookup', { params: { mobile: g.mobile } }); setFound(r.guests); if (r.guests.length === 1 && !g.first_name) fill(r.guests[0]); } catch (e) { fail(e); }
  };
  const fill = (x: any) => { setG(p => ({ ...p, first_name: x.first_name, last_name: x.last_name, email: x.email ?? '', country: x.country ?? '', hotel: x.hotel || p.hotel, room: x.room || p.room, dob: x.dob ?? '', guest_type: x.guest_type, marketing_optin: !!+x.marketing_optin })); setFound([]); };
  const valid = () => {
    if (g.mobile.replace(/\D/g, '').length < 6 || !g.first_name.trim() || !g.last_name.trim()) { fail(new Error('Mobile, first name and last name are required')); return false; }
    if (under18(g.dob) && !g.guardian_name.trim()) { fail(new Error('Under 18: enter the parent/guardian name')); return false; }
    return true;
  };
  return (
    <ScrollView contentContainerStyle={{ padding: 16 }} keyboardShouldPersistTaps="handled">
      <Card>
        <H>{golfers.length ? `Golfer ${golfers.length + 1}` : 'Guest details'}</H>
        {golfers.length > 0 && <Mut>Already added: {golfers.map(x => x.first_name).join(', ')}</Mut>}
        <Field label="Mobile number *" value={g.mobile} onChangeText={set('mobile')} keyboardType="phone-pad" onBlur={lookup} />
        {found.map((x, i) => (
          <Pressable key={i} onPress={() => fill(x)} style={{ borderWidth: 2, borderColor: C.brand, backgroundColor: C.brand2, borderRadius: 10, padding: 10, marginTop: 8 }}>
            <Text style={{ fontWeight: '700', color: C.ink }}>Welcome back, {x.first_name} {x.last_name} · visit #{+x.visits + 1}{x.last_clubs ? ` · last hired ${x.last_clubs}` : ''}</Text>
            <Mut>Tap to fill in their details</Mut>
            {x.alert_note ? <Text style={{ color: C.amb, marginTop: 4 }}>⚠ {x.alert_note}</Text> : null}
          </Pressable>
        ))}
        <Field label="First name *" value={g.first_name} onChangeText={set('first_name')} />
        <Field label="Last name *" value={g.last_name} onChangeText={set('last_name')} />
        <Field label="Email" value={g.email} onChangeText={set('email')} keyboardType="email-address" autoCapitalize="none" />
        <Field label="Country" value={g.country} onChangeText={set('country')} />
        <Field label="Hotel / where staying" value={g.hotel} onChangeText={set('hotel')} />
        <Field label="Room number" value={g.room} onChangeText={set('room')} />
        <Field label="Date of birth (YYYY-MM-DD, needed for under-18s)" value={g.dob} onChangeText={set('dob')} placeholder="1990-05-31" keyboardType="numbers-and-punctuation" />
        {under18(g.dob) && <Field label="Parent / guardian full name (they sign) *" value={g.guardian_name} onChangeText={set('guardian_name')} />}
        <View style={{ flexDirection: 'row', gap: 8, marginTop: 12 }}>
          <Tile title="Visitor" sel={g.guest_type === 'guest'} onPress={() => set('guest_type')('guest')} />
          <Tile title="Member" sel={g.guest_type === 'member'} onPress={() => set('guest_type')('member')} />
        </View>
        <Pressable onPress={() => set('marketing_optin')(!g.marketing_optin)} style={{ flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 14 }}>
          <Text style={{ fontSize: 24 }}>{g.marketing_optin ? '☑' : '☐'}</Text>
          <Text style={{ flex: 1, color: C.ink }}>Happy to receive offers and news from Bula Golf</Text>
        </Pressable>
        <Mut>Your details are kept to make future check-ins faster.</Mut>
        <View style={{ gap: 8, marginTop: 14 }}>
          <Btn title="+ Add another golfer" onPress={() => { if (valid()) { onAdd(g); setG(blank(g.mobile, g.hotel, g.room)); setFound([]); } }} />
          <Btn title="Next: terms & signature →" kind="primary" onPress={() => valid() && onNext(g)} />
          <Btn title={golfers.length ? '← Remove last golfer' : 'Cancel'} onPress={golfers.length ? onBack : onCancel} />
        </View>
      </Card>
    </ScrollView>
  );
}

function SignStep({ golfer, idx, total, terms, onSigned, onBack }: { golfer: Golfer; idx: number; total: number; terms: string; onSigned: (s: string) => void; onBack: () => void }) {
  const ref = useRef<SignatureViewRef>(null);
  const [agree, setAgree] = useState(false);
  const [scroll, setScroll] = useState(true);
  return (
    <ScrollView contentContainerStyle={{ padding: 16 }} scrollEnabled={scroll}>
      <Card>
        <H>Terms of hire & signature</H>
        <Mut>Golfer {idx + 1} of {total}: {golfer.first_name} {golfer.last_name}{golfer.guardian_name ? ` · guardian ${golfer.guardian_name}` : ''}</Mut>
        <ScrollView style={{ maxHeight: 190, borderWidth: 1, borderColor: C.line, borderRadius: 10, padding: 10, marginVertical: 10 }} nestedScrollEnabled><Text style={{ color: C.ink }}>{terms}</Text></ScrollView>
        <Mut>Sign here with finger or stylus</Mut>
        <View style={{ height: 190, borderWidth: 2, borderStyle: 'dashed', borderColor: C.line, borderRadius: 10, overflow: 'hidden', marginTop: 4 }}>
          <Signature ref={ref} onBegin={() => setScroll(false)} onEnd={() => setScroll(true)}
            onOK={sig => onSigned(sig)} onEmpty={() => fail(new Error('Please sign in the box'))}
            webStyle={'.m-signature-pad--footer{display:none;margin:0} .m-signature-pad{box-shadow:none;border:0} body{background:#fff}'}
            backgroundColor="rgb(255,255,255)" penColor="#111" imageType="image/png" autoClear={false} />
        </View>
        <Pressable onPress={() => setAgree(!agree)} style={{ flexDirection: 'row', alignItems: 'center', gap: 10, marginVertical: 12 }}>
          <Text style={{ fontSize: 24 }}>{agree ? '☑' : '☐'}</Text><Text style={{ flex: 1, color: C.ink }}>I have read and agree to these terms</Text>
        </Pressable>
        <View style={{ gap: 8 }}>
          <Btn title="Clear signature" onPress={() => ref.current?.clearSignature()} />
          <Btn title={idx + 1 < total ? 'Next golfer →' : 'Next: cart & clubs →'} kind="primary" onPress={() => (agree ? ref.current?.readSignature() : fail(new Error('Please tick the agreement box')))} />
          <Btn title="← Back" onPress={onBack} />
        </View>
      </Card>
    </ScrollView>
  );
}

function AssetStep({ golfers, onBack, onDone }: { golfers: Golfer[]; onBack: () => void; onDone: (r: { name: string; cart?: string; clubs?: string; fee: number }) => void }) {
  const [assets, setAssets] = useState<Asset[] | null>(null);
  const [cart, setCart] = useState<number | null>(null), [clubs, setClubs] = useState<number | null>(null);
  const [holes, setHoles] = useState(18), [fee, setFee] = useState('unpaid'), [tee, setTee] = useState(''), [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const load = () => api<{ assets: Asset[] }>('assets').then(r => setAssets(r.assets)).catch(fail);
  useEffect(() => { load(); }, []);
  if (!assets) return <Loading />;
  const carts = assets.filter(a => a.kind === 'cart'), sets = assets.filter(a => a.kind === 'clubs');
  const sug = carts.find(a => a.status === 'available');
  const price = (id: number | null) => Number(assets.find(a => a.id === id)?.fee ?? 0);
  const tile = (a: Asset, sel: number | null, pick: (id: number) => void, suggest = false) => (
    <Tile key={a.id} title={a.name} sub={`${a.description ?? ''} ${money(a.fee)}${a.status !== 'available' ? ` · ${a.status.replace('_', ' ')}` : a.gps_battery != null ? ` · 🔋${a.gps_battery}%` : ''}`.trim()}
      sel={sel === a.id} off={a.status !== 'available'} sug={suggest && sug?.id === a.id && sel !== a.id}
      onPress={() => (a.status === 'available' ? pick(a.id) : fail(new Error('That one is not available')))} />
  );
  const submit = async () => {
    setBusy(true);
    try {
      const r = await api<{ hire_fee: number }>('checkins/create', { method: 'POST', body: { golfers, holes, fee_status: fee, tee_time: tee, notes, cart_id: cart || null, clubs_id: clubs || null } });
      onDone({ name: `${golfers[0].first_name} ${golfers[0].last_name}`, cart: assets.find(a => a.id === cart)?.name, clubs: assets.find(a => a.id === clubs)?.name, fee: r.hire_fee });
    } catch (e) { fail(e); load(); setBusy(false); }
  };
  return (
    <ScrollView contentContainerStyle={{ padding: 16 }} keyboardShouldPersistTaps="handled">
      <Card>
        <H>Cart & club hire</H>
        <Mut>Golf cart {sug ? `— suggested: ${sug.name}` : '— none free'}</Mut>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', marginVertical: 6 }}>
          <Tile title="Walking" sub="no cart" sel={cart === 0} onPress={() => setCart(0)} />
          {carts.map(a => tile(a, cart, setCart, true))}
        </View>
        <Mut>Club hire</Mut>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', marginVertical: 6 }}>
          <Tile title="Own clubs" sub="no hire" sel={clubs === 0} onPress={() => setClubs(0)} />
          {sets.map(a => tile(a, clubs, setClubs))}
        </View>
        <Mut>Holes</Mut>
        <View style={{ flexDirection: 'row' }}><Tile title="18" sel={holes === 18} onPress={() => setHoles(18)} /><Tile title="9" sel={holes === 9} onPress={() => setHoles(9)} /></View>
        <Mut>Green fee</Mut>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
          <Tile title="Collect at end" sel={fee === 'unpaid'} onPress={() => setFee('unpaid')} /><Tile title="Paid" sel={fee === 'paid'} onPress={() => setFee('paid')} /><Tile title="Charge to room" sel={fee === 'room'} onPress={() => setFee('room')} />
        </View>
        <Field label="Tee time (HH:MM)" value={tee} onChangeText={setTee} placeholder="08:30" keyboardType="numbers-and-punctuation" />
        <Field label="Notes" value={notes} onChangeText={setNotes} />
        <Text style={{ fontSize: 20, fontWeight: '700', color: C.ink, marginVertical: 14 }}>Hire total: {money(price(cart) + price(clubs))}</Text>
        <View style={{ gap: 8 }}>
          <Btn title={busy ? 'Saving…' : '✔ Complete check-in'} kind="primary" onPress={submit} disabled={busy} />
          <Btn title="← Back" onPress={onBack} />
        </View>
      </Card>
    </ScrollView>
  );
}
