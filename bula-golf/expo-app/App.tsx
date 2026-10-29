import React, { useEffect, useState } from 'react';
import { BackHandler, Pressable, SafeAreaView, StatusBar, Text, View } from 'react-native';
import { api, hasToken, loadSession, Me, setToken } from './src/api';
import { C, Loading, fail } from './src/ui';
import Login from './src/screens/Login';
import Home from './src/screens/Home';
import Checkin from './src/screens/Checkin';
import Returns from './src/screens/Returns';
import History from './src/screens/History';
import Dashboard from './src/screens/Dashboard';

export type Screen = 'home' | 'checkin' | 'returns' | 'history' | 'dashboard';
export type Nav = { go: (s: Screen) => void; back: () => void };
const TITLES: Record<Screen, string> = { home: '', checkin: 'Check-in', returns: 'Returns', history: "Today's check-ins", dashboard: 'Dashboard' };

export default function App() {
  const [ready, setReady] = useState(false);
  const [me, setMe] = useState<Me | null>(null);
  const [screen, setScreen] = useState<Screen>('home');
  useEffect(() => {
    (async () => {
      await loadSession();
      if (hasToken()) { try { setMe((await api<{ user: Me | null }>('auth/me')).user); } catch {} }
      setReady(true);
    })();
  }, []);
  const nav: Nav = { go: setScreen, back: () => setScreen('home') };
  useEffect(() => {
    const h = BackHandler.addEventListener('hardwareBackPress', () => (screen !== 'home' ? (setScreen('home'), true) : false));
    return () => h.remove();
  }, [screen]);
  const logout = async () => { try { await api('auth/logout', { method: 'POST' }); } catch (e) { fail(e); } await setToken(''); setMe(null); setScreen('home'); };

  if (!ready) return <Loading />;
  if (!me) return <><StatusBar barStyle="dark-content" /><SafeAreaView style={{ flex: 1, backgroundColor: C.bg }}><Login onDone={m => { setMe(m); setScreen('home'); }} /></SafeAreaView></>;
  const mgr = me.role !== 'staff'; // admin, supervisor (and the old manager)
  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: C.bg }}>
      <StatusBar barStyle="light-content" />
      <View style={{ backgroundColor: C.brand, paddingHorizontal: 16, paddingVertical: 12, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
        <Text style={{ color: '#fff', fontSize: 19, fontWeight: '700' }}>⛳ Bula Golf{TITLES[screen] ? ` · ${TITLES[screen]}` : ''}</Text>
        <View style={{ flexDirection: 'row', gap: 14 }}>
          {screen !== 'home' && <Pressable onPress={nav.back}><Text style={{ color: '#fff', fontSize: 15 }}>← Home</Text></Pressable>}
          <Pressable onPress={logout}><Text style={{ color: '#fff', fontSize: 15 }}>{me.name} · Sign out</Text></Pressable>
        </View>
      </View>
      {screen === 'home' && <Home nav={nav} manager={mgr} />}
      {screen === 'checkin' && <Checkin nav={nav} />}
      {screen === 'returns' && <Returns />}
      {screen === 'history' && <History manager={mgr} />}
      {screen === 'dashboard' && mgr && <Dashboard />}
    </SafeAreaView>
  );
}
