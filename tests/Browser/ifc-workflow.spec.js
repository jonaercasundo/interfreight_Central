import { expect, test } from '@playwright/test'

const credentials = {
  admin: { email: 'phase17-admin@example.test', password: 'IFC-Browser-Test-Only-17!' },
  sales: { email: 'phase17-sales@example.test', password: 'IFC-Browser-Test-Only-17!' },
}

test.beforeEach(async ({ page }) => {
  page.on('pageerror', (error) => console.error(`[browser page error] ${error.message}`))
  page.on('response', (response) => {
    if (response.status() === 401) console.error(`[browser 401] ${response.request().method()} ${response.url()}`)
  })
  page.on('console', (message) => {
    if (message.type() === 'error') console.error(`[browser console error] ${message.text()}`)
  })
})

async function signIn(page, user = credentials.admin) {
  await page.goto('/login')
  await page.getByLabel('Work email').fill(user.email)
  await page.getByLabel('Password', { exact: true }).fill(user.password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/dashboard/)
}

test('User Management Add User dialog and pagination work with synthetic test records', async ({ page }) => {
  await signIn(page)
  await page.goto('/admin/users')
  await expect(page.getByRole('heading', { name: 'User Management' })).toBeVisible()
  await page.getByRole('button', { name: /add user/i }).click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Add user' })).toBeVisible()
  await expect(page.getByLabel(/work email/i)).toBeVisible()
  await page.getByRole('button', { name: 'Cancel' }).click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Next page' })).toBeEnabled()
  await page.getByRole('button', { name: 'Next page' }).click()
  await expect(page.getByText(/Showing .* of/i)).toBeVisible()
})

test('Roles & Permissions supports role search and selection', async ({ page }) => {
  await signIn(page)
  await page.goto('/admin/roles')
  await expect(page.locator('h1').filter({ hasText: 'Roles & Permissions' })).toBeVisible()
  await page.getByRole('searchbox', { name: 'Search roles' }).fill('Finance')
  const financeRole = page.locator('.rbac-role-option').filter({ hasText: 'Finance' })
  await expect(financeRole).toBeVisible()
  await financeRole.click()
  await expect(financeRole).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByRole('heading', { name: 'Finance', exact: true })).toBeVisible()
})

test('Integration Health displays queue backlog and failed-job monitoring', async ({ page }) => {
  await signIn(page)
  await page.goto('/admin/integrations')
  await expect(page.getByRole('heading', { name: 'Integration Health' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Queue Processing' })).toBeVisible()
  await expect(page.getByText('Pending jobs')).toBeVisible()
  await expect(page.getByText('Failed jobs')).toBeVisible()
})

test('Sales client search and type filter keep request failures distinct from empty results', async ({ page }) => {
  await signIn(page, credentials.sales)
  await page.goto('/sales/clients')
  await expect(page.getByRole('heading', { name: 'Client Management' })).toBeVisible()
  await page.getByLabel('Search clients').fill('Synthetic client')
  await page.getByLabel('Filter by client type').selectOption('FCL')
  await expect(page.getByText(/client records could not be loaded/i)).toHaveCount(0)
  await expect(page.getByText(/No client records match the current filters/i)).toBeVisible()
})

test('Sales Dashboard provides calendar views and clearly unconfirmed availability at mobile width', async ({ page }) => {
  await signIn(page, credentials.sales)
  await expect(page).toHaveURL(/\/sales\/dashboard$/)
  await expect(page.getByRole('heading', { name: 'Sales Dashboard' })).toBeVisible()
  await expect(page.getByText('Total Inquiries')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Shipment Calendar' })).toBeVisible()
  await expect(page.getByRole('group', { name: 'Calendar view' }).getByRole('button', { name: 'Week' })).toBeVisible()
  await page.getByRole('group', { name: 'Calendar view' }).getByRole('button', { name: 'List' }).click()
  await expect(page.getByLabel('Client')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Shipment Availability' })).toBeVisible()
  await expect(page.getByText('Availability not yet confirmed')).toBeVisible()
  await page.setViewportSize({ width: 375, height: 812 })
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true)
})

test('IFC Assistant provides permission-filtered Sales tutorial progress and registered navigation', async ({ page }) => {
  test.setTimeout(90000)
  await signIn(page, credentials.sales)
  await page.goto('/sales/inquiries')
  await page.getByRole('button', { name: 'AI Assistant' }).click()
  await expect(page.getByRole('complementary', { name: 'IFC Assistant' })).toBeVisible()
  await page.getByRole('button', { name: 'Tutorials' }).click()
  await expect(page.locator('.ifc-tutorial-label')).toContainText('Client Inquiries', { timeout: 20000 })
  await expect(page.getByText('Step 1 of 5')).toBeVisible()
  await page.getByRole('button', { name: /All tutorials/ }).click()
  await expect(page.getByText('Sales tutorials')).toBeVisible({ timeout: 60000 })
  await page.getByRole('button', { name: 'Start Sales Tutorial' }).click()
  await expect(page.getByText('Step 1 of 4')).toBeVisible()
  await expect(page.getByText(/Open Sales → Sales Dashboard/)).toBeVisible()
  await page.getByRole('button', { name: 'Next step' }).click()
  await expect(page.getByText('Step 2 of 4')).toBeVisible()
  await page.getByRole('button', { name: 'Previous' }).click()
  await expect(page.getByText('Step 1 of 4')).toBeVisible()
  await page.getByRole('button', { name: 'Restart' }).click()
  await expect(page.getByText('Step 1 of 4')).toBeVisible()
  await expect(page.getByRole('link', { name: '/sales/inquiries' })).toHaveAttribute('href', '/sales/inquiries')
})

test('IFC Assistant chat keeps the composer usable and responsive on mobile', async ({ page }) => {
  await signIn(page, credentials.sales)
  await page.goto('/sales/inquiries')
  await page.getByRole('button', { name: 'AI Assistant' }).click()
  const assistant = page.getByRole('complementary', { name: 'IFC Assistant' })
  await expect(assistant).toBeVisible()
  await expect(assistant.getByText(/Hi! How can I help you today/)).toBeVisible()

  const composer = assistant.getByRole('textbox', { name: 'Message IFC Assistant' })
  await composer.fill('A short first line\nA second line that should expand the composer\nA third line')
  await expect.poll(() => composer.evaluate(element => Number.parseFloat(getComputedStyle(element).height))).toBeGreaterThan(40)
  await composer.focus()
  await page.keyboard.press('Shift+Enter')
  await expect(composer).toBeFocused()
  await expect(composer).toHaveValue(/\n$/)
  await expect.poll(() => composer.evaluate(element => Number.parseFloat(getComputedStyle(element).height))).toBeLessThanOrEqual(146)

  await page.setViewportSize({ width: 375, height: 812 })
  await expect.poll(() => assistant.evaluate(element => element.getBoundingClientRect().width)).toBeLessThanOrEqual(375)
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true)
})

test('IFC Assistant fills, reviews, saves, and refreshes the real Client Management form', async ({ page }) => {
  test.setTimeout(120000)
  await signIn(page, credentials.sales)
  await page.goto('/sales/clients')
  const conversationStarted = page.waitForResponse(response => response.url().endsWith('/api/assistant/conversations') && response.request().method() === 'POST')
  await page.getByRole('button', { name: 'AI Assistant' }).click()
  await conversationStarted
  const assistant = page.getByRole('complementary', { name: 'IFC Assistant' })
  await expect(assistant).toBeVisible()
  const composer = assistant.getByRole('textbox', { name: 'Message IFC Assistant' })

  await composer.fill('Help me fill up the form')
  await assistant.getByRole('button', { name: 'Send message' }).click()
  await expect(assistant.getByText('Client Management form', { exact: true })).toBeVisible({ timeout: 15000 })
  const clientDialog = page.getByRole('dialog')
  await expect(clientDialog).toBeVisible()

  async function answer(value) {
    await composer.fill(value)
    await assistant.getByRole('button', { name: 'Send message' }).click()
  }

  await answer('Harbor Imports')
  await expect(clientDialog.getByLabel('Company name')).toHaveValue('Harbor Imports')
  await assistant.getByRole('button', { name: 'FCL', exact: true }).click()
  await expect(clientDialog.getByLabel('Client type')).toHaveValue('FCL')
  await answer('Taylor Ng')
  await answer('taylor@example.test')
  await answer('+65 6123 4567')
  await answer('10 Marina Boulevard')
  await expect(assistant.getByText('Confirm and save client')).toBeVisible()
  await expect(clientDialog.getByLabel('Email')).toHaveValue('taylor@example.test')
  await expect(clientDialog.getByText('AI filled · please review').first()).toBeVisible()

  await clientDialog.getByLabel('Company name').fill('Harbor Trading')
  await clientDialog.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(page.getByRole('row').filter({ hasText: 'Harbor Trading' })).toBeVisible({ timeout: 15000 })
  await expect(assistant.getByText(/Client saved/)).toBeVisible()
  await expect(assistant.getByRole('button', { name: 'Create Sales Inquiry' })).toBeVisible()
  await expect(page.getByRole('row').filter({ hasText: 'Harbor Trading' })).toHaveCount(1)
})

test('Add Inquiry selects mode-specific origin and destination locations and conditionally shows FCL containers', async ({ page }) => {
  await signIn(page, credentials.sales)
  await page.goto('/sales/inquiries')
  await page.getByRole('button', { name: /new inquiry/i }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()
  // Scope controls to the dialog; the inquiries table has its own status filter.
  await dialog.getByRole('combobox').nth(0).selectOption({ label: 'Synthetic Location Test Client' })
  await dialog.getByLabel('Origin country').selectOption('PH')
  await dialog.getByLabel('Origin port, airport or place').fill('Manila')
  await page.getByRole('option', { name: /Manila.*Philippines.*Seaport.*PHMNL/ }).click()
  await dialog.getByLabel('Destination country').selectOption('SG')
  await dialog.getByLabel('Destination port, airport or place').fill('Singapore')
  await page.getByRole('option', { name: /Singapore.*Singapore.*Seaport.*SGSIN/ }).click()

  await expect(dialog.getByLabel('Container count')).toHaveCount(0)
  await dialog.getByLabel('Service type').selectOption('FCL')
  await expect(dialog.getByLabel('Container count')).toBeVisible()
  await dialog.getByLabel('Container count').fill('1')
  await dialog.getByLabel('Container type').selectOption('40HC')

  await dialog.getByLabel('Transport mode').selectOption('Air')
  await expect(dialog.locator('.location-selector-note').filter({ hasText: /Sea locations cleared because they are not compatible with Air/ }).first()).toBeVisible()
  await dialog.getByLabel('Origin port, airport or place').fill('Manila')
  await page.getByRole('option', { name: /Manila.*Philippines.*Airport.*MNL/ }).click()
  await dialog.getByLabel('Destination port, airport or place').fill('Singapore')
  await page.getByRole('option', { name: /Singapore Changi.*Singapore.*Airport.*SIN/ }).click()
  await dialog.getByLabel('Cargo description').fill('Synthetic electronics freight')
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText('Changes saved.')).toBeVisible({ timeout: 10000 })
})

for (const width of [1440, 1024, 768, 375]) {
  test(`User Management page has no document-level horizontal overflow at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await signIn(page)
    await page.goto('/admin/users')
    await expect(page.getByRole('heading', { name: 'User Management' })).toBeVisible()
    const dimensions = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth, content: document.documentElement.scrollWidth }))
    expect(dimensions.content).toBeLessThanOrEqual(dimensions.viewport + 1)
  })
}
