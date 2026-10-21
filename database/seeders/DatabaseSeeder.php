<?php

namespace Database\Seeders;

use App\Enums\AffiliateProgram;
use App\Enums\SocialPlatform;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with the example setup from the project brief.
     * Accounts have no tokens; add real ones through the admin pages.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $amazon = AffiliateAccount::create([
            'program' => AffiliateProgram::Amazon,
            'name' => 'Amazon Associates (global)',
            'tracking_id' => 'example-20',
        ]);

        $shopee = AffiliateAccount::create([
            'program' => AffiliateProgram::Shopee,
            'name' => 'Shopee Affiliate MY',
            'tracking_id' => 'example-my',
        ]);

        $gadgets = SocialAccount::create([
            'platform' => SocialPlatform::Threads,
            'name' => 'Threads #1 (gadgets, EN)',
            'persona' => [
                'language' => 'English',
                'tone' => 'Helpful, concise gadget reviewer. Honest about downsides.',
                'audience' => 'Global shoppers looking for good-value tech',
                'disclosure' => 'Affiliate link: I may earn a small commission at no cost to you.',
                'sample_posts' => [
                    'Best wireless earbuds under $50 right now? I\'d look at these three first.',
                    'This $25 desk lamp has a USB-C port and a dimmer. Small thing, huge upgrade.',
                ],
            ],
        ]);

        $murah = SocialAccount::create([
            'platform' => SocialPlatform::Threads,
            'name' => 'Threads #2 (barang murah, MY)',
            'persona' => [
                'language' => 'Malay (rojak, mixed with English)',
                'tone' => 'Santai, macam kawan share barang best. Tak hard sell.',
                'audience' => 'Malaysian shoppers on Shopee',
                'disclosure' => 'Link affiliate ya, aku dapat komisen sikit kalau korang beli.',
                'sample_posts' => [
                    'Korang, barang murah tapi best ni. Bawah RM30 je, rating 4.9 dengan 10k sold.',
                    'Siapa selalu lupa bawa charger, ni penyelamat. Kecik, ringan, masuk poket.',
                ],
            ],
        ]);

        Campaign::create([
            'name' => 'Gadgets under $50',
            'affiliate_account_id' => $amazon->id,
            'posts_per_day' => 2,
        ])->socialAccounts()->attach($gadgets);

        Campaign::create([
            'name' => 'Barang murah tapi best',
            'affiliate_account_id' => $shopee->id,
            'posts_per_day' => 3,
        ])->socialAccounts()->attach($murah);

        Product::factory()->for($amazon)->create([
            'name' => 'Example wireless earbuds',
            'affiliate_link' => 'https://www.amazon.com/dp/EXAMPLE?tag=example-20',
        ]);

        Product::factory()->for($shopee)->usedPersonally('Dah guna 2 minggu. Cas laju, tapi casing cepat calar.')->create([
            'name' => 'Example powerbank 10000mAh',
            'affiliate_link' => 'https://s.shopee.com.my/example',
        ]);
    }
}
