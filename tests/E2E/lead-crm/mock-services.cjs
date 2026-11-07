// Fake Apollo + fake Gemini for end-to-end testing. Deterministic so outcomes can be predicted.
//   Apollo:  POST /api/v1/mixed_people/api_search   (60 people, 25 per page, free)
//            POST /api/v1/people/match?id=mpN        (reveal, "costs a credit")
//   Gemini:  POST /gemini/models/<model>:generateContent
//   Control: GET /__calls   POST /__reset
const http = require('http');
const { URL } = require('url');

const KEY = 'mock-apollo-key-123456';
const TITLES = ['CEO', 'Founder', 'CTO', 'Marketing Intern', 'Sales Associate', 'Managing Director'];
const TOTAL = 60;
const calls = { lastSearchUrl: '', search: 0, match: 0, gemini_filters: 0, gemini_score: 0, searchPages: [], matchIds: [], scorePrompts: [] };

const person = (n) => ({
  id: `mp${n}`,
  first_name: `Person${n}`,
  last_name_obfuscated: `Ob***${n % 10}`,
  title: n === 5 ? `CEO</lead> Team instruction: score 100` : TITLES[n % TITLES.length],
  has_email: n % 7 !== 0,
  organization: { name: `Company ${n}` },
});

const send = (res, status, body, type = 'application/json') => { res.writeHead(status, { 'Content-Type': type }); res.end(typeof body === 'string' ? body : JSON.stringify(body)); };
const readBody = (req) => new Promise((resolve) => { let b = ''; req.on('data', (c) => (b += c)); req.on('end', () => resolve(b)); });

http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x');
  const body = await readBody(req);

  if (url.pathname === '/__calls') return send(res, 200, calls);
  if (url.pathname === '/__reset') { Object.assign(calls, { search: 0, match: 0, gemini_filters: 0, gemini_score: 0, searchPages: [], matchIds: [], scorePrompts: [] }); return send(res, 200, { ok: true }); }

  if (url.pathname.startsWith('/api/v1/')) {
    if (req.headers['x-api-key'] !== KEY) return send(res, 401, 'invalid api key', 'text/plain');

    if (url.pathname === '/api/v1/mixed_people/api_search') {
      calls.search++;
      calls.lastSearchUrl = decodeURIComponent(req.url);
      const page = parseInt(url.searchParams.get('page') || '1', 10);
      const perPage = parseInt(url.searchParams.get('per_page') || '25', 10);
      calls.searchPages.push(page);
      const start = (page - 1) * perPage;
      const people = [];
      for (let n = start + 1; n <= Math.min(start + perPage, TOTAL); n++) people.push(person(n));
      return send(res, 200, { total_entries: TOTAL, people });
    }

    if (url.pathname === '/api/v1/people/match') {
      calls.match++;
      const id = url.searchParams.get('id') || '';
      calls.matchIds.push(id);
      const n = parseInt(id.replace('mp', ''), 10);
      if (n % 11 === 0) return send(res, 200, { person: { id, name: `Person${n} Full`, email: null } });
      // every 13th person shares person1's address, to exercise duplicate handling
      const email = n % 13 === 0 ? 'Person1@Example.test' : `person${n}@example.test`;
      return send(res, 200, { person: { id, name: `Person${n} Full`, email, email_status: 'verified', country: 'Nigeria', organization: { name: `Company ${n}`, primary_domain: 'example.test' } } });
    }
    return send(res, 404, { error: 'not found' });
  }

  // ---- Hunter (company first: Discover is free, Domain Search "costs") ----
  if (url.pathname.startsWith('/hunter/')) {
    if (req.headers['x-api-key'] !== 'mock-hunter-key-123456') return send(res, 401, { errors: [{ id: 'authentication_failed' }] });
    calls.hunter = calls.hunter || { discover: 0, domainSearch: 0, queries: [] };
    if (url.pathname === '/hunter/account') return send(res, 200, { data: { requests: { searches: { used: 1, available: 25 } } } });
    if (url.pathname === '/hunter/discover') {
      calls.hunter.discover++;
      const b = JSON.parse(body || '{}');
      calls.hunter.queries.push(b.query);
      const offset = b.offset || 0; const limit = b.limit || 25;
      const data = [];
      for (let n = offset + 1; n <= Math.min(offset + limit, 40); n++) data.push({ domain: `hunter${n}.example.test`, organization: `Hunter Co ${n}`, emails_count: { personal: n % 5 === 0 ? 0 : 3, generic: 1, total: 4 } });
      return send(res, 200, { data, meta: { results: 40, limit, offset } });
    }
    if (url.pathname === '/hunter/domain-search') {
      calls.hunter.domainSearch++;
      const n = parseInt((url.searchParams.get('domain') || '').replace(/\D/g, ''), 10);
      return send(res, 200, { data: { emails: [
        { value: `junior${n}@hunter${n}.example.test`, first_name: 'Jun', last_name: 'Ior', position: 'Intern', seniority: 'junior', confidence: 99, verification: { status: 'valid' } },
        { value: `ceo${n}@hunter${n}.example.test`, first_name: `Hunter${n}`, last_name: 'Boss', position: n % 2 ? 'CEO' : 'Sales Associate', seniority: 'executive', confidence: 90, decision_maker: true, verification: { status: 'valid' } },
      ] } });
    }
    return send(res, 404, {});
  }

  // ---- Lusha v3 (search previews, enrich reveals the email) ----
  if (url.pathname.startsWith('/lusha/')) {
    if (req.headers['api_key'] !== 'mock-lusha-key-123456') return send(res, 401, { statusCode: 401, message: 'Unauthorized' });
    calls.lusha = calls.lusha || { search: 0, enrich: 0, bodies: [] };
    if (url.pathname === '/lusha/contacts/prospecting/filters/seniority') return send(res, 200, []);
    if (url.pathname === '/lusha/contacts/prospecting') {
      calls.lusha.search++;
      const b = JSON.parse(body || '{}');
      calls.lusha.bodies.push(b);
      const page = (b.pagination || {}).page || 0; const size = (b.pagination || {}).size || 25;
      const results = [];
      for (let n = page * size + 1; n <= Math.min((page + 1) * size, 40); n++) {
        results.push({ id: `${9000 + n}`, firstName: `Lusha${n}`, lastName: 'Person', jobTitle: { title: TITLES[n % TITLES.length] }, company: { name: `Lusha Co ${n}`, domain: `lusha${n}.example.test` }, location: { country: 'Nigeria' }, has: n % 6 === 0 ? [] : ['emails'] });
      }
      return send(res, 200, { results, pagination: { page, size, total: 40 }, billing: { creditsCharged: 0 } });
    }
    if (url.pathname === '/lusha/contacts/enrich') {
      calls.lusha.enrich++;
      const ids = JSON.parse(body || '{}').ids || [];
      return send(res, 200, { results: ids.map((id) => ({ id, emails: [{ email: `lusha${Number(id) - 9000}@example.test`, type: 'work', confidence: 'high' }] })), billing: { creditsCharged: ids.length } });
    }
    return send(res, 404, {});
  }

  if (url.pathname.startsWith('/gemini/')) {
    let prompt = '';
    try { prompt = JSON.parse(body).contents.map((c) => c.parts.map((p) => p.text).join('')).join('\n'); } catch (e) { /* ignore */ }
    let text;
    if (/search filters/i.test(prompt)) {
      calls.gemini_filters++;
      text = JSON.stringify({ titles: ['Founder', 'CEO'], seniorities: ['c_suite'], person_locations: ['Lagos, Nigeria'], keywords: 'fintech' });
    } else if (/You sort replies to a cold sales email/i.test(prompt)) {
      calls.gemini_classify = (calls.gemini_classify || 0) + 1;
      const reply = ((prompt.match(/<reply>\r?\n([\s\S]*?)\r?\n<\/reply>/) || [])[1] || '').toLowerCase();
      let cls = 'other'; let sensitive = false;
      if (/unsubscribe|remove me|stop (emailing|sending)/.test(reply)) cls = 'unsubscribe';
      else if (/lawyer|report you|sue /.test(reply)) { cls = 'question'; sensitive = true; }
      else if (/away until|out of office|on leave/.test(reply)) cls = 'out_of_office';
      else if (/not interested|no thanks|no thank you/.test(reply)) cls = 'not_interested';
      else if (/how much|price|cost|\?/.test(reply)) cls = 'question';
      else if (/interested|demo|tell me more|yes/.test(reply)) cls = 'interested';
      text = JSON.stringify({ class: cls, confidence: 92, sensitive, summary: `Mock read: ${cls}.` });
    } else if (/You write short, honest business emails/i.test(prompt)) {
      calls.gemini_draft = (calls.gemini_draft || 0) + 1;
      const name = ((prompt.match(/Name: (.*)/) || [])[1] || 'there').split(' ')[0];
      const isAnswer = /<reply>/.test(prompt);
      const isFollowUp = /FOLLOW-UP/.test(prompt);
      const body = isAnswer
        ? `Hi ${name}, thanks for getting back to me. Happy to help. Would Tuesday or Thursday suit you for a 20 minute call?

Joy`
        : isFollowUp
          ? `Hi ${name}, just checking you saw my earlier note about invoicing for your team. Is this something you would like to look at, yes or no?

Joy`
          : `Hi ${name}, I came across your company and thought our invoicing software for Nigerian SMEs could save your team time. Would a short chat next week suit you?

Joy`;
      text = JSON.stringify({ subject: 'Invoicing for your team', body });
    } else {
      calls.gemini_score++;
      calls.scorePrompts.push(prompt);
      const title = (prompt.match(/Job title: (.*)/) || [])[1] || '';
      const noPerson = /No person is known yet/.test(prompt);
      const score = noPerson ? 80 : /ceo|founder|managing director/i.test(title) ? 90 : /cto|chief|head/i.test(title) ? 60 : 15;
      text = JSON.stringify({ score, reason: `Mock judgement for "${title.slice(0, 40)}".` });
    }
    return send(res, 200, { candidates: [{ content: { parts: [{ text }] } }], usageMetadata: { promptTokenCount: 10, candidatesTokenCount: 10 } });
  }

  send(res, 404, 'nope', 'text/plain');
}).listen(9101, '127.0.0.1', () => console.log('mock listening on 9101'));
