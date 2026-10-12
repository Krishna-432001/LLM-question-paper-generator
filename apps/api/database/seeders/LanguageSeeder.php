<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

final class LanguageSeeder extends Seeder
{
    /**
     * MVP ships English + Tamil active. The rest are seeded inactive so
     * enabling a language later is a flag flip plus fonts and a glossary,
     * not a migration.
     */
    private const LANGUAGES = [
        ['en', 'English', 'English', 'Latn', 'Noto Sans', true, 1],
        ['ta', 'Tamil', 'தமிழ்', 'Taml', 'Noto Sans Tamil', true, 2],
        ['te', 'Telugu', 'తెలుగు', 'Telu', 'Noto Sans Telugu', false, 3],
        ['ml', 'Malayalam', 'മലയാളം', 'Mlym', 'Noto Sans Malayalam', false, 4],
        ['hi', 'Hindi', 'हिन्दी', 'Deva', 'Noto Sans Devanagari', false, 5],
        ['kn', 'Kannada', 'ಕನ್ನಡ', 'Knda', 'Noto Sans Kannada', false, 6],
        ['mr', 'Marathi', 'मराठी', 'Deva', 'Noto Sans Devanagari', false, 7],
        ['bn', 'Bengali', 'বাংলা', 'Beng', 'Noto Sans Bengali', false, 8],
        ['gu', 'Gujarati', 'ગુજરાતી', 'Gujr', 'Noto Sans Gujarati', false, 9],
        ['or', 'Odia', 'ଓଡ଼ିଆ', 'Orya', 'Noto Sans Oriya', false, 10],
        ['pa', 'Punjabi', 'ਪੰਜਾਬੀ', 'Guru', 'Noto Sans Gurmukhi', false, 11],
        ['ur', 'Urdu', 'اردو', 'Arab', 'Noto Nastaliq Urdu', false, 12],
    ];

    public function run(): void
    {
        foreach (self::LANGUAGES as [$code, $name, $native, $script, $font, $active, $order]) {
            Language::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'native_name' => $native,
                    'script' => $script,
                    'direction' => $code === 'ur' ? 'rtl' : 'ltr',
                    'font_family' => $font,
                    'is_active' => $active,
                    'sort_order' => $order,
                ],
            );
        }
    }
}
