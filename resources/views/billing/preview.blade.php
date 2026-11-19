<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->reference }} Â· INTERFREIGHT GROUP</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#eef2f5;color:#203e55;font:14px Arial,sans-serif}.toolbar{display:flex;justify-content:center;padding:16px}.toolbar button{padding:10px 16px;border:0;border-radius:5px;background:#174d70;color:white;font-weight:bold;cursor:pointer}.paper{width:min(850px,calc(100% - 30px));min-height:1050px;margin:0 auto 30px;padding:58px;background:white;box-shadow:0 10px 35px #18334a20}.draft{margin:-20px 0 24px;padding:10px;border:2px solid #bd7e27;color:#996014;text-align:center;font-weight:bold;letter-spacing:.12em}.brand{display:flex;justify-content:space-between;gap:25px;border-bottom:3px solid #176184;padding-bottom:20px}.brand h1{margin:0;color:#123b5b;font-size:23px}.brand p{margin:6px 0;color:#6b7f8f;font-size:11px;letter-spacing:.14em}.brand h2{margin:0;text-align:right;color:#194b6c}.brand small{display:block;margin-top:8px;text-align:right;color:#718493}.details{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:30px 0}.details section{padding:14px;background:#f6f9fb}.details b{display:block;margin-bottom:8px;color:#587183;font-size:10px;text-transform:uppercase}.details p{margin:4px 0;white-space:pre-line}.line-table{width:100%;border-collapse:collapse}.line-table th{padding:11px;background:#eff4f7;color:#5e7483;text-align:left;font-size:10px;text-transform:uppercase}.line-table td{padding:12px 10px;border-bottom:1px solid #e5ebef}.number{text-align:right;white-space:nowrap}.totals{width:min(350px,100%);margin:24px 0 0 auto}.totals div{display:flex;justify-content:space-between;padding:8px;border-bottom:1px solid #e5ebef}.totals .grand{border:0;color:#123b5b;font-size:18px;font-weight:bold}.terms{margin-top:36px;padding:14px;background:#f6f9fb}.terms h3{margin:0 0 6px;font-size:12px}.terms p{margin:0;white-space:pre-line;color:#586f80;font-size:12px}.footer{margin-top:60px;padding-top:12px;border-top:1px solid #dce5ea;color:#82929d;font-size:10px;text-align:center}
        @media print{body{background:white}.toolbar{display:none}.paper{width:100%;min-height:0;margin:0;padding:34px;box-shadow:none}}
        @media(max-width:600px){.paper{padding:22px}.details{grid-template-columns:1fr}.brand{flex-direction:column}.brand h2,.brand small{text-align:left}}
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Print draft preview</button></div>
<main class="paper">
    @unless($isIssued)<div class="draft">DRAFT PREVIEW Â· NOT A TAX INVOICE Â· NOT VALID FOR PAYMENT</div>@endunless
    <header class="brand"><div><h1>INTERFREIGHT GROUP</h1><p>INTERFREIGHT CENTRAL</p></div><div><h2>{{ $isIssued && $invoice->official_number ? 'INVOICE' : 'INVOICE DRAFT' }}</h2><small>Reference: {{ $invoice->official_number ?: $invoice->reference }}</small></div></header>
    <div class="details"><section><b>Bill to</b><p>{{ $invoice->company_name }}</p><p>{{ $invoice->billing_contact }}</p><p>{{ $invoice->billing_address }}</p></section><section><b>Shipment and terms</b><p>Shipment: {{ $invoice->job_number }}</p><p>Invoice date: {{ $invoice->invoice_date ?: 'On submission' }}</p><p>Due date: {{ $invoice->due_date ?: 'Not set' }}</p><p>Currency: {{ $invoice->currency }}</p><p>Payment terms: {{ $invoice->payment_terms ?: 'As stated in accepted quotation' }}</p></section></div>
    <table class="line-table"><thead><tr><th>Description</th><th>Authorization</th><th class="number">Amount</th></tr></thead><tbody>@foreach($invoice->lines as $line)<tr><td>{{ $line->description }}</td><td>{{ $line->authorization_reference }}</td><td class="number">{{ $line->currency }} {{ $line->line_amount }}</td></tr>@endforeach</tbody></table>
    <div class="totals"><div><span>Subtotal</span><b>{{ $invoice->currency }} {{ $invoice->subtotal }}</b></div><div><span>Discount</span><b>{{ $invoice->currency }} {{ $invoice->discount_total }}</b></div><div><span>Tax</span><b>{{ $invoice->tax_total === null ? 'Not configured' : $invoice->currency.' '.$invoice->tax_total }}</b></div><div class="grand"><span>Total</span><b>{{ $invoice->total === null ? 'Pending tax configuration' : $invoice->currency.' '.number_format((float) $invoice->total, 2) }}</b></div></div>
    <section class="terms"><h3>Payment terms and remarks</h3><p>{{ $invoice->payment_terms ?: 'As stated in accepted quotation.' }}@if($invoice->remarks)
{{ $invoice->remarks }}@endif</p></section>
    <footer class="footer">InterFreight Central Â· This document contains authorized commercial charges only. Collection status is not represented.</footer>
</main>
</body>
</html>
