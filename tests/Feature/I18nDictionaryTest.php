<?php

declare(strict_types=1);

function dictionary(string $locale): array
{
    return json_decode(file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
}

function placeholders(string $text): array
{
    preg_match_all('/:[A-Za-z_]+/', $text, $matches);
    $found = array_unique($matches[0]);
    sort($found);

    return $found;
}

test('both dictionaries have the same keys', function () {
    expect(array_keys(dictionary('es')))->toEqualCanonicalizing(array_keys(dictionary('en')));
});

test('every key uses the same placeholders in both languages', function () {
    $es = dictionary('es');
    $en = dictionary('en');

    foreach ($es as $key => $text) {
        expect(placeholders($en[$key]))->toBe(placeholders($text), $key);
    }
});

test('every key belongs to a known group', function () {
    $groups = ['nav', 'hero', 'status', 'week', 'strip', 'lb', 'res', 'donate', 'props', 'cal', 'admin', 'account', 'login', 'link', 'mail', 'foot', 'ui', 'noscript', 'error', 'meta'];

    foreach (array_keys(dictionary('es')) as $key) {
        expect(explode('.', $key)[0])->toBeIn($groups);
    }
});

test('no text is empty', function () {
    foreach (['es', 'en'] as $locale) {
        foreach (dictionary($locale) as $key => $text) {
            expect(trim($text))->not->toBe('', "{$locale}:{$key}");
        }
    }
});
