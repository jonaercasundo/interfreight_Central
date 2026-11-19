import test from 'node:test'
import assert from 'node:assert/strict'
import { buildSidebarNavigation } from '../../resources/js/sidebarNavigation.js'
import { renderSafeAssistantMarkdown } from '../../resources/js/assistantMarkdown.js'

const allAdminPermissions = [
  'dashboard.view', 'executive.overview.view', 'department.sales.view', 'users.view',
  'roles.view', 'audit.view', 'integrations.status.view', 'profile.view',
]

test('assistant Markdown formats prose and lists while escaping HTML and unsafe links', () => {
  const html = renderSafeAssistantMarkdown('**Sales**\n\n- Add a client\n- Review details\n\n[Open](/admin/users) <img src=x onerror=alert(1)>', [{ href: '/sales/clients', label: 'Client Management' }])
  assert.match(html, /<strong>Sales<\/strong>/)
  assert.match(html, /<ul><li>Add a client<\/li><li>Review details<\/li><\/ul>/)
  assert.match(html, /Open &lt;img src=x onerror=alert\(1\)&gt;/)
  assert.doesNotMatch(html, /<img/)
  assert.doesNotMatch(html, /href="\/admin\/users"/)
})

test('assistant Markdown links only to authorized pages and hides permission identifiers', () => {
  const html = renderSafeAssistantMarkdown('Go to /sales/clients. You have clients.create permission.', [{ href: '/sales/clients', label: 'Client Management' }])
  assert.match(html, /<a href="\/sales\/clients">Client Management<\/a>/)
  assert.match(html, /the required access/)
  assert.doesNotMatch(html, /clients\.create|\/sales\/clients\."/)
})

test('Administrator receives management navigation without department links', () => {
  const nav = buildSidebarNavigation(['Administrator'], [...allAdminPermissions, 'accounting.dashboard.view', 'budgets.view'])
  assert.deepEqual(nav.workspace.map(({ label }) => label), ['Dashboard'])
  assert.deepEqual(nav.administration.map(({ label }) => label), ['User Management', 'Roles & Permissions', 'Audit Logs', 'Integration Health'])
  assert.deepEqual(nav.account.map(({ href }) => href), ['/profile'])
})

test('Administrator takes precedence in mixed roles while non-administrative roles combine', () => {
  const admin = buildSidebarNavigation(['Administrator', 'Finance'], ['department.finance.view'])
  assert.equal(admin.workspace.some(({ href }) => href === '/finance'), false)
  const multiRole = buildSidebarNavigation(['Executive', 'Sales', 'Finance'], [
    'executive.overview.view', 'department.sales.view', 'department.finance.view',
  ])
  assert.deepEqual(multiRole.workspace.map(({ href }) => href), ['/finance'])
  assert.deepEqual(multiRole.sales.map(({ href }) => href), ['/sales'])
})

test('department entries require both an assigned role and its permission', () => {
  const nav = buildSidebarNavigation(['Sales', 'Transport'], [
    'department.sales.view', 'department.transport.view', 'department.billing.view', 'users.view',
  ])
  assert.deepEqual(nav.workspace.map(({ href }) => href), [])
  assert.deepEqual(nav.sales.map(({ href }) => href), ['/sales'])
  assert.deepEqual(nav.execution.map(({ key }) => key), [])
  assert.deepEqual(nav.administration.map(({ href }) => href), ['/admin/users'])
})

test('Sales dashboard navigation requires its dedicated permissions', () => {
  const sales = buildSidebarNavigation(['Sales'], [
    'sales.dashboard.view', 'sales.calendar.view', 'sales.availability.view', 'sales.inquiries.approve', 'clients.view', 'inquiries.view',
  ])
  assert.deepEqual(sales.sales.map(({ href }) => href), [
    '/sales/dashboard', '/sales/calendar', '/sales/availability', '/sales/clients', '/sales/inquiries',
  ])
  const withoutDashboard = buildSidebarNavigation(['Sales'], ['sales.calendar.view', 'sales.availability.view'])
  assert.equal(withoutDashboard.sales.some(({ href }) => href === '/sales/dashboard'), false)
  assert.equal(withoutDashboard.sales.some(({ href }) => href === '/sales/calendar'), true)
})

test('Phase 5 department menus are role and permission filtered', () => {
  const ops = buildSidebarNavigation(['Operations'], ['dashboard.view', 'shipments.view', 'operations.jobs.view', 'operations.customs.manage', 'department.operations.view'])
  assert.deepEqual(ops.workspace.map(({ href }) => href), ['/dashboard', '/shipments'])
  assert.deepEqual(ops.execution.map(({ key }) => key), ['operations'])
  assert.deepEqual(ops.execution[0].links.map(({ href }) => href), ['/operations', '/operations/jobs', '/operations/customs'])
  const finance = buildSidebarNavigation(['Finance'], ['shipments.view', 'transport.jobs.view', 'department.transport.view'])
  assert.deepEqual(finance.execution, [])
})

test('Executive Intelligence shows its implemented overview only to authorized Executive accounts', () => {
  const nav = buildSidebarNavigation(['Executive'], ['dashboard.view', 'executive.overview.view', 'audit.view'])
  assert.deepEqual(nav.workspace.map(({ href }) => href), ['/dashboard'])
  assert.deepEqual(nav.executive.map(({ href }) => href), ['/executive'])
  assert.deepEqual(nav.administration.map(({ href }) => href), ['/admin/audit'])
  assert.deepEqual(buildSidebarNavigation(['Administrator'], ['executive.overview.view']).executive, [])
  assert.deepEqual(buildSidebarNavigation(['Administrator', 'Executive'], ['executive.overview.view']).executive, [])
})

test('Accounting receives permission-filtered Phase 4 budget navigation', () => {
  const nav = buildSidebarNavigation(['Accounting'], [
    'dashboard.view', 'accounting.dashboard.view', 'department.accounting.view', 'budgets.view',
    'budgets.allocate', 'funding-requirements.view', 'cash-forecasts.view', 'commitments.view',
    'budget-variances.view', 'budgets.history.view', 'profile.view',
  ])
  assert.deepEqual(nav.workspace.map(({ href }) => href), ['/dashboard'])
  assert.deepEqual(nav.accounting.map(({ href }) => href), [
    '/accounting', '/accounting/funding-requirements', '/accounting/budget-plans',
    '/accounting/budget-allocations', '/accounting/cash-forecast', '/accounting/commitments-actuals',
    '/accounting/budget-variance', '/accounting/budget-history',
  ])
  const finance = buildSidebarNavigation(['Finance'], ['budgets.view', 'budgets.review', 'budget-variances.view'])
  assert.deepEqual(finance.finance.map(({ href }) => href), [])
  assert.deepEqual(finance.accounting.map(({ href }) => href), ['/accounting', '/accounting/budget-plans', '/accounting/budget-variance'])
  assert.equal(finance.accounting.some(({ href }) => href === '/accounting/budget-allocations'), false)
})

test('Phase 6 expense links are permission filtered for departments and accounting', () => {
  const operations = buildSidebarNavigation(['Operations'], ['expenses.view', 'cash-advances.view', 'cash-advances.liquidate'])
  assert.deepEqual(operations.expenseManagement.map(({ href }) => href), ['/expenses', '/cash-advances', '/my-liquidations'])
  const accounting = buildSidebarNavigation(['Accounting'], ['expenses.view', 'expenses.verify', 'cash-advances.view', 'cash-advances.liquidation.verify', 'budgets.utilization.view'])
  assert.deepEqual(accounting.accounting.slice(0, 5).map(({ href }) => href), [
    '/accounting/actual-expenses', '/accounting/expense-verification', '/accounting/budget-utilization',
    '/accounting/cash-advance-monitoring', '/accounting/liquidation-review',
  ])
  const sales = buildSidebarNavigation(['Sales'], [])
  assert.deepEqual(sales.expenseManagement, [])
})

test('Phase 9 collection navigation is shown only to roles with collection view access', () => {
  const accounting = buildSidebarNavigation(['Accounting'], ['collections.view'])
  const finance = buildSidebarNavigation(['Finance'], ['collections.view'])
  const billing = buildSidebarNavigation(['Billing'], ['collections.view'])
  const sales = buildSidebarNavigation(['Sales'], [])
  assert.equal(accounting.accounting.some(({ href }) => href === '/collections'), true)
  assert.equal(finance.finance.some(({ href }) => href === '/collections'), true)
  assert.equal(billing.billing.some(({ href }) => href === '/collections'), true)
  assert.equal(sales.accounting.some(({ href }) => href === '/collections'), false)
  const accountingWithFollowups = buildSidebarNavigation(['Accounting'], ['collections.view', 'collections.followups.view'])
  const accountingLinks = accountingWithFollowups.accounting.map(({ href }) => href)
  assert.ok(accountingLinks.includes('/collections?view=receipts'))
  assert.ok(accountingLinks.includes('/collections?view=ledger'))
  assert.ok(accountingLinks.includes('/collections?view=aging'))
  assert.ok(accountingLinks.includes('/collections?view=followups'))
  const financeOps = buildSidebarNavigation(['Finance'], ['collections.view', 'collections.reconcile'])
  assert.ok(financeOps.finance.some(({ href }) => href === '/collections?view=reconciliation'))
})

test('AI configuration is visible only with its administrator settings permission', () => {
  const without = buildSidebarNavigation(['Administrator'], ['users.view', 'roles.view'])
  assert.ok(!without.administration.some(item => item.href === '/admin/settings/ai'))

  const withCurrentPermission = buildSidebarNavigation(['Administrator'], ['admin.ai-config.view'])
  assert.ok(withCurrentPermission.administration.some(item => item.label === 'AI Configuration' && item.href === '/admin/settings/ai'))

  const withPermission = buildSidebarNavigation(['Administrator'], ['ai.configuration.view'])
  assert.ok(withPermission.administration.some(item => item.label === 'AI Configuration' && item.href === '/admin/settings/ai'))
})

test('AI usage monitoring is permission controlled and administrator visible', () => {
  const without = buildSidebarNavigation(['Administrator'], ['users.view'])
  assert.ok(!without.administration.some(item => item.href === '/admin/ai-usage'))
  const withPermission = buildSidebarNavigation(['Administrator'], ['admin.ai-usage.view'])
  assert.ok(withPermission.administration.some(item => item.label === 'AI Usage & Costs' && item.href === '/admin/ai-usage'))
})
