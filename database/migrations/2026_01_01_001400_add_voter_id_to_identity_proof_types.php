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
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE request_documents MODIFY doc_type ENUM('.self::WITH.') NOT NULL');
            DB::statement('ALTER TABLE request_occupants MODIFY id_proof_type ENUM('.self::WITH.') NULL');

            return;
        }

        if ($driver === 'pgsql') {
            // Laravel's ->enum() becomes a CHECK constraint named
            // "{table}_{column}_check". Recreate it with VOTER_ID added.
            DB::statement('ALTER TABLE request_documents DROP CONSTRAINT IF EXISTS request_documents_doc_type_check');
            DB::statement('ALTER TABLE request_documents ADD CONSTRAINT request_documents_doc_type_check CHECK (doc_type IN ('.self::WITH.'))');

            DB::statement('ALTER TABLE request_occupants DROP CONSTRAINT IF EXISTS request_occupants_id_proof_type_check');
            DB::statement('ALTER TABLE request_occupants ADD CONSTRAINT request_occupants_id_proof_type_check CHECK (id_proof_type IN ('.self::WITH.'))');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        DB::table('request_documents')->where('doc_type', 'VOTER_ID')->update(['doc_type' => 'OTHER']);
        DB::table('request_occupants')->where('id_proof_type', 'VOTER_ID')->update(['id_proof_type' => 'OTHER']);

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE request_documents MODIFY doc_type ENUM('.self::WITHOUT.') NOT NULL');
            DB::statement('ALTER TABLE request_occupants MODIFY id_proof_type ENUM('.self::WITHOUT.') NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE request_documents DROP CONSTRAINT IF EXISTS request_documents_doc_type_check');
            DB::statement('ALTER TABLE request_documents ADD CONSTRAINT request_documents_doc_type_check CHECK (doc_type IN ('.self::WITHOUT.'))');

            DB::statement('ALTER TABLE request_occupants DROP CONSTRAINT IF EXISTS request_occupants_id_proof_type_check');
            DB::statement('ALTER TABLE request_occupants ADD CONSTRAINT request_occupants_id_proof_type_check CHECK (id_proof_type IN ('.self::WITHOUT.'))');
        }
    }
};
