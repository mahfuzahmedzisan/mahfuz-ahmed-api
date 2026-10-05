<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar')->nullable()->after('email');
            $table->boolean('email_notifications')->default(true)->after('avatar');
            $table->boolean('push_notifications')->default(false)->after('email_notifications');
            $table->string('theme', 16)->default('system')->after('push_notifications');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'avatar',
                'email_notifications',
                'push_notifications',
                'theme',
            ]);
        });
    }
};
