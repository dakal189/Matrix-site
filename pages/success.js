import Link from 'next/link';

export default function Success() {
  return (
    <div className="flex flex-col items-center justify-center min-h-screen bg-gradient-to-br from-purple-800 to-black text-white p-6">
      <h1 className="text-2xl font-bold mb-4">✅ پیام شما با موفقیت ارسال شد</h1>
      <Link href="/" className="bg-green-600 p-2 rounded">بازگشت به صفحه اصلی</Link>
    </div>
  );
}
