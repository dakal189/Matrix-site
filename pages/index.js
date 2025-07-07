import Link from 'next/link';

export default function Home() {
  return (
    <div className="flex flex-col items-center justify-center min-h-screen bg-gray-950 text-white p-6">
      <h1 className="text-3xl font-bold mb-6">Invite Matrix Family</h1>
      <Link href="/register" className="bg-green-600 px-4 py-2 rounded mb-3">ثبت نام</Link>
      <Link href="/login" className="bg-blue-600 px-4 py-2 rounded mb-3">ورود</Link>
      <Link href="/request" className="bg-purple-600 px-4 py-2 rounded">ارسال درخواست عضویت</Link>
    </div>
  )
}
