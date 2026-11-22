<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Enums\EventQuestionType;
use App\Exceptions\BookingRejected;

/**
 * Checks and cleans the answers to an event's custom questions (decided
 * 2026-11-22). Only questions the event asks are kept, keyed by the
 * question's key; required ones must be answered, a choice must be one of
 * the options and yes/no is stored as "yes" or "no".
 */
final class EventAnswers
{
    public const MAX_LENGTH = 300;

    /**
     * @param  list<array{key: string, label: string, type: string, options: list<string>, required: bool, scope: string}>  $questions
     * @param  array<array-key, mixed>  $given
     * @return array<string, string>
     *
     * @throws BookingRejected
     */
    public static function clean(array $questions, array $given, string $who = ''): array
    {
        $answers = [];

        foreach ($questions as $question) {
            $raw = $given[$question['key']] ?? null;
            $value = is_scalar($raw) ? trim((string) $raw) : '';

            $value = match (EventQuestionType::tryFrom($question['type'])) {
                EventQuestionType::YesNo => in_array(strtolower($value), ['yes', '1', 'true'], true) ? 'yes' : (in_array(strtolower($value), ['no', '0', 'false'], true) ? 'no' : ''),
                EventQuestionType::Select => in_array($value, $question['options'], true) ? $value : '',
                default => mb_substr($value, 0, self::MAX_LENGTH),
            };

            if ($value === '') {
                if ($question['required']) {
                    throw BookingRejected::eventRule('Please answer "'.$question['label'].'"'.($who === '' ? '' : ' for '.$who).'.');
                }

                continue;
            }

            $answers[$question['key']] = $value;
        }

        return $answers;
    }

    /**
     * Answers with their question labels, for the run sheet, exports and emails.
     *
     * @param  list<array{key: string, label: string}>|array<int, array<string, mixed>>  $questions
     * @param  array<string, string>|null  $answers
     * @return list<array{label: string, answer: string}>
     */
    public static function labelled(array $questions, ?array $answers): array
    {
        $rows = [];

        foreach ($questions as $question) {
            $key = (string) ($question['key'] ?? '');

            if ($key !== '' && isset($answers[$key]) && $answers[$key] !== '') {
                $rows[] = ['label' => (string) ($question['label'] ?? $key), 'answer' => $answers[$key]];
            }
        }

        return $rows;
    }
}
