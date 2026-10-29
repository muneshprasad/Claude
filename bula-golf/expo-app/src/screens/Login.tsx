import React, { useState } from 'react';
import { KeyboardAvoidingView, ScrollView, Text } from 'react-native';
import { api, getBase, Me, setBase, setToken } from '../api';
import { Btn, Card, C, Field, H, Mut, fail } from '../ui';

export default function Login({ onDone }: { onDone: (m: Me) => void }) {
  const [url, setUrl] = useState(getBase()), [u, setU] = useState(''), [p, setP] = useState(''), [busy, setBusy] = useState(false);
  const go = async () => {
    setBusy(true);
    try {
      await setBase(url);
      const r = await api<{ user: Me; token: string }>('auth/login', { method: 'POST', body: { username: u, password: p, app: true } });
      await setToken(r.token);
      onDone(r.user);
    } catch (e) { fail(e); } finally { setBusy(false); }
  };
  return (
    <KeyboardAvoidingView behavior="padding" style={{ flex: 1, backgroundColor: C.bg }}>
      <ScrollView contentContainerStyle={{ padding: 20, maxWidth: 480, width: '100%', alignSelf: 'center' }} keyboardShouldPersistTaps="handled">
        <Text style={{ fontSize: 28, fontWeight: '800', color: C.brand, marginVertical: 24 }}>⛳ Bula Golf</Text>
        <Card>
          <H>Staff sign in</H>
          <Field label="Server address" value={url} onChangeText={setUrl} autoCapitalize="none" autoCorrect={false} keyboardType="url" placeholder="https://yourdomain.com/golf" />
          <Mut>The address where the Bula Golf web app is installed.</Mut>
          <Field label="Username" value={u} onChangeText={setU} autoCapitalize="none" autoCorrect={false} />
          <Field label="Password" value={p} onChangeText={setP} secureTextEntry onSubmitEditing={go} />
          <Btn title={busy ? 'Signing in…' : 'Sign in'} kind="primary" onPress={go} disabled={busy} style={{ marginTop: 16 }} />
        </Card>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
