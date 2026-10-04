<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This closes the authorization gap found in the original API: every
     * route there only checked `auth:api` (authenticated), never *who* the
     * authenticated user was. Any self-registered account could hit admin
     * endpoints. New registrations now default to the non-privileged role;
     * only accounts explicitly promoted (e.g. via UserSeeder) get `admin`.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default(UserRole::User->value)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
