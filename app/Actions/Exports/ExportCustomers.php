<?php

declare(strict_types=1);

namespace App\Actions\Exports;

use App\Enums\ExportFormat;
use App\Enums\LeadSource;
use App\Models\User;
use App\Support\CustomerStats;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * The admin Customers list as CSV or Excel (prompt 14): the customers the
 * table's filters select, with the same figures as its columns
 * (CustomerStats). Admins only, logged like every export (log `exports`);
 * the caller applies the shared 10-an-hour limit. Text cells go through
 * RunExport::cell (formula guard).
 */
final class ExportCustomers
{
    /** @return list<string> */
    public static function headers(): array
    {
        return ['Joined (Pakistan time)', 'Name', 'Email', 'Phone', 'WhatsApp', 'Role', 'Bookings', 'Total paid (PKR)', 'Last booking (Pakistan time)', 'Lead source', 'Marketing consent', 'Account'];
    }

    /**
     * @param  Builder<User>  $selection  the filtered table query (any columns, any order)
     * @param  array<string, mixed>  $filters  what the admin filtered by, for the audit log
     *
     * @throws AuthorizationException
     */
    public function handle(Builder $selection, ExportFormat $format, User $actor, array $filters = []): ExportFile
    {
        if (! $actor->can('export-data')) {
            throw new AuthorizationException('Only an admin can export data.');
        }

        $path = tempnam(sys_get_temp_dir(), 'rp-export-');

        if ($path === false) {
            throw new InvalidArgumentException('Could not create the export file.');
        }

        $tz = (string) config('app.display_timezone');
        $date = fn (mixed $at): string => $at === null ? '' : CarbonImmutable::parse($at instanceof CarbonInterface ? $at : (string) $at, 'UTC')->setTimezone($tz)->format('Y-m-d H:i');

        $writer = $format === ExportFormat::Xlsx ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::headers()));
        $rows = 0;

        $ids = (clone $selection)->reorder()->select('users.id');

        CustomerStats::apply(User::withTrashed()->whereIn('users.id', $ids))
            ->orderBy('users.created_at')->orderBy('users.id')
            ->chunk(500, function ($users) use ($writer, $date, &$rows): void {
                foreach ($users as $user) {
                    /** @var User $user */
                    $source = LeadSource::tryFrom((string) $user->getAttribute('lead_source'));
                    $values = [
                        $date($user->created_at),
                        $user->name ?? '',
                        $user->email,
                        $user->phone ?? '',
                        $user->whatsapp_phone ?? '',
                        $user->role->getLabel(),
                        (string) (int) $user->getAttribute('bookings_count'),
                        Money::decimal((int) $user->getAttribute('total_paid_minor')),
                        $date($user->getAttribute('last_booked_at')),
                        $source?->label() ?? '',
                        $user->marketing_consent ? 'Yes' : 'No',
                        $user->isAnonymised() ? 'Anonymised' : ($user->trashed() ? 'Deletion requested' : 'Active'),
                    ];
                    $writer->addRow(Row::fromValues(array_map(RunExport::cell(...), $values)));
                    $rows++;
                }
            });

        $writer->close();

        $filename = sprintf('ride-pakistan-customers-%s.%s', CarbonImmutable::now($tz)->toDateString(), $format->value);

        activity('exports')
            ->causedBy($actor)
            ->event('exported')
            ->withProperties(['dataset' => 'customers', 'filters' => $filters, 'format' => $format->value, 'rows' => $rows])
            ->log(sprintf('Exported %d customers (%s)', $rows, strtoupper($format->value)));

        return new ExportFile($path, $filename, $format->mimeType(), $rows);
    }
}
