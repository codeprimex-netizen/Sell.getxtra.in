<?php

declare(strict_types=1);

namespace App\Support\Validation;

/**
 * Minimal, dependency-free input validator.
 *
 * Rules are declared per field as a pipe-delimited string, e.g.
 * 'required|email|max:190'. Collects human-readable error messages so
 * controllers can re-render forms. Not a replacement for domain rules.
 */
final class Validator
{
    /** @var array<string, array<int, string>> field => messages */
    private array $errors = [];

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     */
    public function __construct(
        private array $data,
        private array $rules,
    ) {
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     */
    public static function make(array $data, array $rules): self
    {
        $validator = new self($data, $rules);
        $validator->validate();
        return $validator;
    }

    public function validate(): bool
    {
        $this->errors = [];

        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            foreach (explode('|', $ruleString) as $rule) {
                $this->applyRule($field, $value, $rule);
            }
        }

        return $this->passes();
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string, array<int, string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $messages) {
            return $messages[0] ?? null;
        }
        return null;
    }

    private function applyRule(string $field, mixed $value, string $rule): void
    {
        $param = null;
        if (str_contains($rule, ':')) {
            [$rule, $param] = explode(':', $rule, 2);
        }

        $label = ucfirst(str_replace('_', ' ', $field));
        $isEmpty = $value === null || $value === '';

        switch ($rule) {
            case 'required':
                if ($isEmpty) {
                    $this->addError($field, "{$label} is required.");
                }
                break;

            case 'email':
                if (!$isEmpty && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "{$label} must be a valid email address.");
                }
                break;

            case 'min':
                if (!$isEmpty && mb_strlen((string) $value) < (int) $param) {
                    $this->addError($field, "{$label} must be at least {$param} characters.");
                }
                break;

            case 'max':
                if (!$isEmpty && mb_strlen((string) $value) > (int) $param) {
                    $this->addError($field, "{$label} may not exceed {$param} characters.");
                }
                break;

            case 'confirmed':
                $confirmation = $this->data[$field . '_confirmation'] ?? null;
                if ($value !== $confirmation) {
                    $this->addError($field, "{$label} confirmation does not match.");
                }
                break;

            case 'same':
                if ($value !== ($this->data[$param] ?? null)) {
                    $this->addError($field, "{$label} must match {$param}.");
                }
                break;

            case 'in':
                $allowed = explode(',', (string) $param);
                if (!$isEmpty && !in_array((string) $value, $allowed, true)) {
                    $this->addError($field, "{$label} is invalid.");
                }
                break;

            case 'numeric':
                if (!$isEmpty && !is_numeric($value)) {
                    $this->addError($field, "{$label} must be numeric.");
                }
                break;

            case 'accepted':
                if (!in_array($value, ['1', 'on', 'true', true, 1], true)) {
                    $this->addError($field, "{$label} must be accepted.");
                }
                break;
        }
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
