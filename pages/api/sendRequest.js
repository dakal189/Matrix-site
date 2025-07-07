export default async function handler(req, res) {
  if (req.method === 'POST') {
    const { username, nameGame, idTelegram, level, wbook } = req.body;
    const botToken = '6560518948:AAHzSL0lDpoJMY6SH9kZKqaLPOTVNgp4PyE';
    const chatId = '-1001904437342';
    const message = `🟩 درخواست عضویت جدید:
👤 UserName: ${username}
🎮 Name Game: ${nameGame}
🆔 ID Telegram: ${idTelegram}
⭐ Level: ${level}
📖 Wbook: ${wbook}`;

    try {
      await fetch(`https://api.telegram.org/bot${botToken}/sendMessage`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          chat_id: chatId,
          text: message
        })
      });
      res.status(200).json({ ok: true });
    } catch (error) {
      res.status(500).json({ ok: false, error: error.message });
    }
  } else {
    res.status(405).json({ message: 'Method Not Allowed' });
  }
}
