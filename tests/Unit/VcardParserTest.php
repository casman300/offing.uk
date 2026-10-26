<?php

use App\Support\Contacts\VcardImportException;
use App\Support\Contacts\VcardParser;
use Carbon\CarbonImmutable;

function parseFixture(string $name, string $today = '2026-10-16'): array
{
    $rows = (new VcardParser(CarbonImmutable::parse($today)))->parse(fopen(__DIR__."/../Fixtures/vcards/{$name}.vcf", 'r'));

    return collect($rows)->keyBy('name')->all();
}

function parseString(string $vcf): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $vcf);
    rewind($stream);

    return (new VcardParser(CarbonImmutable::parse('2026-10-16')))->parse($stream);
}

it('prefers the nickname, and matches on nickname and full name', function () {
    $sue = parseFixture('iphone')['Auntie Sue'];

    expect($sue['match_names'])->toBe(['auntie sue', 'susan smith'])
        ->and($sue['birthdays'])->toBe([['day' => 2, 'month' => 10, 'year' => 1983]]);
});

it('keeps only names, birthdays and notes', function () {
    $sue = parseFixture('iphone')['Auntie Sue'];

    expect(array_keys($sue))->toBe(['key', 'name', 'match_names', 'birthdays', 'notes', 'warnings', 'cards'])
        ->and(json_encode($sue))->not->toContain('7700')->not->toContain('sue@example.com')->not->toContain('High St')->not->toContain('9j/4AAQ');
});

it('reads year-less birthdays, including Apple’s 1604 placeholder', function () {
    $rows = parseFixture('iphone');

    expect($rows['Tom Jones']['birthdays'])->toBe([['day' => 7, 'month' => 6, 'year' => null]])
        ->and($rows['Tom Jones']['warnings'])->toBe([])
        ->and(parseFixture('vcard4')['Ann Four']['birthdays'])->toBe([['day' => 25, 'month' => 12, 'year' => null]]);
});

it('reads basic and timestamped date formats', function () {
    expect(parseFixture('iphone')['Zoë Müller']['birthdays'])->toBe([['day' => 12, 'month' => 1, 'year' => 1985]])
        ->and(parseFixture('vcard4')['Ben Four']['birthdays'])->toBe([['day' => 1, 'month' => 1, 'year' => 1990]]);
});

it('drops out-of-range years but keeps the date, with a note', function () {
    $olive = parseFixture('iphone')['Olive Old'];

    expect($olive['birthdays'])->toBe([['day' => 5, 'month' => 5, 'year' => null]])
        ->and($olive['warnings'])->toBe(['year_ignored']);

    $future = parseString("BEGIN:VCARD\nVERSION:3.0\nFN:Baby\nBDAY:2026-12-01\nEND:VCARD\n")[0];
    expect($future['birthdays'])->toBe([['day' => 1, 'month' => 12, 'year' => null]])
        ->and($future['warnings'])->toBe(['year_ignored']);
});

it('keeps 29 February in a leap year, drops an impossible date', function () {
    $rows = parseFixture('iphone');

    expect($rows['Lily Leap']['birthdays'])->toBe([['day' => 29, 'month' => 2, 'year' => 2000]])
        ->and($rows['Bad Date']['birthdays'])->toBe([])
        ->and($rows['Bad Date']['warnings'])->toBe(['invalid_date']);
});

it('lists contacts without a birthday', function () {
    expect(parseFixture('iphone')['The Plumber']['birthdays'])->toBe([]);
});

it('collapses duplicate names, keeping disagreeing birthdays as alternatives', function () {
    $rows = parseFixture('iphone');

    expect($rows['Tess Twin']['cards'])->toBe(2)
        ->and($rows['Tess Twin']['birthdays'])->toHaveCount(2)
        ->and($rows['Tess Twin']['warnings'])->toContain('duplicate_in_file')
        // Same day+month, one with a year: one birthday, the year wins.
        ->and($rows['Dup Same']['birthdays'])->toBe([['day' => 8, 'month' => 8, 'year' => 1975]]);
});

it('normalises case, spacing and accents', function () {
    expect(VcardParser::normalise('  ZOË   Müller '))->toBe('zoe muller');
});

it('rejects files with no contacts or that are not vCards', function (string $fixture, string $message) {
    expect(fn () => parseFixture($fixture))->toThrow(VcardImportException::class, $message);
})->with([
    ['empty', 'couldn’t find any contacts'],
    ['not-a-vcard', 'doesn’t look like a contacts'],
]);

it('caps the number of cards', function () {
    $vcf = str_repeat("BEGIN:VCARD\nVERSION:3.0\nFN:X\nEND:VCARD\n", VcardParser::MAX_CARDS + 1);

    expect(fn () => parseString($vcf))->toThrow(VcardImportException::class, 'more than');
});

it('takes the first of several nicknames and builds a name from N when FN is missing', function () {
    $rows = parseString("BEGIN:VCARD\nVERSION:3.0\nN:Doe;Jane;;;\nNICKNAME:JJ,Janie\nEND:VCARD\nBEGIN:VCARD\nVERSION:3.0\nN:Roe;Rick;;;\nEND:VCARD\n");

    expect($rows[0]['name'])->toBe('JJ')
        ->and($rows[0]['match_names'])->toBe(['jj', 'jane doe'])
        ->and($rows[1]['name'])->toBe('Rick Roe');
});
