<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gapless document numbers per branch and document type (MAIN-ADJ-000001).
     * Shared by all modules through App\Services\DocumentNumberService.
     */
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 10);
            $table->unsignedBigInteger('last_number')->default(0);

            $table->primary(['branch_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
