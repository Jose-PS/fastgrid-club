<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SocialChannelName;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSocialAccountRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    protected $dontFlash = ['webhook_announcements', 'webhook_results', 'app_password', 'access_token'];

    private const string DISCORD_WEBHOOK = '~^https://discord\.com/api/webhooks/\d+/[A-Za-z0-9_\-]+$~';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [
            'enabled' => ['required', 'boolean'],
            'clear' => ['sometimes', 'array'],
            'clear.*' => ['string', 'in:'.implode(',', $this->channel()->secretFields())],
        ];

        $fieldRules = match ($this->channel()) {
            SocialChannelName::Discord => [
                'webhook_announcements' => ['nullable', 'string', 'max:500', 'regex:'.self::DISCORD_WEBHOOK],
                'webhook_results' => ['nullable', 'string', 'max:500', 'regex:'.self::DISCORD_WEBHOOK],
                'announcements_role_id' => ['nullable', 'string', 'max:30', 'regex:/^\d+$/'],
            ],
            SocialChannelName::Bluesky => [
                'identifier' => ['nullable', 'string', 'max:253'],
                'app_password' => ['nullable', 'string', 'max:100'],
            ],
            SocialChannelName::Instagram => [
                'ig_user_id' => ['nullable', 'string', 'max:30', 'regex:/^\d+$/'],
                'access_token' => ['nullable', 'string', 'max:1000'],
            ],
        };

        return [...$rules, ...$fieldRules];
    }

    public function channel(): SocialChannelName
    {
        $channel = $this->route('channel');
        assert($channel instanceof SocialChannelName);

        return $channel;
    }

    /**
     * @return array<string, string|null>
     */
    public function values(): array
    {
        $values = [];

        foreach ($this->channel()->fields() as $field) {
            $value = $this->input($field);
            $values[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    public function cleared(): array
    {
        return array_values(array_filter(
            $this->array('clear'),
            fn (mixed $field): bool => is_string($field),
        ));
    }
}
