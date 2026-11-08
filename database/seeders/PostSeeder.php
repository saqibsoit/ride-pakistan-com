<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SettingKey;
use App\Models\Post;
use App\Support\Settings;
use Illuminate\Database\Seeder;

/**
 * Three starter guides as drafts with outlines only. Final copy is written
 * in the admin; publishing sets published_at.
 */
class PostSeeder extends Seeder
{
    public function run(Settings $settings): void
    {
        $author = $settings->string(SettingKey::TrainerName);

        $guides = [
            'horse-riding-lessons-islamabad-beginners-guide' => [
                'Horse riding lessons in Islamabad: a beginner’s guide',
                'What a first riding lesson in Islamabad looks like, what it costs, and how to go from first touch to trail riding.',
                'meet.jpg',
                [
                    'The short answer' => '- Where, when, price, who it is for (2–3 sentences)',
                    'What happens in your first lesson' => "- Bringing the horse in\n- Grooming and tacking up\n- The ride\n- Cooling down",
                    'Do you need any experience?' => '- No; how groundwork builds confidence',
                    'Single session or package?' => '- Link to prices and the 8-class package',
                    'How to book' => '- Link to the booking page',
                ],
            ],
            'what-to-wear-first-horse-riding-lesson' => [
                'What to wear to your first horse riding lesson',
                'Simple kit for a first ride: trousers, shoes and layers.',
                'ride.jpg',
                [
                    'The short answer' => '- Long trousers, closed shoes with a small heel (equipment we provide: to confirm, see docs/OPEN-QUESTIONS.md)',
                    'Trousers' => '- Why full-length; jeans vs riding trousers',
                    'Shoes and boots' => '- Why a small heel; what to avoid',
                    'Layers and weather' => '- Summer and winter in Islamabad',
                    'What to leave at home' => '- Scarves, loose jewellery',
                    'What we provide' => '- To confirm with Omar before writing (helmets, tack)',
                ],
            ],
            'private-vs-group-riding-lessons' => [
                'Private vs group riding lessons: which is right for you?',
                'How private and group sessions differ in price, pace and what you learn, and how to choose.',
                'gallop.jpg',
                [
                    'The short answer' => '- One-paragraph comparison with prices',
                    'How group sessions work' => '- Riders per slot (setting, to confirm), shared trainer',
                    'How private sessions work' => '- Whole slot, your pace',
                    'Which suits you?' => "- Nervous beginners\n- Families and couples\n- Riders with a goal",
                    'Prices compared' => '- Link to prices',
                ],
            ],
        ];

        foreach ($guides as $slug => [$title, $excerpt, $cover, $outline]) {
            $body = [];

            foreach ($outline as $heading => $notes) {
                $body[] = ['type' => 'text', 'data' => ['heading' => $heading, 'content' => $notes]];
            }

            Post::query()->firstOrCreate(['slug' => $slug], [
                'title' => $title,
                'excerpt' => $excerpt,
                'body' => $body,
                'cover_image' => $cover,
                'author_name' => $author,
                'published_at' => null,
            ]);
        }
    }
}
