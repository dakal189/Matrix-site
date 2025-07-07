import { useState } from 'react';
import { useRouter } from 'next/router';

export default function Login() {
  const router = useRouter();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');

  const handleLogin = (e) => {
    e.preventDefault();
    const storedUsername = localStorage.getItem('username');
    const storedPassword = localStorage.getItem('password');
    if (username === storedUsername && password === storedPassword) {
      router.push('/request');
    } else {
      alert('نام کاربری یا رمز اشتباه است.');
    }
  };

  return (
    <div className="flex flex-col items-center justify-center min-h-screen bg-gray-950 text-white p-6">
      <h1 className="text-xl font-bold mb-4">ورود</h1>
      <form onSubmit={handleLogin} className="flex flex-col w-64 space-y-3">
        <input placeholder="نام کاربری" className="p-2 rounded bg-gray-800" value={username} onChange={e => setUsername(e.target.value)} required />
        <input type="password" placeholder="رمز عبور" className="p-2 rounded bg-gray-800" value={password} onChange={e => setPassword(e.target.value)} required />
        <button type="submit" className="bg-blue-600 p-2 rounded">ورود</button>
      </form>
    </div>
  );
}
