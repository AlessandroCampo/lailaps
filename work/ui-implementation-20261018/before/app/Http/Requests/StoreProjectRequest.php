<?php

namespace App\Http\Requests;

use App\Enums\ProjectSourceType;
use App\Services\ZipService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;

    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'source_type' => ['required', Rule::in([ProjectSourceType::ZIP->value])],
            'source' => ['required', 'file', 'mimes:zip', 'max:'.ZipService::MAX_UPLOAD_KB],
        ];
    }
}
