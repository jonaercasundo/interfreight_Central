<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DocumentReferenceGenerator
{
    public function next(string $type): string
    {
        $year = (int) now()->format('Y');
        DB::table('document_sequences')->insertOrIgnore(['document_type' => $type, 'year' => $year, 'next_value' => 0]);
        $sequence = DB::table('document_sequences')->where('document_type', $type)->where('year', $year)->lockForUpdate()->first();
        $next = $sequence->next_value + 1;
        DB::table('document_sequences')->where('id', $sequence->id)->update(['next_value' => $next]);

        return sprintf('%s-%d-%06d', $type, $year, $next);
    }
}
