<?php

namespace App\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        protected int $status = 400,
        protected array $errors = [],
        protected array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function forbidden(string $message = 'Forbidden.'): self
    {
        return new self($message, 403);
    }

    public static function badRequest(string $message, array $errors = []): self
    {
        return new self($message, 400, $errors);
    }

    public static function unprocessable(string $message, array $errors = []): self
    {
        return new self($message, 422, $errors);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
