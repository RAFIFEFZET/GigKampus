<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

final class PasswordText implements DataAwareRule, ValidationRule
{
    public bool $implicit = true;

    private array $data = [];

    public function __construct(
        private readonly int $min = 8,
        private readonly bool $confirmed = false,
        private readonly ?string $message = null,
    ) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) < $this->min || mb_strlen($value) > 128) {
            $fail($this->message ?? "Kata sandi harus berisi {$this->min}–128 karakter.");

            return;
        }

        if ($this->confirmed && $value !== ($this->data[$attribute.'_confirmation'] ?? null)) {
            $fail('Konfirmasi kata sandi harus sama.');
        }
    }
}
