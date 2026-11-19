<?php

return [
    // Must remain false until company tax registration, statutory numbering, and invoice format are verified.
    'legal_issuance_verified' => (bool) env('IFC_BILLING_LEGAL_ISSUANCE_VERIFIED', false),
    // An approved profile is not sufficient; an authorized statutory provider is still required.
    'issuance_provider' => env('IFC_BILLING_ISSUANCE_PROVIDER'),
];
