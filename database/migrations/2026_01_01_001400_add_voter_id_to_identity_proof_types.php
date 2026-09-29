<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Voter ID is one of the two ID cards the public requisition form accepts
 * (Aadhaar / Voter ID), so it becomes a first-class document and proof type
 * rather than being filed as "OTHER".
 */
return new class extends Migration
{
    private const WITH = "'AADHAAR','PAN','PASSPORT','OFFICE_ID','VOTER_ID','OTHER'";

    private const WITHOUT = "'AADHAAR','PAN','PASSPORT','OFFICE_ID','OTHER'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE request_documents MODIFY doc_type ENUM('.self::WITH.') NOT NULL');
        DB::statement('ALTER TABLE request_occupants MODIFY id_proof_type ENUM('.self::WITH.') NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('request_documents')->where('doc_type', 'VOTER_ID')->update(['doc_type' => 'OTHER']);
        DB::table('request_occupants')->where('id_proof_type', 'VOTER_ID')->update(['id_proof_type' => 'OTHER']);

        DB::statement('ALTER TABLE request_documents MODIFY doc_type ENUM('.self::WITHOUT.') NOT NULL');
        DB::statement('ALTER TABLE request_occupants MODIFY id_proof_type ENUM('.self::WITHOUT.') NULL');
    }
};
