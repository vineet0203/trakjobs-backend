<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add verification_status to actors
        Schema::table('users', function (Blueprint $table) {
            $table->string('verification_status', 30)->default('pending')->after('status');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('verification_status', 30)->default('pending')->after('status');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('verification_status', 30)->default('pending')->after('is_active');
        });

        // 2. Create verification_profiles table
        Schema::create('verification_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('authenticatable_type');
            $table->unsignedBigInteger('authenticatable_id');
            $table->string('status', 30)->default('pending');
            $table->integer('current_step')->default(1);
            $table->json('verification_data')->nullable();
            $table->string('document_type', 50)->nullable();
            $table->string('document_path')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['authenticatable_type', 'authenticatable_id'], 'poly_owner_idx');
        });

        // 3. Create verification_otps table
        Schema::create('verification_otps', function (Blueprint $table) {
            $table->id();
            $table->string('contact_type', 20); // 'email' or 'whatsapp'
            $table->string('contact_destination');
            $table->string('otp_hash');
            $table->integer('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_otps');
        Schema::dropIfExists('verification_profiles');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('verification_status');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('verification_status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('verification_status');
        });
    }
};
