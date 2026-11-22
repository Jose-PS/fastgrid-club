<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\Social\PrepareSocialPosts;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSocialPostTextRequest extends FormRequest
{
    public const int MAX_LENGTH = PrepareSocialPosts::MAX_LENGTH;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    public function text(): string
    {
        return $this->string('text')->toString();
    }
}
