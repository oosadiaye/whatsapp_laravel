// A tiny SMTP server that accepts every message and keeps it, so the end-to-end run can prove what was
// actually emailed (and to whom) without a real mail provider.
//
//   SMTP:  127.0.0.1:2525      (point a connected mailbox at it: host 127.0.0.1, port 2525, no encryption)
//   HTTP:  GET  127.0.0.1:9102/messages   -> every message received, newest last
//          POST 127.0.0.1:9102/reset      -> forget them
const net = require('net');
const http = require('http');

const messages = [];

net.createServer((socket) => {
  let from = ''; let to = []; let data = ''; let inData = false; let buffer = '';
  const reply = (line) => socket.write(line + '\r\n');
  reply('220 smtp-sink ready');

  socket.on('data', (chunk) => {
    buffer += chunk.toString('utf8');
    let index;
    while ((index = buffer.indexOf('\r\n')) !== -1) {
      const line = buffer.slice(0, index);
      buffer = buffer.slice(index + 2);

      if (inData) {
        if (line === '.') {
          inData = false;
          const subject = (data.match(/^Subject: (.*)$/mi) || [])[1] || '';
          messages.push({ from, to, subject, raw: data, at: new Date().toISOString() });
          data = ''; reply('250 queued');
        } else {
          data += (line.startsWith('..') ? line.slice(1) : line) + '\n';
        }
        continue;
      }

      const upper = line.toUpperCase();
      if (upper.startsWith('EHLO') || upper.startsWith('HELO')) reply('250 smtp-sink');
      else if (upper.startsWith('MAIL FROM')) { from = (line.match(/<([^>]*)>/) || [])[1] || ''; to = []; reply('250 ok'); }
      else if (upper.startsWith('RCPT TO')) { to.push((line.match(/<([^>]*)>/) || [])[1] || ''); reply('250 ok'); }
      else if (upper === 'DATA') { inData = true; reply('354 go ahead'); }
      else if (upper === 'RSET') { from = ''; to = []; reply('250 ok'); }
      else if (upper === 'QUIT') { reply('221 bye'); socket.end(); }
      else reply('250 ok');
    }
  });
  socket.on('error', () => {});
}).listen(2525, '127.0.0.1', () => console.log('smtp sink on 2525'));

http.createServer((req, res) => {
  if (req.url === '/reset' && req.method === 'POST') { messages.length = 0; res.end('ok'); return; }
  res.setHeader('Content-Type', 'application/json');
  res.end(JSON.stringify(messages));
}).listen(9102, '127.0.0.1');
