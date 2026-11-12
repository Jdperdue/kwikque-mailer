<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('subject');
            $table->string('preheader')->nullable();
            $table->text('intro')->nullable();
            $table->string('status', 20)->default('draft'); // draft | scheduled (sending states arrive in Phase 3)
            $table->timestamp('scheduled_at')->nullable(); // UTC
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'scheduled_at']);
        });

        Schema::create('campaign_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audience_group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'campaign_id', 'audience_group_id'], 'campaign_group_unique');
        });

        Schema::create('campaign_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'campaign_id', 'content_item_id'], 'campaign_item_unique');
        });
    }

    public function down(): void
    {
        foreach (['campaign_items', 'campaign_groups', 'campaigns'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
