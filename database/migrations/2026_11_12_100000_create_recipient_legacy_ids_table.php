<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy-app identifiers. The legacy app had separate bidder, client and email-list tables, so the same
     * number can mean different people: ids are stored per kind and a recipient may hold one of each.
     */
    public function up(): void
    {
        Schema::create('recipient_legacy_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // bidder | client | email
            $table->string('legacy_id', 50);
            $table->timestamps();

            $table->unique(['tenant_id', 'recipient_id', 'kind']);
            $table->index(['tenant_id', 'kind', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_legacy_ids');
    }
};
