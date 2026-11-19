<?php

return [
    'low_margin_percent' => env('IFC_LOW_MARGIN_PERCENT'),
    'minimum_margin_percent' => env('IFC_MINIMUM_MARGIN_PERCENT'),
    'required_quotation_reviews' => array_values(array_filter(array_map('trim', explode(',', env('IFC_REQUIRED_QUOTATION_REVIEWS', 'accounting,finance'))))),
    'prohibit_self_approval' => (bool) env('IFC_PROHIBIT_SELF_APPROVAL', true),
    // Intentionally dormant. Finance must approve the source, accrual and FX policy
    // in writing before any service can publish recognized revenue or close a shipment.
    'policy' => [
        'status' => env('IFC_FINANCE_POLICY_STATUS', 'unconfigured'),
        'approval_reference' => env('IFC_FINANCE_APPROVAL_REFERENCE'),
        'approved_by' => env('IFC_FINANCE_APPROVED_BY'),
        'revenue_recognition_source' => env('IFC_REVENUE_RECOGNITION_SOURCE'),
        'final_cost_accrual_method' => env('IFC_FINAL_COST_ACCRUAL_METHOD'),
        'fx_source' => env('IFC_FINANCE_FX_SOURCE'),
    ],
];
