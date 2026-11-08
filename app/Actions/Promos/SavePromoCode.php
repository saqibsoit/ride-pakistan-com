<?php

declare(strict_types=1);

namespace App\Actions\Promos;

use App\Enums\DiscountType;
use App\Models\PromoCode;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a promo code (milestone 10e, admins). Percentage
 * 1–100 or a fixed PKR amount; optional products (none = all), window,
 * total and per-customer limits, minimum order and first-booking-only.
 * Once a code has been used its terms (code, type, value, products) are
 * fixed so past discounts stay explainable; dates, limits and the active
 * switch can still change, and limits never go below the uses so far.
 * Applying codes at checkout is Part B.
 */
final class SavePromoCode
{
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9_-]{2,39}$/';

    /**
     * @param  array{code?: mixed, description?: mixed, discount_type?: mixed, percent?: mixed, amount_pkr?: mixed, starts_at?: mixed, ends_at?: mixed, max_uses?: mixed, max_uses_per_customer?: mixed, min_order_pkr?: mixed, first_booking_only?: mixed, is_active?: mixed, product_ids?: mixed}  $data
     *
     * @throws ValidationException
     */
    public function handle(?PromoCode $promo, array $data, User $admin): PromoCode
    {
        $data['code'] = PromoCode::normaliseCode((string) ($data['code'] ?? ''));

        if (($data['discount_type'] ?? null) instanceof DiscountType) {
            $data['discount_type'] = $data['discount_type']->value;
        }
        $used = $promo !== null ? $promo->redemptions()->count() : 0;

        $validated = Validator::make($data, [
            'code' => ['required', 'string', 'regex:'.self::CODE_PATTERN, Rule::unique('promo_codes', 'code')->ignore($promo?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', Rule::enum(DiscountType::class)],
            'percent' => ['exclude_unless:discount_type,percent', 'required', 'integer', 'between:1,100'],
            'amount_pkr' => ['exclude_unless:discount_type,fixed', 'required', 'integer', 'min:1', 'max:10000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'max_uses' => ['nullable', 'integer', 'min:'.max(1, $used)],
            'max_uses_per_customer' => ['nullable', 'integer', 'min:1'],
            'min_order_pkr' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'first_booking_only' => ['boolean'],
            'is_active' => ['boolean'],
            'product_ids' => ['array'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')],
        ], [
            'code.regex' => 'Use 3 to 40 letters, numbers, - or _, starting with a letter or number.',
            'max_uses.min' => 'The limit can\'t be lower than the '.$used.' times the code has been used.',
            'ends_at.after' => 'The end must be after the start.',
        ])->validate();

        $type = DiscountType::from((string) $validated['discount_type']);
        $productIds = array_values(array_unique(array_map('intval', (array) ($validated['product_ids'] ?? []))));
        sort($productIds);

        $terms = [
            'code' => (string) $validated['code'],
            'discount_type' => $type,
            'percent' => $type === DiscountType::Percent ? (int) $validated['percent'] : null,
            'amount_minor' => $type === DiscountType::Fixed ? (int) $validated['amount_pkr'] * Money::MINOR_PER_MAJOR : null,
        ];

        if ($promo !== null && $used > 0) {
            $current = $promo->products()->pluck('products.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

            if ($terms['code'] !== $promo->code || $terms['discount_type'] !== $promo->discount_type
                || $terms['percent'] !== $promo->percent || $terms['amount_minor'] !== $promo->amount_minor
                || $productIds !== $current) {
                throw ValidationException::withMessages([
                    'code' => 'This code has been used, so its code, discount and products can\'t change. Archive it and create a new one.',
                ]);
            }
        }

        $attributes = [
            ...$terms,
            'description' => self::nullable($validated['description'] ?? null),
            'starts_at' => self::date($validated['starts_at'] ?? null),
            'ends_at' => self::date($validated['ends_at'] ?? null),
            'max_uses' => self::int($validated['max_uses'] ?? null),
            'max_uses_per_customer' => self::int($validated['max_uses_per_customer'] ?? null),
            'min_order_minor' => ($min = self::int($validated['min_order_pkr'] ?? null)) === null ? null : $min * Money::MINOR_PER_MAJOR,
            'first_booking_only' => (bool) ($validated['first_booking_only'] ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];

        return DB::transaction(function () use ($promo, $attributes, $productIds, $admin): PromoCode {
            $creating = $promo === null;
            $promo ??= new PromoCode;
            $promo->fill($attributes);
            $promo->save();
            $promo->products()->sync($productIds);

            activity('promos')
                ->performedOn($promo)
                ->causedBy($admin)
                ->event($creating ? 'created' : 'updated')
                ->withProperties([...array_map(fn (mixed $v): mixed => $v instanceof \BackedEnum ? $v->value : ($v instanceof CarbonImmutable ? $v->toIso8601String() : $v), $attributes), 'product_ids' => $productIds])
                ->log(($creating ? 'Promo code created: ' : 'Promo code updated: ').$promo->code);

            return $promo;
        });
    }

    private static function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse((string) $value, 'UTC');
    }
}
