<?php

namespace App\Domain\Payments\Service;

final class EngineOutput
{
    private array $lines;
    private bool $shouldExit;

    /**
     * @param list<string> $lines
     */
    public function __construct(
        array $lines,
        bool $shouldExit = false
    ) {
        $this->lines = $lines;
        $this->shouldExit = $shouldExit;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function shouldExit(): bool
    {
        return $this->shouldExit;
    }
}
