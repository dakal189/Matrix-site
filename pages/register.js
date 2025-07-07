import { useState } from 'react';
import { useRouter } from 'next/router';

export default function Register() {
  const router = useRouter();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');

  const handleRegister = (e) => {
    e.preventDefault();
    localStorage.setItem('username', username);
    localStorage.setItem('password', password);
    router.push('/request');
  };

  return (
    <div className="flex flex-col items-center justify-center min-h-screen bg-gray-950 text-white p-6">
      <h1 className="text-xl font-bold mb-4">ثبت نام</h1>
      <form onSubmit={handleRegister} className="flex flex-col w-64 space-y-3">
        <input placeholder="نام کاربری" className="p-2 rounded bg-gray-800" value={username} onChange={e => setUsername(e.target.value)} required />
        <input type="password" placeholder="رمز عبور" className="p-2 rounded bg-gray-800" value={password} onChange={e => setPassword(e.target.value)} required />
        <button type="submit" className="bg-green-600 p-2 rounded">ثبت نام</button>
      </form>
    </div>
  );
}
