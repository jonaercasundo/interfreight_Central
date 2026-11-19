const departments = [
  { role: 'Sales', permission: 'department.sales.view', label: 'Sales', href: '/sales', icon: 'layers' },
  { role: 'Operations', permission: 'department.operations.view', label: 'Operations', href: '/operations', icon: 'layers' },
  { role: 'Transport', permission: 'department.transport.view', label: 'Transport', href: '/transport', icon: 'layers' },
  { role: 'Forwarding', permission: 'department.forwarding.view', label: 'Forwarding', href: '/forwarding', icon: 'layers' },
  { role: 'Accounting', permission: 'department.accounting.view', label: 'Accounting', href: '/accounting', icon: 'layers' },
  { role: 'Billing', permission: 'department.billing.view', label: 'Billing', href: '/billing', icon: 'layers' },
  { role: 'Finance', permission: 'department.finance.view', label: 'Finance', href: '/finance', icon: 'layers' },
]

const administration = [
  { label: 'User Management', permission: 'users.view', href: '/admin/users', icon: 'users' },
  { label: 'Roles & Permissions', permission: 'roles.view', href: '/admin/roles', icon: 'roles' },
  { label: 'Audit Logs', permission: 'audit.view', href: '/admin/audit', icon: 'audit' },
  { label: 'Integration Health', permission: 'integrations.status.view', href: '/admin/integrations', icon: 'health' },
  { label: 'AI Configuration', permissions: ['admin.ai-config.view', 'ai.configuration.view'], href: '/admin/settings/ai', icon: 'health' },
  { label: 'AI Usage & Costs', permission: 'admin.ai-usage.view', href: '/admin/ai-usage', icon: 'chart' },
]

const executionGroups = [
  { key: 'operations', role: 'Operations', title: 'Operations', icon: 'layers', links: [
    ['Operations Overview', 'operations.jobs.view', '/operations'], ['Job Register', 'operations.jobs.view', '/operations/jobs'], ['Customs Processing', 'operations.customs.manage', '/operations/customs'], ['Document Checklist', 'shipments.documents.view', '/operations/documents'], ['Milestones', 'operations.jobs.manage', '/operations/milestones'], ['Exceptions', 'shipment.exceptions.view', '/operations/exceptions'], ['Completion Review', 'shipments.completion.view', '/finance/shipment-closing'],
  ] },
  { key: 'transport', role: 'Transport', title: 'Transport', icon: 'truck', links: [
    ['Transport Overview', 'transport.jobs.view', '/transport'], ['Dispatch Board', 'transport.dispatch.manage', '/transport/dispatch'], ['Truck Assignments', 'transport.assignments.manage', '/transport/assignments'], ['Pickup & Delivery', 'transport.deliveries.manage', '/transport/deliveries'], ['POD Management', 'transport.pod.upload', '/transport/pod'], ['Completion Review', 'shipments.completion.view', '/finance/shipment-closing'],
  ] },
  { key: 'forwarding', role: 'Forwarding', title: 'Forwarding', icon: 'ship', links: [
    ['Forwarding Overview', 'forwarding.jobs.view', '/forwarding'], ['Booking Management', 'forwarding.bookings.manage', '/forwarding/bookings'], ['Carrier Schedules', 'forwarding.jobs.view', '/forwarding/schedules'], ['Container Monitoring', 'forwarding.containers.manage', '/forwarding/containers'], ['Milestones', 'forwarding.milestones.manage', '/forwarding/milestones'], ['Completion Review', 'shipments.completion.view', '/finance/shipment-closing'],
  ] },
]

const expenseManagement = [
  { label: 'Expense Records', permission: 'expenses.view', href: '/expenses', icon: 'audit' },
  { label: 'Cash Advances', permission: 'cash-advances.view', href: '/cash-advances', icon: 'chart' },
  { label: 'My Liquidations', permission: 'cash-advances.liquidate', href: '/my-liquidations', icon: 'audit' },
]

export function buildSidebarNavigation(roles = [], permissions = []) {
  const assignedRoles = new Set(roles)
  const allowed = new Set(permissions)
  const administrator = assignedRoles.has('Administrator')

  const sales = [
    { label: 'Sales Overview', permission: 'department.sales.view', href: '/sales', icon: 'grid' },
    { label: 'Sales Dashboard', permission: 'sales.dashboard.view', href: '/sales/dashboard', icon: 'chart' },
    { label: 'Shipment Calendar', permission: 'sales.calendar.view', href: '/sales/calendar', icon: 'calendar' },
    { label: 'Shipment Availability', permission: 'sales.availability.view', href: '/sales/availability', icon: 'truck' },
    { label: 'Client Management', permission: 'clients.view', href: '/sales/clients', icon: 'users' },
    { label: 'Client Inquiries', permission: 'inquiries.view', href: '/sales/inquiries', icon: 'audit' },
    { label: 'Forecast Calendar', permission: 'forecasts.view', href: '/sales/forecasts/calendar', icon: 'chart' },
    { label: 'Monthly Demand', permission: 'forecasts.view', href: '/sales/forecasts/monthly', icon: 'chart' },
    { label: 'Cost Estimates', permission: 'cost-estimates.view', href: '/sales/cost-estimates', icon: 'audit' },
    { label: 'Quotations', permission: 'quotations.view', href: '/sales/quotations', icon: 'roles' },
    { label: 'Quotation History', permission: 'quotations.history.view', href: '/sales/quotation-history', icon: 'audit' },
    { label: 'Forecast History', permission: 'forecasts.view', href: '/sales/forecasts/history', icon: 'roles' },
  ].filter(({ permission }) => allowed.has(permission))
  const accounting = [
    ...(allowed.has('financial-closing.view') ? [{ label: 'Completion & Closing Review', permission: 'financial-closing.view', href: '/finance/shipment-closing', icon: 'audit' }] : []),
    ...(allowed.has('expenses.view') ? [{ label: 'Actual Expense Ledger', permission: 'expenses.view', href: '/accounting/actual-expenses', icon: 'audit' }] : []),
    ...(allowed.has('expenses.verify') ? [{ label: 'Expense Verification', permission: 'expenses.verify', href: '/accounting/expense-verification', icon: 'roles' }] : []),
    ...(allowed.has('budgets.utilization.view') ? [{ label: 'Budget Utilization', permission: 'budgets.utilization.view', href: '/accounting/budget-utilization', icon: 'chart' }] : []),
    ...(allowed.has('cash-advances.view') ? [{ label: 'Cash Advance Monitoring', permission: 'cash-advances.view', href: '/accounting/cash-advance-monitoring', icon: 'chart' }] : []),
    ...(allowed.has('cash-advances.liquidation.verify') ? [{ label: 'Liquidation Review', permission: 'cash-advances.liquidation.verify', href: '/accounting/liquidation-review', icon: 'roles' }] : []),
    ...(allowed.has('expenses.variance.view') ? [{ label: 'Variance & Exceptions', permission: 'expenses.variance.view', href: '/accounting/variance-exceptions', icon: 'audit' }] : []),
    ...(allowed.has('expenses.view') ? [{ label: 'Expense Reports', permission: 'expenses.view', href: '/accounting/expense-reports', icon: 'chart' }] : []),
    ...(allowed.has('budgets.view') ? [{ label: 'Accounting Overview', permission: 'budgets.view', href: '/accounting', icon: 'layers' }] : []),
    ...(assignedRoles.has('Finance') && allowed.has('budgets.review') ? [{ label: 'Budget Approval Queue', permission: 'budgets.review', href: '/accounting/budget-plans', icon: 'roles' }] : []),
    ...(allowed.has('forecasts.accounting.view') ? [{ label: 'Shipment Demand Forecast', permission: 'forecasts.accounting.view', href: '/accounting/shipment-demand', icon: 'chart' }] : []),
    ...(allowed.has('funding-requirements.view') ? [{ label: 'Funding Requirements', permission: 'funding-requirements.view', href: '/accounting/funding-requirements', icon: 'chart' }] : []),
    ...(assignedRoles.has('Accounting') && allowed.has('budgets.view') ? [{ label: 'Monthly Budget Plans', permission: 'budgets.view', href: '/accounting/budget-plans', icon: 'audit' }] : []),
    ...(assignedRoles.has('Accounting') && allowed.has('budgets.allocate') ? [{ label: 'Budget Allocations', permission: 'budgets.allocate', href: '/accounting/budget-allocations', icon: 'roles' }] : []),
    ...(allowed.has('cash-forecasts.view') ? [{ label: 'Cash Requirement Forecast', permission: 'cash-forecasts.view', href: '/accounting/cash-forecast', icon: 'chart' }] : []),
    ...(allowed.has('commitments.view') || allowed.has('budget-actuals.view') ? [{ label: 'Commitments & Actuals', permission: allowed.has('commitments.view') ? 'commitments.view' : 'budget-actuals.view', href: '/accounting/commitments-actuals', icon: 'audit' }] : []),
    ...(allowed.has('budget-variances.view') ? [{ label: 'Budget Variance Reports', permission: 'budget-variances.view', href: '/accounting/budget-variance', icon: 'chart' }] : []),
    ...(allowed.has('budgets.history.view') ? [{ label: 'Budget History', permission: 'budgets.history.view', href: '/accounting/budget-history', icon: 'roles' }] : []),
    ...(allowed.has('collections.view') ? [{ label: 'Accounts Receivable', permission: 'collections.view', href: '/collections', icon: 'chart' }] : []),
    ...(allowed.has('collections.view') ? [{ label: 'Collection Register', permission: 'collections.view', href: '/collections?view=receipts', icon: 'audit' }] : []),
    ...(assignedRoles.has('Finance') && allowed.has('collections.reconcile') ? [{ label: 'Payment Reconciliation', permission: 'collections.reconcile', href: '/collections?view=reconciliation', icon: 'roles' }] : []),
    ...(allowed.has('collections.view') ? [{ label: 'Customer Ledger', permission: 'collections.view', href: '/collections?view=ledger', icon: 'chart' }] : []),
    ...(allowed.has('collections.view') ? [{ label: 'Aging Reports', permission: 'collections.view', href: '/collections?view=aging', icon: 'chart' }] : []),
    ...(allowed.has('collections.followups.view') ? [{ label: 'Collection Follow-ups', permission: 'collections.followups.view', href: '/collections?view=followups', icon: 'audit' }] : []),
  ]
  const finance = [
    ...(allowed.has('financial-closing.view') ? [{ label: 'Shipment Closing', permission: 'financial-closing.view', href: '/finance/shipment-closing', icon: 'audit' }] : []),
    ...(allowed.has('collections.reconcile') ? [{ label: 'Payment Reconciliation', permission: 'collections.reconcile', href: '/collections?view=reconciliation', icon: 'roles' }] : []),
    ...(allowed.has('collections.view') ? [{ label: 'Accounts Receivable Overview', permission: 'collections.view', href: '/collections', icon: 'chart' }] : []),
    ...(allowed.has('expenses.view') ? [{ label: 'Expense Oversight', permission: 'expenses.view', href: '/finance/expense-oversight', icon: 'audit' }] : []),
    ...(allowed.has('expenses.exceptions.approve') ? [{ label: 'Overrun Approval Queue', permission: 'expenses.exceptions.approve', href: '/finance/overruns', icon: 'roles' }] : []),
    ...(allowed.has('budgets.utilization.view') ? [{ label: 'Budget Variance', permission: 'budgets.utilization.view', href: '/finance/budget-variance', icon: 'chart' }] : []),
    ...(allowed.has('cash-advances.view') ? [{ label: 'Cash Advance Exposure', permission: 'cash-advances.view', href: '/finance/cash-advance-exposure', icon: 'chart' }] : []),
    ...(allowed.has('cost-estimates.view') ? [{ label: 'Cost Review', permission: 'cost-estimates.view', href: '/finance/cost-review', icon: 'audit' }] : []),
    ...(allowed.has('margins.view') ? [{ label: 'Margin Analysis', permission: 'margins.view', href: '/finance/margins', icon: 'chart' }] : []),
    ...((allowed.has('quotations.approve') || allowed.has('quotations.review')) ? [{ label: 'Quotation Approval Queue', permission: allowed.has('quotations.approve') ? 'quotations.approve' : 'quotations.review', href: '/finance/approval-queue', icon: 'roles' }] : []),
    ...(allowed.has('collections.view') ? [{ label: 'Collection Monitoring', permission: 'collections.view', href: '/collections', icon: 'chart' }] : []),
  ]
  const billing = [
    ...(allowed.has('shipments.completion.view') ? [{ label: 'Billing Completion Review', permission: 'shipments.completion.view', href: '/finance/shipment-closing', icon: 'audit' }] : []),
    ...(allowed.has('billing.dashboard.view') ? [{ label: 'Billing Overview', permission: 'billing.dashboard.view', href: '/billing', icon: 'chart' }] : []),
    ...(allowed.has('billing.ready.view') ? [{ label: 'Ready for Billing', permission: 'billing.ready.view', href: '/billing/ready', icon: 'audit' }] : []),
    ...(allowed.has('billing.invoices.view') ? [{ label: 'Invoice Register', permission: 'billing.invoices.view', href: '/billing/register', icon: 'audit' }] : []),
    ...(allowed.has('billing.invoices.create') ? [{ label: 'Create Invoice', permission: 'billing.invoices.create', href: '/billing/create', icon: 'roles' }] : []),
    ...(allowed.has('billing.invoices.review') ? [{ label: 'Invoice Approval Queue', permission: 'billing.invoices.review', href: '/billing/approvals', icon: 'roles' }] : []),
    ...(allowed.has('billing.invoices.history.view') ? [{ label: 'Invoice Revisions', permission: 'billing.invoices.history.view', href: '/billing/revisions', icon: 'audit' }] : []),
    ...(allowed.has('billing.documents.manage') ? [{ label: 'Billing Documents', permission: 'billing.documents.manage', href: '/billing/documents', icon: 'audit' }] : []),
    ...(allowed.has('billing.exceptions.view') ? [{ label: 'Billing Exceptions', permission: 'billing.exceptions.view', href: '/billing/exceptions', icon: 'health' }] : []),
    ...(allowed.has('collections.view') ? [{ label: 'Collection Monitoring', permission: 'collections.view', href: '/collections', icon: 'chart' }] : []),
  ]
  const execution = administrator ? [] : executionGroups.filter(({ role }) => assignedRoles.has(role)).map((group) => ({
    ...group,
    links: group.links.filter(([, permission]) => allowed.has(permission)).map(([label, permission, href]) => ({ label, permission, href, icon: group.icon })),
  })).filter(({ links }) => links.length)

  return {
    workspace: [
      ...(allowed.has('dashboard.view') ? [{ label: 'Dashboard', href: '/dashboard', icon: 'grid' }] : []),
      ...(allowed.has('shipments.view') && (administrator || assignedRoles.has('Executive') || ['Sales', 'Operations', 'Transport', 'Forwarding'].some((role) => assignedRoles.has(role))) ? [{ label: 'Shipment Register', href: '/shipments', icon: 'layers' }] : []),
      ...(!administrator && allowed.has('additional-charges.view') ? [{ label: 'Additional Charges', href: '/additional-charges', icon: 'audit' }] : []),
      ...(!administrator && allowed.has('commercial-changes.billing-eligibility.view') ? [{ label: 'Approved Additional Charges', href: '/billing/additional-charges', icon: 'audit' }] : []),
      ...departments
        .filter(({ role, permission }) => !administrator && !['Sales', 'Operations', 'Transport', 'Forwarding', 'Accounting'].includes(role) && assignedRoles.has(role) && allowed.has(permission))
        .map(({ label, href, icon }) => ({ label, href, icon })),
    ],
    executive: !administrator && assignedRoles.has('Executive') && allowed.has('executive.overview.view') ? [{ label: 'Executive Overview', permission: 'executive.overview.view', href: '/executive', icon: 'chart' }] : [],
    sales: administrator ? [] : sales,
    accounting: administrator ? [] : accounting,
    finance: administrator ? [] : finance,
    billing: administrator ? [] : billing,
    expenseManagement: administrator ? [] : expenseManagement.filter(({ permission }) => allowed.has(permission)),
    execution,
    administration: administration.filter(({ permission, permissions }) => permissions ? permissions.some((name) => allowed.has(name)) : allowed.has(permission)),
    account: allowed.has('profile.view') ? [{ label: 'My Profile', href: '/profile', icon: 'user' }] : [],
  }
}

