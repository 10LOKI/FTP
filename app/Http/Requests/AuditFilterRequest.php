<?php

namespace App\Http\Requests;

use App\Services\PricingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class AuditFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows($this->routeIs('audit.export') ? 'AUDIT_EXPORT' : 'AUDIT_READ');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'month' => [$this->routeIs('audit.export') ? 'required' : 'sometimes', 'date_format:Y-m'],
            'from' => ['sometimes', 'date', 'regex:/(?:Z|[+-]\d{2}:\d{2})$/'],
            'to' => ['sometimes', 'date', 'regex:/(?:Z|[+-]\d{2}:\d{2})$/', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'action' => ['sometimes', 'string', 'max:255'],
            'object_type' => ['sometimes', 'string', 'max:255'],
            'object_id' => ['sometimes', 'string', 'max:255'],
            'result' => ['sometimes', Rule::in(['SUCCESS', 'REFUSED', 'FAILED'])],
            'cursor' => ['sometimes', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                try {
                    $cursor = Cursor::fromEncoded($value);
                    $values = $cursor?->toArray() ?? [];
                    if (count($values) !== 3 || ! is_bool($values['_pointsToNextItems'] ?? null)
                        || ! is_string($values['id'] ?? null) || ! Str::isUuid($values['id'])
                        || ! is_string($values['occurred_at'] ?? null)) {
                        $fail('Le curseur du journal est invalide. Rechargez la première page.');

                        return;
                    }
                    $timestamp = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $values['occurred_at'], 'UTC');
                    if (! $timestamp || $timestamp->format('Y-m-d H:i:s') !== $values['occurred_at']) {
                        $fail('Le curseur du journal est invalide. Rechargez la première page.');
                    }
                } catch (\Throwable) {
                    $fail('Le curseur du journal est invalide. Rechargez la première page.');
                }
            }],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function apply(Builder $query): Builder
    {
        $values = $this->validated();
        if (isset($values['month'])) {
            try {
                PricingPolicy::requireTimezoneData(config('audit.timezone'));
            } catch (ServiceUnavailableHttpException) {
                abort(503, 'Les données de fuseau horaire doivent être actualisées avant l’export mensuel.');
            }
            $start = CarbonImmutable::createFromFormat('!Y-m', $values['month'], config('audit.timezone'));
            $query->where('occurred_at', '>=', $start->utc())->where('occurred_at', '<', $start->addMonth()->utc());
        }
        foreach (['actor_id', 'action', 'object_type', 'object_id', 'result'] as $field) {
            if (isset($values[$field])) {
                $query->where($field, $values[$field]);
            }
        }
        if (isset($values['from'])) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($values['from'])->utc());
        }
        if (isset($values['to'])) {
            $query->where('occurred_at', '<=', CarbonImmutable::parse($values['to'])->utc());
        }

        return $query;
    }
}
