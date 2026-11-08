<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Enums\SettingKey;
use App\Models\Faq;
use App\Models\Post;
use App\Models\Product;
use App\Models\Review;
use App\Models\ScheduleRule;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Spatie\SchemaOrg\Article;
use Spatie\SchemaOrg\BreadcrumbList;
use Spatie\SchemaOrg\FAQPage;
use Spatie\SchemaOrg\ItemAvailability;
use Spatie\SchemaOrg\OpeningHoursSpecification;
use Spatie\SchemaOrg\Organization;
use Spatie\SchemaOrg\Person;
use Spatie\SchemaOrg\Product as ProductSchema;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\SportsActivityLocation;
use Spatie\SchemaOrg\WebSite;

/**
 * Builds schema.org nodes from our data (settings, products, schedule
 * rules, FAQs, posts). Nodes reference each other by @id so a page's graph
 * stays consistent across the site.
 */
final class SchemaFactory
{
    private const DAYS = [
        0 => 'https://schema.org/Sunday',
        1 => 'https://schema.org/Monday',
        2 => 'https://schema.org/Tuesday',
        3 => 'https://schema.org/Wednesday',
        4 => 'https://schema.org/Thursday',
        5 => 'https://schema.org/Friday',
        6 => 'https://schema.org/Saturday',
    ];

    public function __construct(private readonly Settings $settings) {}

    public static function organizationId(): string
    {
        return url('/').'#organization';
    }

    public static function websiteId(): string
    {
        return url('/').'#website';
    }

    public static function locationId(): string
    {
        return url('/').'#location';
    }

    public static function trainerId(): string
    {
        return route('trainer').'#person';
    }

    public function organization(): Organization
    {
        return Schema::organization()
            ->identifier(self::organizationId())
            ->name($this->settings->string(SettingKey::BusinessName))
            ->url(url('/'))
            ->logo(asset('images/logo.png'))
            ->email($this->settings->string(SettingKey::Email))
            ->sameAs(array_values(array_filter([
                $this->settings->string(SettingKey::InstagramUrl),
                $this->settings->string(SettingKey::ExpeditionsUrl),
            ])))
            ->parentOrganization(
                Schema::organization()->name($this->settings->string(SettingKey::ParentCompany)),
            );
    }

    public function website(): WebSite
    {
        return Schema::webSite()
            ->identifier(self::websiteId())
            ->url(url('/'))
            ->name($this->settings->string(SettingKey::BusinessName))
            ->inLanguage(str_replace('_', '-', app()->getLocale()))
            ->publisher(Schema::organization()->identifier(self::organizationId()));
    }

    /**
     * The riding location: area served and opening hours from active rules.
     * No street address is published (PLAN §2, §8).
     *
     * @param  Collection<int, ScheduleRule>  $rules
     * @param  Collection<int, Product>  $products
     */
    public function location(Collection $rules, Collection $products): SportsActivityLocation
    {
        $location = $this->settings->string(SettingKey::Location);

        $hours = $rules
            ->groupBy(fn (ScheduleRule $rule): string => $rule->startTime().'-'.$rule->endTime())
            ->map(fn (Collection $group): OpeningHoursSpecification => Schema::openingHoursSpecification()
                // schema.org wants plain "HH:MM" times and day URLs here.
                ->setProperty('dayOfWeek', $group->map(fn (ScheduleRule $rule): string => self::DAYS[$rule->day_of_week])->unique()->values()->all())
                ->setProperty('opens', $group->first()?->startTime() ?? '')
                ->setProperty('closes', $group->first()?->endTime() ?? ''))
            ->values()
            ->all();

        $node = Schema::sportsActivityLocation()
            ->identifier(self::locationId())
            ->name($this->settings->string(SettingKey::BusinessName))
            ->description($this->settings->string(SettingKey::Tagline))
            ->url(url('/'))
            ->image(asset('images/hero.jpg'))
            ->email($this->settings->string(SettingKey::Email))
            ->areaServed(Schema::city()->name($location))
            ->address(Schema::postalAddress()->addressLocality($location)->addressCountry('PK'))
            ->parentOrganization(Schema::organization()->identifier(self::organizationId()))
            ->openingHoursSpecification($hours);

        if ($products->isNotEmpty()) {
            $node->priceRange(
                Money::format((int) $products->min('price_minor')).' – '.Money::format((int) $products->max('price_minor')),
            );
        }

        return $node;
    }

    public function trainer(): Person
    {
        return Schema::person()
            ->identifier(self::trainerId())
            ->name($this->settings->string(SettingKey::TrainerName))
            ->jobTitle('Horse riding trainer')
            ->url(route('trainer'))
            ->knowsAbout(['Horsemanship', 'Trail riding', 'Endurance riding', 'Cross-country riding'])
            ->worksFor(Schema::organization()->identifier(self::organizationId()));
    }

    /**
     * Product with its offer. With at least Review::AGGREGATE_MINIMUM approved
     * reviews of this product (milestone 10d), also its AggregateRating and
     * up to five of those reviews. Product pages are where Google allows
     * review snippets; business-level pages never get one.
     *
     * @param  iterable<Review>  $reviews  approved reviews of this product
     * @param  array{count: int, average: float}|null  $summary
     */
    public function product(Product $product, iterable $reviews = [], ?array $summary = null): ProductSchema
    {
        $node = $this->productNode($product);

        if ($summary === null || $summary['count'] < Review::AGGREGATE_MINIMUM) {
            return $node;
        }

        $items = [];

        foreach ($reviews as $review) {
            if (count($items) === 5) {
                break;
            }

            $items[] = Schema::review()
                ->reviewRating(Schema::rating()->ratingValue($review->rating)->bestRating(5)->worstRating(1))
                ->author(Schema::person()->name($review->display_name))
                ->reviewBody($review->body)
                ->datePublished($review->created_at);
        }

        return $node
            ->aggregateRating(Schema::aggregateRating()
                ->ratingValue($summary['average'])
                ->reviewCount($summary['count'])
                ->bestRating(5)
                ->worstRating(1))
            ->review($items);
    }

    private function productNode(Product $product): ProductSchema
    {
        $offer = Schema::offer()
            ->url(route('sessions.show', $product))
            ->price(Money::decimal($product->price_minor))
            ->priceCurrency('PKR')
            ->setProperty('availability', ItemAvailability::InStock)
            ->seller(Schema::organization()->identifier(self::organizationId()));

        return Schema::product()
            ->identifier(route('sessions.show', $product).'#product')
            ->name($product->name)
            ->description($product->short_description)
            ->url(route('sessions.show', $product))
            ->image(asset('images/'.($product->cover_image ?? 'ride.jpg')))
            ->category($product->category->label())
            ->brand(Schema::organization()->identifier(self::organizationId()))
            ->offers($offer);
    }

    /**
     * @param  iterable<Faq>  $faqs
     */
    public function faqPage(iterable $faqs, string $url): FAQPage
    {
        $questions = [];

        foreach ($faqs as $faq) {
            $questions[] = Schema::question()
                ->name($faq->question)
                ->acceptedAnswer(Schema::answer()->text($faq->answer));
        }

        return Schema::fAQPage()
            ->identifier($url.'#faq')
            ->url($url)
            ->mainEntity($questions);
    }

    /**
     * @param  list<array{label: string, url?: string|null}>  $items
     */
    public function breadcrumbs(array $items, string $currentUrl): BreadcrumbList
    {
        $elements = [];

        foreach ($items as $index => $item) {
            $elements[] = Schema::listItem()
                ->position($index + 1)
                ->name($item['label'])
                ->setProperty('item', $item['url'] ?? $currentUrl);
        }

        return Schema::breadcrumbList()
            ->identifier($currentUrl.'#breadcrumb')
            ->itemListElement($elements);
    }

    public function article(Post $post): Article
    {
        $url = route('guides.show', $post);

        $article = Schema::article()
            ->identifier($url.'#article')
            ->headline($post->title)
            ->description($post->excerpt ?? '')
            ->url($url)
            ->mainEntityOfPage($url)
            ->inLanguage(str_replace('_', '-', app()->getLocale()))
            ->author(Schema::person()->identifier(self::trainerId())->name($post->author_name))
            ->publisher(Schema::organization()->identifier(self::organizationId()))
            ->image(asset('images/'.($post->cover_image ?? 'meet.jpg')));

        if ($post->published_at !== null) {
            $article->datePublished($post->published_at);
        }

        if ($post->updated_at !== null) {
            $article->dateModified($post->updated_at);
        }

        return $article;
    }
}
