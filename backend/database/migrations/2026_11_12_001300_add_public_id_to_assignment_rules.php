<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_rules', function (Blueprint $table) {
            $table->ulid('public_id')->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('assignment_rules', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};
