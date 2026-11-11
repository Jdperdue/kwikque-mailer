<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('name')->nullable();
            $table->string('external_id')->nullable();
            $table->string('source', 50)->default('manual');
            $table->boolean('subscribed')->default(true);
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->string('suppression_reason', 50)->nullable();
            $table->string('consent_source')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'external_id']);
        });

        Schema::create('recipient_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audience_group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'recipient_id', 'audience_group_id'], 'recipient_group_unique');
        });

        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('source', 50)->default('manual');
            $table->string('type', 30)->default('auction'); // auction | course | event
            $table->string('title');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source', 'external_id']);
            $table->index(['tenant_id', 'is_active', 'starts_at']);
        });

        // Permanent per-tenant opt-out / block record. Survives deleting the recipient.
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('reason', 50); // unsubscribe | manual | bounce | complaint | import
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
        });

        // Global (platform-wide): hard bounces and complaints. Not tenant-owned by design.
        Schema::create('platform_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email', 254)->unique();
            $table->string('reason', 50); // bounce | complaint
            $table->foreignId('first_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tenant_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // content | audience
            $table->string('adapter');
            $table->text('settings')->nullable(); // encrypted:array cast, depends on APP_KEY
            $table->string('schedule')->nullable();
            $table->string('last_status')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audience_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 50)->default('csv');
            $table->string('filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->string('status', 20)->default('pending'); // pending | running | completed | failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('protected_count')->default(0); // suppressed/unsubscribed, left as opted out
            $table->json('errors')->nullable(); // capped list of {row, email, reason}
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'import_runs', 'tenant_sources', 'platform_suppressions', 'suppressions', 'content_items', 'recipient_group', 'recipients', 'audience_groups'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
