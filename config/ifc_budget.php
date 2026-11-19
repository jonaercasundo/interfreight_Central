<?php

return [
    // No monetary thresholds are defined until InterFreight supplies its authority matrix.
    'approval_roles' => array_values(array_filter(array_map('trim', explode(',', env('IFC_BUDGET_APPROVAL_ROLES', 'Finance'))))),
    'prevent_self_approval' => (bool) env('IFC_BUDGET_PREVENT_SELF_APPROVAL', true),
];
