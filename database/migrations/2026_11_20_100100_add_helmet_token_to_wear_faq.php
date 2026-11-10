<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Riders bring their own helmet (decided 2026-11-10, prompt 14). The FAQ
 * "What should I wear?" gets the {helmet} token, filled from the
 * policies.helmet_notice setting, so the wording lives in one place. An
 * answer an admin already changed to mention helmets is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $faq = DB::table('faqs')->where('question', 'What should I wear?')->first(['id', 'answer']);

        if ($faq === null || ! is_string($faq->answer) || str_contains(strtolower($faq->answer), 'helmet')) {
            return;
        }

        DB::table('faqs')->where('id', $faq->id)->update([
            'answer' => rtrim($faq->answer).' {helmet}',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $faq = DB::table('faqs')->where('question', 'What should I wear?')->first(['id', 'answer']);

        if ($faq !== null && is_string($faq->answer) && str_ends_with($faq->answer, ' {helmet}')) {
            DB::table('faqs')->where('id', $faq->id)->update(['answer' => substr($faq->answer, 0, -strlen(' {helmet}'))]);
        }
    }
};
