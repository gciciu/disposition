import http from 'node:http'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createHmac, timingSafeEqual } from 'node:crypto'
import { WebSocketServer } from 'ws'

// Load realtime/.env if present (does not override existing process env).
const envPath = path.join(path.dirname(fileURLToPath(import.meta.url)), '.env')
if (fs.existsSync(envPath)) {
  for (const line of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
    const trimmed = line.trim()
    if (!trimmed || trimmed.startsWith('#')) {
      continue
    }
    const eq = trimmed.indexOf('=')
    if (eq <= 0) {
      continue
    }
    const key = trimmed.slice(0, eq).trim()
    let value = trimmed.slice(eq + 1).trim()
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1)
    }
    if (process.env[key] === undefined) {
      process.env[key] = value
    }
  }
}

const PORT = Number(process.env.REALTIME_PORT || 8090)
const SECRET = process.env.REALTIME_SECRET || 'dev-realtime-secret-change-me'

/** @type {Map<string, Set<import('ws').WebSocket>>} */
const channelSubscribers = new Map()

function json(res, status, body) {
  const payload = JSON.stringify(body)
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(payload),
  })
  res.end(payload)
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = []
    req.on('data', (chunk) => chunks.push(chunk))
    req.on('end', () => {
      resolve(Buffer.concat(chunks).toString('utf8'))
    })
    req.on('error', reject)
  })
}

function verifyPublishSecret(req) {
  const header = req.headers['x-realtime-secret']
  if (typeof header !== 'string' || header.length === 0) {
    return false
  }
  const expected = Buffer.from(SECRET)
  const actual = Buffer.from(header)
  if (expected.length !== actual.length) {
    return false
  }
  return timingSafeEqual(expected, actual)
}

/**
 * Ticket format: base64url(payloadJson).base64url(hmacSha256)
 * payload: { exp: unixSeconds, channels: string[] }
 */
function verifyTicket(ticket) {
  if (typeof ticket !== 'string' || !ticket.includes('.')) {
    return null
  }

  const [payloadB64, sigB64] = ticket.split('.', 2)
  if (!payloadB64 || !sigB64) {
    return null
  }

  const expectedSig = createHmac('sha256', SECRET).update(payloadB64).digest()
  let actualSig
  try {
    actualSig = Buffer.from(sigB64, 'base64url')
  } catch {
    return null
  }

  if (expectedSig.length !== actualSig.length || !timingSafeEqual(expectedSig, actualSig)) {
    return null
  }

  let payload
  try {
    payload = JSON.parse(Buffer.from(payloadB64, 'base64url').toString('utf8'))
  } catch {
    return null
  }

  if (!payload || typeof payload !== 'object') {
    return null
  }

  const exp = Number(payload.exp)
  if (!Number.isFinite(exp) || exp * 1000 < Date.now()) {
    return null
  }

  const channels = Array.isArray(payload.channels)
    ? payload.channels.filter((c) => typeof c === 'string' && c.length > 0)
    : []

  if (channels.length === 0) {
    return null
  }

  return { channels }
}

function subscribe(ws, channels) {
  ws.__channels = channels
  for (const channel of channels) {
    let set = channelSubscribers.get(channel)
    if (!set) {
      set = new Set()
      channelSubscribers.set(channel, set)
    }
    set.add(ws)
  }
}

function unsubscribe(ws) {
  const channels = ws.__channels
  if (!Array.isArray(channels)) {
    return
  }
  for (const channel of channels) {
    const set = channelSubscribers.get(channel)
    if (!set) {
      continue
    }
    set.delete(ws)
    if (set.size === 0) {
      channelSubscribers.delete(channel)
    }
  }
  ws.__channels = []
}

function publish(channels, event, data) {
  const message = JSON.stringify({ event, data, channels })
  let delivered = 0
  for (const channel of channels) {
    const set = channelSubscribers.get(channel)
    if (!set) {
      continue
    }
    for (const client of set) {
      if (client.readyState === 1) {
        client.send(message)
        delivered += 1
      }
    }
  }
  return delivered
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url || '/', `http://${req.headers.host || '127.0.0.1'}`)

  if (req.method === 'GET' && url.pathname === '/health') {
    return json(res, 200, { ok: true })
  }

  if (req.method === 'POST' && url.pathname === '/publish') {
    if (!verifyPublishSecret(req)) {
      return json(res, 401, { error: 'Unauthorized' })
    }

    let body
    try {
      const raw = await readBody(req)
      body = raw ? JSON.parse(raw) : {}
    } catch {
      return json(res, 400, { error: 'Invalid JSON' })
    }

    const channels = Array.isArray(body.channels)
      ? body.channels.filter((c) => typeof c === 'string' && c.length > 0)
      : []
    const event = typeof body.event === 'string' ? body.event : ''
    const data = body.data ?? null

    if (channels.length === 0 || event === '') {
      return json(res, 400, { error: 'channels and event are required' })
    }

    const delivered = publish(channels, event, data)
    return json(res, 200, { ok: true, delivered })
  }

  json(res, 404, { error: 'Not found' })
})

const wss = new WebSocketServer({ noServer: true })

server.on('upgrade', (req, socket, head) => {
  const url = new URL(req.url || '/', `http://${req.headers.host || '127.0.0.1'}`)
  if (url.pathname !== '/ws') {
    socket.destroy()
    return
  }

  const ticket = url.searchParams.get('ticket')
  const verified = verifyTicket(ticket)
  if (!verified) {
    socket.write('HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n')
    socket.destroy()
    return
  }

  wss.handleUpgrade(req, socket, head, (ws) => {
    subscribe(ws, verified.channels)
    ws.send(JSON.stringify({ event: 'realtime.connected', data: { channels: verified.channels } }))

    ws.on('close', () => unsubscribe(ws))
    ws.on('error', () => unsubscribe(ws))
  })
})

server.listen(PORT, '127.0.0.1', () => {
  console.log(`[realtime] listening on http://127.0.0.1:${PORT} (ws://127.0.0.1:${PORT}/ws)`)
})
