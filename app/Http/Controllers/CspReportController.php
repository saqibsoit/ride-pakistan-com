<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * CSP violation reports (POST /csp-report). Browsers send either the
 * legacy `application/csp-report` body ({"csp-report": {...}}) or the
 * Reporting API `application/reports+json` list. No session, no CSRF,
 * rate limited; bodies over csp.report.max_bytes are dropped. Only the
 * fields needed to fix a policy are logged, without query strings or the
 * visitor's IP (PLAN §7: PII minimisation).
 */
final class CspReportController extends Controller
{
    private const FIELDS = [
        'effective-directive', 'violated-directive', 'blocked-uri', 'document-uri',
        'source-file', 'line-number', 'column-number', 'disposition', 'status-code',
    ];

    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();

        if (strlen($body) > (int) config('csp.report.max_bytes', 16384)) {
            return response()->noContent(413);
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return response()->noContent();
        }

        foreach (self::reports($decoded) as $report) {
            Log::channel('csp')->warning('CSP violation', $report);
        }

        return response()->noContent();
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<array<string, string|int>>
     */
    private static function reports(array $decoded): array
    {
        $raw = [];

        if (isset($decoded['csp-report']) && is_array($decoded['csp-report'])) {
            $raw[] = $decoded['csp-report'];
        } elseif (array_is_list($decoded)) {
            foreach (array_slice($decoded, 0, 10) as $item) {
                if (is_array($item) && ($item['type'] ?? null) === 'csp-violation' && is_array($item['body'] ?? null)) {
                    $raw[] = self::fromReportingApi($item['body']);
                }
            }
        }

        return array_map(self::clean(...), $raw);
    }

    /**
     * Reporting API field names (camelCase) mapped to the legacy ones.
     *
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    private static function fromReportingApi(array $body): array
    {
        return [
            'effective-directive' => $body['effectiveDirective'] ?? null,
            'blocked-uri' => $body['blockedURL'] ?? null,
            'document-uri' => $body['documentURL'] ?? null,
            'source-file' => $body['sourceFile'] ?? null,
            'line-number' => $body['lineNumber'] ?? null,
            'column-number' => $body['columnNumber'] ?? null,
            'disposition' => $body['disposition'] ?? null,
            'status-code' => $body['statusCode'] ?? null,
        ];
    }

    /**
     * @param  array<mixed>  $report
     * @return array<string, string|int>
     */
    private static function clean(array $report): array
    {
        $clean = [];

        foreach (self::FIELDS as $field) {
            $value = $report[$field] ?? null;

            if (is_int($value)) {
                $clean[$field] = $value;
            } elseif (is_string($value) && $value !== '') {
                $clean[$field] = mb_substr(self::stripQuery($value), 0, 300);
            }
        }

        return $clean;
    }

    private static function stripQuery(string $value): string
    {
        return preg_replace('/[?#].*$/s', '', $value) ?? $value;
    }
}
