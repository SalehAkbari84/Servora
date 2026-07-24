<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Categories
        $categories = [
            ['name' => 'آرایشگاه'],
            ['name' => 'دندانپزشکی'],
            ['name' => 'پزشکی عمومی'],
            ['name' => 'روانشناسی'],
            ['name' => 'فیزیوتراپی'],
            ['name' => 'کلینیک زیبایی'],
            ['name' => 'دامپزشکی'],
            ['name' => 'مشاوره حقوقی'],
        ];

        foreach ($categories as $cat) {
            DB::table('categories')->insert([
                'name' => $cat['name'],
                'parent_id' => null,
                'is_active' => true,
            ]);
        }

        $catIds = DB::table('categories')->pluck('id', 'name');

        // ── Admin user ─────────────────────────────────────────
        // Lives in AdminSeeder so a buyer's setup.bat can create the primary
        // admin WITHOUT the demo data below. Idempotent.
        $this->call(AdminSeeder::class);

        // ── Test customer ──────────────────────────────────────
        DB::table('users')->insert([
            'full_name'     => 'مشتری تست',
            'phone'         => '09120000001',
            'password_hash' => Hash::make('Test@1234'),
            'role'          => 'مشتری',
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Business owner users
        $owners = [
            ['full_name' => 'علی محمدی',    'phone' => '09121000001'],
            ['full_name' => 'مریم رضایی',    'phone' => '09121000002'],
            ['full_name' => 'حسین کریمی',    'phone' => '09121000003'],
            ['full_name' => 'فاطمه احمدی',   'phone' => '09121000004'],
            ['full_name' => 'رضا نجفی',      'phone' => '09121000005'],
            ['full_name' => 'سارا موسوی',    'phone' => '09121000006'],
            ['full_name' => 'محمد حسینی',    'phone' => '09121000007'],
            ['full_name' => 'زهرا قاسمی',    'phone' => '09121000008'],
        ];

        $ownerIds = [];
        foreach ($owners as $owner) {
            $ownerIds[] = DB::table('users')->insertGetId([
                'full_name'     => $owner['full_name'],
                'phone'         => $owner['phone'],
                'password_hash' => Hash::make('Owner@1234'),
                'role'          => 'کسب‌وکار',
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // Businesses
        $businesses = [
            [
                'owner_idx'       => 0,
                'name'            => 'آرایشگاه مدرن',
                'category'        => 'آرایشگاه',
                'gender_type'     => 'مرد',
                'description'     => 'آرایشگاه مدرن با بهترین متخصصان در تهران. خدمات کوتاهی مو، رنگ و مدل.',
                'address_text'    => 'تهران، خیابان ولیعصر، پلاک ۱۲',
                'phone'           => '02177001001',
                'rating_avg'      => 4.5,
                'rating_sum'      => 45,
                'rating_count'    => 10,
                'total_reviews'   => 10,
                'is_verified'     => true,
            ],
            [
                'owner_idx'       => 1,
                'name'            => 'کلینیک دندانپزشکی دکتر رضایی',
                'category'        => 'دندانپزشکی',
                'gender_type'     => 'هر دو',
                'description'     => 'خدمات تخصصی دندانپزشکی شامل ایمپلنت، ارتودنسی و کامپوزیت.',
                'address_text'    => 'تهران، شریعتی، نبش کوچه هفتم',
                'phone'           => '02177002002',
                'rating_avg'      => 4.8,
                'rating_sum'      => 96,
                'rating_count'    => 20,
                'total_reviews'   => 20,
                'is_verified'     => true,
            ],
            [
                'owner_idx'       => 2,
                'name'            => 'مطب دکتر کریمی — پزشک عمومی',
                'category'        => 'پزشکی عمومی',
                'gender_type'     => 'هر دو',
                'description'     => 'ویزیت بیماران عمومی، معاینه و تجویز دارو. نوبت‌دهی آنلاین.',
                'address_text'    => 'تهران، پونک، بلوار اصلی',
                'phone'           => '02188003003',
                'rating_avg'      => 4.2,
                'rating_sum'      => 42,
                'rating_count'    => 10,
                'total_reviews'   => 10,
                'is_verified'     => true,
            ],
            [
                'owner_idx'       => 3,
                'name'            => 'مرکز مشاوره روانشناختی آرامش',
                'category'        => 'روانشناسی',
                'gender_type'     => 'زن',
                'description'     => 'مشاوره فردی، زوج‌درمانی و خانواده‌درمانی توسط روانشناسان مجرب.',
                'address_text'    => 'تهران، سعادت‌آباد، میدان کاج',
                'phone'           => '02122004004',
                'rating_avg'      => 4.9,
                'rating_sum'      => 49,
                'rating_count'    => 10,
                'total_reviews'   => 10,
                'is_verified'     => true,
            ],
            [
                'owner_idx'       => 4,
                'name'            => 'کلینیک فیزیوتراپی نوین',
                'category'        => 'فیزیوتراپی',
                'gender_type'     => 'هر دو',
                'description'     => 'درمان دردهای عضلانی، کمردرد، زانودرد و توانبخشی بعد از جراحی.',
                'address_text'    => 'تهران، تجریش، پایین‌تر از میدان',
                'phone'           => '02122005005',
                'rating_avg'      => 4.6,
                'rating_sum'      => 46,
                'rating_count'    => 10,
                'total_reviews'   => 10,
                'is_verified'     => true,
            ],
            [
                'owner_idx'       => 5,
                'name'            => 'آرایشگاه بانوان گل رز',
                'category'        => 'آرایشگاه',
                'gender_type'     => 'زن',
                'description'     => 'آرایشگاه زنانه با خدمات کامل: مو، ناخن، ابرو، آرایش عروس.',
                'address_text'    => 'تهران، نارمک، خیابان رزها',
                'phone'           => '02177006006',
                'rating_avg'      => 4.7,
                'rating_sum'      => 47,
                'rating_count'    => 10,
                'total_reviews'   => 10,
                'is_verified'     => true,
            ],
            [
                'owner_idx'       => 6,
                'name'            => 'کلینیک زیبایی پرستو',
                'category'        => 'کلینیک زیبایی',
                'gender_type'     => 'زن',
                'description'     => 'لیزر موهای زائد، بوتاکس، فیلر و خدمات لاغری موضعی.',
                'address_text'    => 'تهران، الهیه، خیابان فرشته',
                'phone'           => '02122007007',
                'rating_avg'      => 4.4,
                'rating_sum'      => 44,
                'rating_count'    => 10,
                'total_reviews'   => 10,
                'is_verified'     => true,
            ],
            [
                'owner_idx'       => 7,
                'name'            => 'دفتر حقوقی وکیل قاسمی',
                'category'        => 'مشاوره حقوقی',
                'gender_type'     => 'هر دو',
                'description'     => 'مشاوره حقوقی در زمینه دعاوی ملکی، خانوادگی و تجاری.',
                'address_text'    => 'تهران، میرداماد، برج نگین',
                'phone'           => '02122008008',
                'rating_avg'      => 4.3,
                'rating_sum'      => 43,
                'rating_count'    => 10,
                'total_reviews'   => 10,
                'is_verified'     => true,
            ],
        ];

        $businessIds = [];
        foreach ($businesses as $b) {
            $catName = $b['category'];
            $catId   = $catIds[$catName] ?? null;
            $ownerId = $ownerIds[$b['owner_idx']];
            $ownerName  = $owners[$b['owner_idx']]['full_name'];
            $ownerPhone = $owners[$b['owner_idx']]['phone'];

            $businessIds[] = DB::table('businesses')->insertGetId([
                'owner_user_id'   => $ownerId,
                'owner_name'      => $ownerName,
                'owner_phone'     => $ownerPhone,
                'name'            => $b['name'],
                'category_id'     => $catId,
                'category_name'   => $catName,
                'subcategory_id'  => null,
                'subcategory_name'=> '',
                'gender_type'     => $b['gender_type'],
                'description'     => $b['description'],
                'address_text'    => $b['address_text'],
                'phone'           => $b['phone'],
                'is_verified'     => $b['is_verified'],
                'is_active'       => true,
                'rating_avg'      => $b['rating_avg'],
                'rating_sum'      => $b['rating_sum'],
                'rating_count'    => $b['rating_count'],
                'total_reviews'   => $b['total_reviews'],
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        // Services per business
        $servicesByBusiness = [
            // آرایشگاه مدرن
            0 => [
                ['service_name' => 'کوتاهی مو',      'duration_minutes' => 30, 'price' => 120000, 'gender_type' => 'مرد'],
                ['service_name' => 'رنگ مو',          'duration_minutes' => 90, 'price' => 350000, 'gender_type' => 'مرد'],
                ['service_name' => 'اصلاح ریش',       'duration_minutes' => 20, 'price' =>  80000, 'gender_type' => 'مرد'],
            ],
            // دندانپزشکی
            1 => [
                ['service_name' => 'ویزیت و معاینه',  'duration_minutes' => 30, 'price' => 200000, 'gender_type' => 'هر دو'],
                ['service_name' => 'جرم‌گیری',        'duration_minutes' => 45, 'price' => 400000, 'gender_type' => 'هر دو'],
                ['service_name' => 'کامپوزیت دندان',  'duration_minutes' => 60, 'price' => 800000, 'gender_type' => 'هر دو'],
            ],
            // پزشک عمومی
            2 => [
                ['service_name' => 'ویزیت عمومی',     'duration_minutes' => 20, 'price' => 150000, 'gender_type' => 'هر دو'],
                ['service_name' => 'تجدید نسخه',      'duration_minutes' => 10, 'price' =>  80000, 'gender_type' => 'هر دو'],
            ],
            // روانشناسی
            3 => [
                ['service_name' => 'مشاوره فردی',     'duration_minutes' => 50, 'price' => 500000, 'gender_type' => 'زن'],
                ['service_name' => 'زوج‌درمانی',      'duration_minutes' => 90, 'price' => 800000, 'gender_type' => 'هر دو'],
            ],
            // فیزیوتراپی
            4 => [
                ['service_name' => 'ارزیابی اولیه',   'duration_minutes' => 45, 'price' => 300000, 'gender_type' => 'هر دو'],
                ['service_name' => 'درمان کمردرد',     'duration_minutes' => 60, 'price' => 450000, 'gender_type' => 'هر دو'],
                ['service_name' => 'الکتروتراپی',      'duration_minutes' => 30, 'price' => 250000, 'gender_type' => 'هر دو'],
            ],
            // آرایشگاه بانوان
            5 => [
                ['service_name' => 'کوتاهی مو',       'duration_minutes' => 45, 'price' => 200000, 'gender_type' => 'زن'],
                ['service_name' => 'رنگ و هایلایت',   'duration_minutes' => 120, 'price' => 600000, 'gender_type' => 'زن'],
                ['service_name' => 'اصلاح ابرو',       'duration_minutes' => 20, 'price' =>  80000, 'gender_type' => 'زن'],
                ['service_name' => 'آرایش عروس',       'duration_minutes' => 180, 'price' => 2000000, 'gender_type' => 'زن'],
            ],
            // کلینیک زیبایی
            6 => [
                ['service_name' => 'لیزر موهای زائد', 'duration_minutes' => 30, 'price' => 500000, 'gender_type' => 'زن'],
                ['service_name' => 'بوتاکس',          'duration_minutes' => 30, 'price' => 1200000, 'gender_type' => 'زن'],
                ['service_name' => 'فیلر لب',         'duration_minutes' => 30, 'price' => 1500000, 'gender_type' => 'زن'],
            ],
            // وکیل
            7 => [
                ['service_name' => 'مشاوره ۳۰ دقیقه', 'duration_minutes' => 30, 'price' => 500000, 'gender_type' => 'هر دو'],
                ['service_name' => 'مشاوره ۶۰ دقیقه', 'duration_minutes' => 60, 'price' => 900000, 'gender_type' => 'هر دو'],
            ],
        ];

        foreach ($servicesByBusiness as $bizIdx => $services) {
            $bizId   = $businessIds[$bizIdx];
            $bizName = $businesses[$bizIdx]['name'];
            $catName = $businesses[$bizIdx]['category'];
            foreach ($services as $svc) {
                DB::table('services')->insert([
                    'business_id'      => $bizId,
                    'business_name'    => $bizName,
                    'service_name'     => $svc['service_name'],
                    'category_name'    => $catName,
                    'gender_type'      => $svc['gender_type'],
                    'duration_minutes' => $svc['duration_minutes'],
                    'price'            => $svc['price'],
                    'is_active'        => true,
                    'created_at'       => now(),
                ]);
            }
        }

        // Business slots: Sat–Wed (0=Sat,1=Sun,...,4=Wed), 09:00–18:00, every 30 min
        $days = [0, 1, 2, 3, 4]; // شنبه تا چهارشنبه
        $slots = [];
        for ($h = 9; $h < 18; $h++) {
            $slots[] = sprintf('%02d:00', $h);
            $slots[] = sprintf('%02d:30', $h);
        }

        foreach ($businessIds as $idx => $bizId) {
            $bizName = $businesses[$idx]['name'];
            foreach ($days as $day) {
                foreach ($slots as $slot) {
                    DB::table('business_slots')->insert([
                        'business_id'   => $bizId,
                        'business_name' => $bizName,
                        'day_of_week'   => $day,
                        'time_slot'     => $slot,
                        'max_capacity'  => 2,
                        'is_active'     => true,
                    ]);
                }
            }
        }

        $this->command->info('Seeded: 1 admin, 1 customer, 8 owners, 8 categories, 8 businesses, services, and slots.');
        $this->command->info('Admin login:    09112801486 / Jackrichard@1384');
        $this->command->info('Customer login: 09120000001 / Test@1234');
        $this->command->info('Owner login:    09121000001..8 / Owner@1234');
    }
}
