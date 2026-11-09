<?php

namespace Database\Seeders;

use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LeadStage;
use Illuminate\Database\Seeder;

class CrmSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            ['code' => 'facebook', 'name_ar' => 'فيسبوك', 'name_en' => 'Facebook'],
            ['code' => 'instagram', 'name_ar' => 'إنستغرام', 'name_en' => 'Instagram'],
            ['code' => 'whatsapp', 'name_ar' => 'واتساب', 'name_en' => 'WhatsApp'],
            ['code' => 'website', 'name_ar' => 'الموقع الإلكتروني', 'name_en' => 'Website'],
            ['code' => 'phone', 'name_ar' => 'اتصال هاتفي', 'name_en' => 'Phone'],
            ['code' => 'walk_in', 'name_ar' => 'زيارة للفرع', 'name_en' => 'Walk-in'],
            ['code' => 'referral', 'name_ar' => 'توصية عميل', 'name_en' => 'Referral'],
            ['code' => 'other', 'name_ar' => 'أخرى', 'name_en' => 'Other'],
        ];

        foreach ($sources as $source) {
            LeadSource::query()->updateOrCreate(['code' => $source['code']], $source + ['is_active' => true]);
        }

        $stages = [
            ['code' => 'new', 'name_ar' => 'جديد', 'name_en' => 'New', 'sort_order' => 10],
            ['code' => 'contacted', 'name_ar' => 'تم التواصل', 'name_en' => 'Contacted', 'sort_order' => 20],
            ['code' => 'qualified', 'name_ar' => 'مؤهل', 'name_en' => 'Qualified', 'sort_order' => 30],
            ['code' => 'proposal', 'name_ar' => 'عرض سعر', 'name_en' => 'Proposal', 'sort_order' => 40],
            ['code' => 'won', 'name_ar' => 'تم البيع', 'name_en' => 'Won', 'sort_order' => 50, 'is_won' => true],
            ['code' => 'lost', 'name_ar' => 'خسارة', 'name_en' => 'Lost', 'sort_order' => 60, 'is_lost' => true],
        ];

        foreach ($stages as $stage) {
            LeadStage::query()->updateOrCreate(
                ['code' => $stage['code']],
                $stage + ['is_won' => false, 'is_lost' => false],
            );
        }
    }
}
