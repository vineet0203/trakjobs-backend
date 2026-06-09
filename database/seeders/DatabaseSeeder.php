<?php


namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();

        $this->call([
            SystemUserSeeder::class,
            SystemDataSeeder::class,
            AdminUsersSeeder::class,
            PasswordSecuritySettingsSeeder::class,
            DocumentTemplateSeeder::class,
            ServiceCategorySeeder::class,
            ServicesSeeder::class,
        ]);

        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
    }
}
