const STORAGE_PREFIX = 'ifc:business-action-sync:'

export const ACTION_MODULES = Object.freeze({
  'sales_inquiry.created': ['sales-inquiries', 'sales', 'sales-dashboard', 'sales-clients', 'sales-calendar', 'sales-monthly', 'inquiry-selectors'],
  'sales_inquiry.updated': ['sales-inquiries', 'sales', 'sales-dashboard', 'sales-clients', 'sales-calendar', 'sales-monthly', 'inquiry-selectors'],
  'shipment_forecast.created': ['sales-inquiries', 'sales', 'sales-dashboard', 'sales-calendar', 'sales-monthly', 'sales-history', 'accounting-forecasts'],
  'client.created': ['sales-clients', 'sales', 'sales-dashboard', 'inquiry-selectors'],
  'client.updated': ['sales-clients', 'sales', 'sales-dashboard', 'inquiry-selectors'],
  'shipment.created': ['shipments'],
  'shipment.updated': ['shipments'],
  'expense_draft.created': ['expenses'],
})

function storageKey(userId) {
  return `${STORAGE_PREFIX}${userId || 'user'}`
}

function readEvents(userId) {
  try {
    const events = JSON.parse(localStorage.getItem(storageKey(userId)) || '[]')
    return Array.isArray(events) ? events : []
  } catch {
    return []
  }
}

function writeEvents(userId, events) {
  try { localStorage.setItem(storageKey(userId), JSON.stringify(events)) } catch { /* In-page event delivery still works when browser storage is unavailable. */ }
}

export function normalizeActionResults(results) {
  if (!Array.isArray(results)) return []
  return results.filter(result => result
    && typeof result.action === 'string'
    && Object.hasOwn(ACTION_MODULES, result.action)
    && result.operation_status === 'success'
    && Number.isInteger(Number(result.entity_id))
    && Number(result.entity_id) > 0
    && typeof result.reference_number === 'string'
    && result.reference_number.length > 0)
}

export function publishBusinessActions(results, userId) {
  const actions = normalizeActionResults(results)
  if (!actions.length || typeof window === 'undefined') return null
  const modules = [...new Set(actions.flatMap(action => ACTION_MODULES[action.action]))]
  const event = {
    id: crypto.randomUUID(),
    user_id: String(userId || 'user'),
    created_at: new Date().toISOString(),
    actions,
    stale_modules: modules,
    acknowledged_modules: [],
  }
  const events = [...readEvents(userId), event].slice(-20)
  writeEvents(userId, events)
  window.dispatchEvent(new CustomEvent('ifc:business-actions', { detail: event }))
  return event
}

export function pendingBusinessActions(userId, moduleName) {
  return readEvents(userId).filter(event => event.stale_modules?.includes(moduleName)
    && !event.acknowledged_modules?.includes(moduleName))
}

export function acknowledgeBusinessAction(eventId, userId, moduleName) {
  const events = readEvents(userId).map(event => event.id !== eventId
    ? event
    : { ...event, acknowledged_modules: [...new Set([...(event.acknowledged_modules || []), moduleName])] })
  writeEvents(userId, events)
}

export function listenForBusinessActions(userId, moduleName, handler) {
  const onAction = async event => {
    const detail = event.detail
    if (!detail || String(detail.user_id) !== String(userId || 'user') || !detail.stale_modules?.includes(moduleName)) return
    try {
      await handler(detail)
      acknowledgeBusinessAction(detail.id, userId, moduleName)
    } catch {
      // Keep it pending so the next visit can retry the refresh.
    }
  }
  const onStorage = event => {
    if (event.key !== storageKey(userId) || !event.newValue) return
    let records = []
    try { records = JSON.parse(event.newValue) } catch { return }
    const pending = records.find(record => record.stale_modules?.includes(moduleName) && !record.acknowledged_modules?.includes(moduleName))
    if (pending) void onAction({ detail: pending })
  }
  window.addEventListener('ifc:business-actions', onAction)
  window.addEventListener('storage', onStorage)
  return () => {
    window.removeEventListener('ifc:business-actions', onAction)
    window.removeEventListener('storage', onStorage)
  }
}
