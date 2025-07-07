import { useState, useEffect } from 'react';
import axios from 'axios';
import { useRouter } from 'next/router';

export default function Request() {
  const router = useRouter();
  const [nameGame, setNameGame] = useState('');
  const [idTelegram, setIdTelegram] = useState('');
  const [level, setLevel] = useState('');
  const [wbook, setWbook] = useState('');
  const [username, setUsername] = useState('');

  useEffect(() => {
    setUsername(localStorage.getItem('username') || '');
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    try {
      await axios.post('/api/sendRequest', { username, nameGame, idTelegram, level, wbook });
      router.push('/success');
    } catch (error) {
      alert('خطا در ارسال درخواست');
    }
  };

  return (
    <div className="flex flex-col items-center justify-center min-h-screen bg-gray-950 text-white p-6">
      <h1 className="text-xl font-bold mb-4">Invite Matrix Family</h1>
      <form onSubmit={handleSubmit} className="flex flex-col w-72 space-y-3">
        <input value={username} readOnly className="p-2 rounded bg-gray-800" />
        <input placeholder="Name Game" className="p-2 rounded bg-gray-800" value={nameGame} onChange={e => setNameGame(e.target.value)} required />
        <input placeholder="ID Telegram" className="p-2 rounded bg-gray-800" value={idTelegram} onChange={e => setIdTelegram(e.target.value)} required />
        <input placeholder="Level" className="p-2 rounded bg-gray-800" value={level} onChange={e => setLevel(e.target.value)} required />
        <textarea placeholder="Wbook" className="p-2 rounded bg-gray-800 h-32" value={wbook} onChange={e => setWbook(e.target.value)} required />
        <button type="submit" className="bg-purple-600 p-2 rounded">ارسال درخواست</button>
      </form>
    </div>
  );
}
