import test from 'node:test'
import assert from 'node:assert/strict'
import {
  acknowledgeBusinessAction,
  listenForBusinessActions,
  pendingBusinessActions,
  publishBusinessActions,
} from '../../resources/js/businessActionSync.js'

function installBrowserMocks() {
  const values = new Map()
  globalThis.localStorage = {
    getItem: key => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
  }
  globalThis.window = new EventTarget()
  globalThis.CustomEvent = class extends Event {
    constructor(type, options = {}) { super(type); this.detail = options.detail }
  }
  return values
}

test('successful inquiry actions refresh subscribed modules once and retain independent acknowledgements', async () => {
  installBrowserMocks()
  const received = []
  const stopInquiry = listenForBusinessActions(7, 'sales-inquiries', event => received.push(event.id))
  const stopDashboard = listenForBusinessActions(7, 'sales-dashboard', event => received.push(event.id))

  const event = publishBusinessActions([{
    action: 'sales_inquiry.created', entity_type: 'sales_inquiry', entity_id: 23,
    reference_number: 'INQ-2026-000023', status: 'draft', operation_status: 'success',
  }], 7)

  await new Promise(resolve => setImmediate(resolve))
  assert.deepEqual(received, [event.id, event.id])
  assert.equal(pendingBusinessActions(7, 'sales-inquiries').length, 0)
  assert.equal(pendingBusinessActions(7, 'sales-dashboard').length, 0)
  assert.equal(pendingBusinessActions(7, 'sales-clients').length, 1)

  acknowledgeBusinessAction(event.id, 7, 'sales-clients')
  assert.equal(pendingBusinessActions(7, 'sales-clients').length, 0)
  stopInquiry()
  stopDashboard()
})

test('client creation invalidates client and inquiry selector views, but failed or malformed actions are ignored', () => {
  installBrowserMocks()
  let clientRefreshes = 0
  const stop = listenForBusinessActions(4, 'sales-clients', () => { clientRefreshes++ })
  const results = publishBusinessActions([
    { action: 'client.created', entity_type: 'client', entity_id: 12, reference_number: 'CLI-001', operation_status: 'success' },
    { action: 'sales_inquiry.created', entity_type: 'sales_inquiry', entity_id: 0, reference_number: 'BAD', operation_status: 'success' },
    { action: 'shipment.created', entity_type: 'shipment', entity_id: 9, reference_number: 'SHP-9', operation_status: 'failed' },
  ], 4)
  assert.equal(results.actions.length, 1)
  assert.deepEqual(results.stale_modules, ['sales-clients', 'sales', 'sales-dashboard', 'inquiry-selectors'])
  assert.equal(clientRefreshes, 1)
  stop()
})

test('a pending invalidation replays across tabs through the storage event', async () => {
  const values = installBrowserMocks()
  const event = publishBusinessActions([{
    action: 'sales_inquiry.created', entity_type: 'sales_inquiry', entity_id: 5,
    reference_number: 'INQ-005', operation_status: 'success',
  }], 2)
  // A separate view did not exist when the action was published; mounting it later reads pending state.
  let refreshes = 0
  const stop = listenForBusinessActions(2, 'sales-dashboard', () => { refreshes++ })
  assert.equal(pendingBusinessActions(2, 'sales-dashboard')[0].id, event.id)
  const key = [...values.keys()][0]
  const storageEvent = new Event('storage')
  Object.defineProperties(storageEvent, { key: { value: key }, newValue: { value: values.get(key) } })
  window.dispatchEvent(storageEvent)
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(refreshes, 1)
  assert.equal(pendingBusinessActions(2, 'sales-dashboard').length, 0)
  assert.ok(values.size > 0)
  stop()
})

test('confirmed forecast creation invalidates inquiry, calendar, dashboard and accounting views', () => {
  installBrowserMocks()
  const event = publishBusinessActions([{ action: 'shipment_forecast.created', entity_type: 'shipment_forecast', entity_id: 41, reference_number: 'Forecast #41', operation_status: 'success' }], 3)
  assert.ok(event)
  for (const moduleName of ['sales-inquiries', 'sales-calendar', 'sales-monthly', 'sales-dashboard', 'accounting-forecasts']) {
    assert.equal(pendingBusinessActions(3, moduleName).length, 1)
  }
  assert.equal(pendingBusinessActions(4, 'sales-calendar').length, 0)
})
