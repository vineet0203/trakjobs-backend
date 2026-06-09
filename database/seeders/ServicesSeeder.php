<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServicesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $services = [
            [
                'title' => 'Plumbing Services',
                'subtitle' => 'Expert plumbing solutions',
                'image' => 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=80&h=80&fit=crop',
                'category' => 'Home Services',
                'price' => 'PKR 1,500+',
                'location' => 'Lahore, Pakistan',
                'detailed_address' => 'Gulberg III, Lahore, Pakistan',
                'latitude' => 31.5204,
                'longitude' => 74.3587,
                'status' => 'Published',
                'featured' => true,
                'sort_order' => 1,
            ],
            [
                'title' => 'Cleaning Services',
                'subtitle' => 'Professional cleaning',
                'image' => 'https://images.unsplash.com/photo-1581578731548-c64695cc6952?w=80&h=80&fit=crop',
                'category' => 'Home Services',
                'price' => 'PKR 2,000+',
                'location' => 'Karachi, Pakistan',
                'detailed_address' => 'Clifton, Karachi, Pakistan',
                'latitude' => 24.8138,
                'longitude' => 67.0326,
                'status' => 'Published',
                'featured' => true,
                'sort_order' => 2,
            ],
            [
                'title' => 'AC Repair & Maintenance',
                'subtitle' => 'Cool your life',
                'image' => 'https://images.unsplash.com/photo-1631083366506-a0ae8f58bfdb?w=80&h=80&fit=crop',
                'category' => 'Repair Services',
                'price' => 'PKR 2,500+',
                'location' => 'Islamabad, Pakistan',
                'detailed_address' => 'F-6 Markaz, Islamabad, Pakistan',
                'latitude' => 33.7294,
                'longitude' => 73.0931,
                'status' => 'Published',
                'featured' => false,
                'sort_order' => 3,
            ],
            [
                'title' => 'Car Washing',
                'subtitle' => 'Premium car wash',
                'image' => 'https://images.unsplash.com/photo-1520340356584-f9917d1eea6f?w=80&h=80&fit=crop',
                'category' => 'Automotive',
                'price' => 'PKR 800+',
                'location' => 'Lahore, Pakistan',
                'detailed_address' => 'DHA Phase 5, Lahore, Pakistan',
                'latitude' => 31.4697,
                'longitude' => 74.4098,
                'status' => 'Published',
                'featured' => true,
                'sort_order' => 4,
            ],
            [
                'title' => 'Electrical Services',
                'subtitle' => 'Safe & reliable work',
                'image' => 'https://images.unsplash.com/photo-1621905251918-48416bd8575a?w=80&h=80&fit=crop',
                'category' => 'Home Services',
                'price' => 'PKR 1,800+',
                'location' => 'Rawalpindi, Pakistan',
                'detailed_address' => 'Saddar, Rawalpindi, Pakistan',
                'latitude' => 33.5951,
                'longitude' => 73.0543,
                'status' => 'Draft',
                'featured' => false,
                'sort_order' => 5,
            ],
            [
                'title' => 'Painting Services',
                'subtitle' => 'Give a new look',
                'image' => 'https://images.unsplash.com/photo-1562259929-b4e1fd3aef09?w=80&h=80&fit=crop',
                'category' => 'Home Services',
                'price' => 'PKR 2,200+',
                'location' => 'Multan, Pakistan',
                'detailed_address' => 'Gulgasht Colony, Multan, Pakistan',
                'latitude' => 30.1575,
                'longitude' => 71.5249,
                'status' => 'Published',
                'featured' => true,
                'sort_order' => 6,
            ],
            [
                'title' => 'Moving Services',
                'subtitle' => 'Safe & fast moving',
                'image' => 'https://images.unsplash.com/photo-1600518464441-9154a4dea21b?w=80&h=80&fit=crop',
                'category' => 'Other Services',
                'price' => 'PKR 3,000+',
                'location' => 'Karachi, Pakistan',
                'detailed_address' => 'Gulshan-e-Iqbal, Karachi, Pakistan',
                'latitude' => 24.9180,
                'longitude' => 67.0971,
                'status' => 'Pending',
                'featured' => false,
                'sort_order' => 7,
            ],
        ];

        foreach ($services as $service) {
            $category = \App\Models\ServiceCategory::where('name', $service['category'])->first();
            if ($category) {
                $subName = null;
                if ($service['title'] === 'Plumbing Services') $subName = 'Plumbing';
                elseif ($service['title'] === 'Cleaning Services') $subName = 'Cleaning';
                elseif ($service['title'] === 'AC Repair & Maintenance') $subName = 'AC Repair';
                elseif ($service['title'] === 'Car Washing') $subName = 'Car Washing';
                elseif ($service['title'] === 'Electrical Services') $subName = 'Electrical';
                elseif ($service['title'] === 'Painting Services') $subName = 'Painting';
                elseif ($service['title'] === 'Moving Services') $subName = 'Moving';

                if ($subName) {
                    $subCat = \App\Models\ServiceSubCategory::where('service_category_id', $category->id)
                        ->where('name', $subName)
                        ->first();
                    if ($subCat) {
                        $service['sub_category_id'] = $subCat->id;
                        $service['sub_category'] = $subCat->name;
                    }
                }
            }

            Service::updateOrCreate(
                ['title' => $service['title']],
                $service
            );
        }
    }
}
