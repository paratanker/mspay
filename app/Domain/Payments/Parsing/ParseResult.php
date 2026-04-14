<?php

namespace App\Domain\Payments\Parsing;

final class ParseResult
{
    private ?ParsedCommand $command;
    private ?string $error;
    private bool $ignored;

    private function __construct(
        ?ParsedCommand $command,
        ?string $error,
        bool $ignored
    ) {
        $this->command = $command;
        $this->error = $error;
        $this->ignored = $ignored;
    }

    public static function command(ParsedCommand $command): self
    {
        return new self($command, null, false);
    }

    public static function error(string $error): self
    {
        return new self(null, $error, false);
    }

    public static function ignored(): self
    {
        return new self(null, null, true);
    }

    public function commandValue(): ?ParsedCommand
    {
        return $this->command;
    }

    public function errorValue(): ?string
    {
        return $this->error;
    }

    public function isIgnored(): bool
    {
        return $this->ignored;
    }
}
