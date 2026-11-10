<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');
            $table->json('branding')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_address')->nullable();
            $table->string('reply_to')->nullable();
            $table->text('postal_address')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('mailgun_domain')->nullable()->unique();
            $table->string('domain_status')->default('unverified');
            $table->timestamp('domain_verified_at')->nullable();
            $table->json('send_limits')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
        });

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
        Schema::dropIfExists('tenants');
    }
};
