<?php

declare(strict_types=1);

use App\Actions\Leads\LeadReport;
use App\Enums\LeadSource;
use App\Enums\OrderStatus;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;

it('counts leads per Pakistan week and source', function (): void {
    // Wednesday 12:00 Pakistan time.
    $now = CarbonImmutable::parse('2026-11-11 12:00', 'Asia/Karachi');
    $this->travelTo($now);

    // Monday 00:30 PKT is Sunday 19:30 UTC: still this week in Pakistan.
    Lead::factory()->instagramDm()->create(['created_at' => CarbonImmutable::parse('2026-11-09 00:30', 'Asia/Karachi')->utc()]);
    Lead::factory()->create(['created_at' => CarbonImmutable::parse('2026-11-08 23:30', 'Asia/Karachi')->utc()]);
    Lead::factory()->create(['created_at' => $now->subWeeks(20)]);

    $report = (new LeadReport(4))->weeklyBySource($now);

    expect($report['weeks'])->toBe(['2026-10-19', '2026-10-26', '2026-11-02', '2026-11-09'])
        ->and($report['series'][LeadSource::InstagramDm->value])->toBe([0, 0, 0, 1])
        ->and($report['series'][LeadSource::ContactForm->value])->toBe([0, 0, 1, 0]);
});

it('reports conversion and net revenue per source, each order counted once', function (): void {
    $now = CarbonImmutable::parse('2026-11-11 12:00', 'UTC');
    $this->travelTo($now);

    $customer = User::factory()->create();
    Lead::factory()->instagramDm()->create(['user_id' => $customer->id, 'created_at' => $now->subDays(10)]);
    Lead::factory()->create(['user_id' => $customer->id, 'created_at' => $now->subDays(5)]);
    Lead::factory()->create(); // not converted

    // Paid before the contact-form lead: only Instagram converted by it.
    Order::factory()->status(OrderStatus::Paid)->create(['user_id' => $customer->id, 'amount_minor' => 6_000_000, 'created_at' => $now->subDays(8)]);
    // After both: converts the contact-form lead too; revenue still first touch.
    Order::factory()->status(OrderStatus::PartiallyRefunded)->create(['user_id' => $customer->id, 'amount_minor' => 1_000_000, 'amount_refunded_minor' => 400_000, 'created_at' => $now->subDays(2), 'paid_at' => $now->subDays(2)]);
    // Unpaid orders never count.
    Order::factory()->status(OrderStatus::PendingPayment)->create(['user_id' => $customer->id, 'created_at' => $now->subDay()]);

    $rows = collect((new LeadReport(4))->bySource($now))->keyBy(fn (array $row): string => $row['source']->value);

    expect($rows[LeadSource::InstagramDm->value])
        ->leads->toBe(1)
        ->converted->toBe(1)
        ->rate->toBe(100.0)
        ->revenue_minor->toBe(6_600_000)
        ->and($rows[LeadSource::ContactForm->value])
        ->leads->toBe(2)
        ->converted->toBe(1)
        ->rate->toBe(50.0)
        ->revenue_minor->toBe(0);
});
