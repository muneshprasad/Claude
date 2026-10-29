import React from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, TextInput, TextInputProps, View, ViewStyle } from 'react-native';

export const C = { bg: '#f4f7f4', card: '#fff', ink: '#16301f', mut: '#5b6e61', line: '#d5e0d7', brand: '#1b6b3a', brand2: '#e8f3ea', warn: '#b3261e', amb: '#a15c00' };
export const money = (n: number | string, cur = 'FJD') => `${cur} ${Number(n || 0).toFixed(2)}`;
export const hm = (s?: string | null) => (s ? s.slice(11, 16) : '');
export const today = () => new Date().toLocaleDateString('en-CA');
export const fail = (e: unknown) => Alert.alert('Oops', e instanceof Error ? e.message : String(e));

export function Btn({ title, onPress, kind = 'plain', disabled, style }: { title: string; onPress: () => void; kind?: 'plain' | 'primary' | 'danger'; disabled?: boolean; style?: ViewStyle }) {
  const p = kind === 'primary', d = kind === 'danger';
  return (
    <Pressable onPress={onPress} disabled={disabled} style={[s.btn, p && { backgroundColor: C.brand }, d && { borderColor: C.warn }, disabled && { opacity: 0.45 }, style]}>
      <Text style={[s.btnT, p && { color: '#fff' }, d && { color: C.warn }]}>{title}</Text>
    </Pressable>
  );
}
export const Big = ({ title, sub, onPress, alt }: { title: string; sub?: string; onPress: () => void; alt?: boolean }) => (
  <Pressable onPress={onPress} style={[s.big, alt && { backgroundColor: C.card, borderWidth: 2, borderColor: C.brand }]}>
    <Text style={[s.bigT, alt && { color: C.brand }]}>{title}</Text>
    {sub ? <Text style={[s.bigS, alt && { color: C.mut }]}>{sub}</Text> : null}
  </Pressable>
);
export const Card = ({ children, style }: { children: React.ReactNode; style?: ViewStyle }) => <View style={[s.card, style]}>{children}</View>;
export const H = ({ children }: { children: React.ReactNode }) => <Text style={s.h}>{children}</Text>;
export const Mut = ({ children }: { children: React.ReactNode }) => <Text style={s.mut}>{children}</Text>;
export const Field = ({ label, ...p }: TextInputProps & { label: string }) => (
  <View style={{ marginTop: 10 }}>
    <Text style={s.lab}>{label}</Text>
    <TextInput placeholderTextColor="#9bb0a1" {...p} style={[s.input, p.style]} />
  </View>
);
export const Loading = () => <ActivityIndicator size="large" color={C.brand} style={{ marginTop: 40 }} />;
export const Tile = ({ title, sub, sel, off, sug, onPress }: { title: string; sub?: string; sel?: boolean; off?: boolean; sug?: boolean; onPress: () => void }) => (
  <Pressable onPress={onPress} style={[s.tile, sel && { borderColor: C.brand, backgroundColor: C.brand2 }, sug && { borderStyle: 'dashed', borderColor: C.brand }, off && { opacity: 0.4 }]}>
    <Text style={[s.tileT, sel && { fontWeight: '700' }]}>{title}</Text>
    {sub ? <Text style={s.tileS}>{sub}</Text> : null}
  </Pressable>
);

const s = StyleSheet.create({
  btn: { borderWidth: 1, borderColor: C.brand, borderRadius: 10, paddingVertical: 13, paddingHorizontal: 16, minHeight: 46, alignItems: 'center', justifyContent: 'center', backgroundColor: C.card },
  btnT: { color: C.brand, fontSize: 17 },
  big: { backgroundColor: C.brand, borderRadius: 14, padding: 20, marginBottom: 12 },
  bigT: { color: '#fff', fontSize: 21, fontWeight: '600' }, bigS: { color: '#dff', fontSize: 14, marginTop: 2 },
  card: { backgroundColor: C.card, borderWidth: 1, borderColor: C.line, borderRadius: 12, padding: 14, marginBottom: 14 },
  h: { fontSize: 20, fontWeight: '700', color: C.ink, marginBottom: 6 },
  mut: { color: C.mut, fontSize: 13 },
  lab: { color: C.mut, fontSize: 13, marginBottom: 3 },
  input: { borderWidth: 1, borderColor: C.line, borderRadius: 10, padding: 12, fontSize: 17, backgroundColor: C.bg, color: C.ink },
  tile: { width: 108, margin: 4, padding: 10, borderWidth: 2, borderColor: C.line, borderRadius: 10, backgroundColor: C.bg, alignItems: 'center' },
  tileT: { color: C.ink, fontSize: 15 }, tileS: { color: C.mut, fontSize: 11, textAlign: 'center' },
});
