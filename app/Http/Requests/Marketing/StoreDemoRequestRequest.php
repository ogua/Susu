<?php

namespace App\Http\Requests\Marketing;

use App\Enums\DemoOrganisationType;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDemoRequestRequest extends FormRequest
{
    /** Humans take longer than this to fill the form; most bots don't. */
    public const MINIMUM_FILL_SECONDS = 3;

    /** Name of the hidden honeypot field — only bots fill it in. */
    public const HONEYPOT_FIELD = 'website';

    /** Name of the hidden, encrypted "form rendered at" timestamp field. */
    public const STARTED_FIELD = 'form_started';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'organisation' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50', 'regex:/^[0-9+()\-\s]{7,}$/'],
            'organisation_type' => ['required', Rule::enum(DemoOrganisationType::class)],
            'branches_count' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organisation' => 'organisation name',
            'organisation_type' => 'type of organisation',
            'branches_count' => 'number of branches',
        ];
    }

    /**
     * True when the submission looks automated: the honeypot is filled, or
     * the form came back faster than a person could type it (or without a
     * valid timestamp). Such submissions are dropped without telling the
     * sender, so bots get no signal to adapt to.
     */
    public function looksAutomated(): bool
    {
        if (filled($this->input(self::HONEYPOT_FIELD))) {
            return true;
        }

        try {
            $startedAt = (int) decrypt((string) $this->input(self::STARTED_FIELD));
        } catch (DecryptException) {
            return true;
        }

        return now()->timestamp - $startedAt < self::MINIMUM_FILL_SECONDS;
    }
}
