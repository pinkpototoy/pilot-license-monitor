<?php

namespace App\Domain;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A business-rule failure with field-level messages. Rendered as a normal
 * validation error in forms and as RFC 9457 problem details in the API.
 */
class DomainRuleViolation extends RuntimeException
{
    /** @param array<string, string> $errors field => message (message may start with a rule ID) */
    public function __construct(public readonly array $errors, public readonly bool $needsConfirmation = false)
    {
        parent::__construct(implode(' ', $errors));
    }

    public static function on(string $field, string $message, bool $needsConfirmation = false): self
    {
        return new self([$field => $message], $needsConfirmation);
    }

    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages($this->errors);
    }
}
