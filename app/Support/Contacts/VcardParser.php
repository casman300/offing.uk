<?php

namespace App\Support\Contacts;

use App\Support\Birthday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;
use Sabre\VObject\Splitter\VCard as VCardSplitter;

/**
 * Turns a .vcf export into import rows (#14).
 *
 * Only names, birthdays and notes are read; PHOTO, TEL, EMAIL, ADR and the
 * rest are dropped as each card is parsed and never stored.
 *
 * Cards with the same normalised name are collapsed into one row. If they
 * disagree on the birthday, the row carries every distinct birthday as an
 * alternative for the reviewer to pick.
 */
final class VcardParser
{
    public const MAX_CARDS = 5000;

    /** Apple's "no year" placeholder (and X-APPLE-OMIT-YEAR's usual value). */
    private const APPLE_NO_YEAR = 1604;

    public function __construct(private readonly CarbonImmutable $today) {}

    /**
     * @param  resource  $stream
     * @return list<array<string, mixed>>
     *
     * @throws VcardImportException
     */
    public function parse($stream): array
    {
        $splitter = new VCardSplitter($stream, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        $contacts = [];
        $count = 0;

        while (true) {
            try {
                $card = $splitter->getNext();
            } catch (ParseException) {
                throw new VcardImportException('That file doesn’t look like a contacts (.vcf) export.');
            }

            if ($card === null) {
                break;
            }

            if (++$count > self::MAX_CARDS) {
                throw new VcardImportException('That file has more than '.number_format(self::MAX_CARDS).' contacts — export a smaller list.');
            }

            if ($contact = $this->readCard($card)) {
                $contacts[] = $contact;
            }

            $card->destroy();
        }

        if ($count === 0) {
            throw new VcardImportException('We couldn’t find any contacts in that file.');
        }

        return $this->collapse($contacts);
    }

    public static function normalise(string $name): string
    {
        return Str::of(Str::ascii($name))->lower()->squish()->toString();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCard(VCard $card): ?array
    {
        $fn = $this->clean(isset($card->FN) ? (string) $card->FN : null);
        $nickname = $this->clean(isset($card->NICKNAME) ? Str::before((string) $card->NICKNAME, ',') : null);

        if ($fn === null && isset($card->N)) {
            $parts = $card->N->getParts();
            $fn = $this->clean(trim(($parts[1] ?? '').' '.($parts[0] ?? '')));
        }

        $name = $nickname ?? $fn;

        if ($name === null) {
            return null;
        }

        [$birthday, $warnings] = $this->readBirthday($card);

        return [
            'name' => Str::limit($name, 255, ''),
            'fn' => $fn,
            'nickname' => $nickname,
            'birthday' => $birthday,
            'notes' => isset($card->NOTE) ? $this->clean(Str::limit((string) $card->NOTE, 5000, ''), squish: false) : null,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{0: array{day: int, month: int, year: ?int}|null, 1: list<string>}
     */
    private function readBirthday(VCard $card): array
    {
        if (! isset($card->BDAY)) {
            return [null, []];
        }

        $raw = trim((string) $card->BDAY->getValue());

        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $raw, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^--(\d{2})-?(\d{2})/', $raw, $m)) {
            [$year, $month, $day] = [null, (int) $m[1], (int) $m[2]];
        } else {
            return [null, ['invalid_date']];
        }

        $omitYear = $card->BDAY['X-APPLE-OMIT-YEAR'];
        if ($year === self::APPLE_NO_YEAR || ($omitYear !== null && (int) (string) $omitYear === $year)) {
            $year = null;
        }

        if (! Birthday::isValidDate($day, $month, null)) {
            return [null, ['invalid_date']];
        }

        $warnings = [];

        if ($year !== null && (
            $year < Birthday::MIN_YEAR
            || ! Birthday::isValidDate($day, $month, $year)
            || CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC')->gt(Birthday::dateOnly($this->today))
        )) {
            $year = null;
            $warnings[] = 'year_ignored';
        }

        return [['day' => $day, 'month' => $month, 'year' => $year], $warnings];
    }

    /**
     * One row per normalised name; disagreeing birthdays become alternatives.
     *
     * @param  list<array<string, mixed>>  $contacts
     * @return list<array<string, mixed>>
     */
    private function collapse(array $contacts): array
    {
        $rows = [];

        foreach ($contacts as $contact) {
            $key = self::normalise($contact['name']);

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'key' => 'r'.count($rows),
                    'name' => $contact['name'],
                    'match_names' => array_values(array_unique(array_filter([
                        self::normalise($contact['name']),
                        $contact['fn'] ? self::normalise($contact['fn']) : null,
                    ]))),
                    'birthdays' => [],
                    'notes' => null,
                    'warnings' => [],
                    'cards' => 0,
                ];
            }

            $row = &$rows[$key];
            $row['cards']++;
            $row['notes'] ??= $contact['notes'];
            $row['warnings'] = array_values(array_unique([...$row['warnings'], ...$contact['warnings']]));

            if ($contact['fn']) {
                $row['match_names'] = array_values(array_unique([...$row['match_names'], self::normalise($contact['fn'])]));
            }

            if ($contact['birthday'] !== null) {
                $row['birthdays'] = self::addBirthday($row['birthdays'], $contact['birthday']);
            }

            unset($row);
        }

        return array_values(array_map(function (array $row) {
            if ($row['cards'] > 1) {
                $row['warnings'][] = 'duplicate_in_file';
            }

            return $row;
        }, $rows));
    }

    /**
     * Same day+month with a year on one side only is the same birthday —
     * keep the one that knows the year.
     *
     * @param  list<array{day: int, month: int, year: ?int}>  $birthdays
     * @param  array{day: int, month: int, year: ?int}  $new
     * @return list<array{day: int, month: int, year: ?int}>
     */
    private static function addBirthday(array $birthdays, array $new): array
    {
        foreach ($birthdays as $i => $existing) {
            if ($existing['day'] !== $new['day'] || $existing['month'] !== $new['month']) {
                continue;
            }

            if ($existing['year'] === $new['year'] || $new['year'] === null) {
                return $birthdays;
            }

            if ($existing['year'] === null) {
                $birthdays[$i] = $new;

                return $birthdays;
            }
        }

        $birthdays[] = $new;

        return $birthdays;
    }

    private function clean(?string $value, bool $squish = true): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = $squish ? Str::squish($value) : trim($value);

        return $value === '' ? null : $value;
    }
}
