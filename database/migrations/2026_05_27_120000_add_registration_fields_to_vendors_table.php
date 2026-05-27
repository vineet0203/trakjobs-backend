<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('service_category')->nullable()->after('service_description');
            $table->string('service_sub_category')->nullable()->after('service_category');
            $table->string('service_category_custom')->nullable()->after('service_sub_category');
            $table->string('service_sub_category_custom')->nullable()->after('service_category_custom');

            $table->string('availability_type')->nullable()->after('service_sub_category_custom');
            $table->json('availability_days')->nullable()->after('availability_type');
            $table->time('office_start_time')->nullable()->after('availability_days');
            $table->time('office_end_time')->nullable()->after('office_start_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'service_category',
                'service_sub_category',
                'service_category_custom',
                'service_sub_category_custom',
                'availability_type',
                'availability_days',
                'office_start_time',
                'office_end_time',
            ]);
        });
    }
};
