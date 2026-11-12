<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->boolean('pending_confirmation')->default(false)->after('subscribed');
        });

        Schema::table('audience_groups', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('recipients', fn (Blueprint $t) => $t->dropColumn('pending_confirmation'));
        Schema::table('audience_groups', fn (Blueprint $t) => $t->dropColumn('is_public'));
    }
};
