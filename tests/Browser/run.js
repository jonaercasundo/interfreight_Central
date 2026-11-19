import { spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { existsSync, mkdirSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'

const database = path.join(tmpdir(), `ifc-phase17-browser-${process.pid}.sqlite`)
const sessions = path.join(tmpdir(), `ifc-phase17-browser-sessions-${process.pid}`)
const environment = {
  ...process.env, APP_ENV: 'testing', IFC_E2E: '1', APP_DEBUG: 'false',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  APP_URL: 'http://127.0.0.1:18717', DB_CONNECTION: 'sqlite', DB_DATABASE: database,
  IFC_E2E_DATABASE: database, IFC_E2E_SESSIONS: sessions, CACHE_STORE: 'array', SESSION_DRIVER: 'file', SESSION_FILES: sessions,
  QUEUE_CONNECTION: 'sync', IFC_E2E_PORT: '18717',
}

function run(command, args) {
  const result = spawnSync(command, args, { cwd: process.cwd(), env: environment, stdio: 'inherit', shell: process.platform === 'win32' })
  if (result.error) throw result.error
  return result.status || 0
}

if (existsSync(database)) {
  throw new Error('Refusing to reuse an existing phase-17 browser-test database file.')
}
if (existsSync(sessions)) {
  throw new Error('Refusing to reuse an existing phase-17 browser-test session directory.')
}
mkdirSync(sessions)

let status = 1
const ownsDatabasePath = true
try {
  status = run('php', ['tests/Browser/prepare.php'])
  if (status === 0) status = run('npx', ['playwright', 'test', '--config=playwright.config.js', ...process.argv.slice(2)])
} finally {
  if (ownsDatabasePath) {
    for (const suffix of ['', '-journal', '-wal', '-shm']) rmSync(`${database}${suffix}`, { force: true })
    rmSync(sessions, { recursive: true, force: true })
  }
}
process.exit(status)
