<?php

namespace App\Domain\Payments\Parsing;

final class ParsedCommand
{
    private string $name;

    private array $arguments;

    public function __construct(string $name, array $arguments) {
        $this->name = $name;
        $this->arguments = $arguments;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }
}
