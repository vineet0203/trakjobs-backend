<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_otps', function (Blueprint $table) {
            $table->string('authenticatable_type')->nullable()->after('contact_destination');
            $table->unsignedBigInteger('authenticatable_id')->nullable()->after('authenticatable_type');
            $table->index(
                ['authenticatable_type', 'authenticatable_id', 'contact_type', 'contact_destination'],
                'verification_otp_owner_contact_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('verification_otps', function (Blueprint $table) {
            $table->dropIndex('verification_otp_owner_contact_idx');
            $table->dropColumn(['authenticatable_type', 'authenticatable_id']);
        });
    }
};
