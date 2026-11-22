<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\DonationPlatform;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DonationUrl implements ValidationRule
{
    public function __construct(private readonly DonationPlatform $platform) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->accepts($value)) {
            $fail('validation.donation_url')->translate(['domain' => $this->platform->domain()]);
        }
    }

    public function accepts(string $value): bool
    {
        if ($value !== trim($value) || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1) {
            return false;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        $domain = $this->platform->domain();

        return $host === $domain || $host === "www.{$domain}";
    }
}
