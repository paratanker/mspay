<?php

namespace App\Domain\Payments\Parsing;

final class CommandParser
{
    /**
     * @var array<string, int>
     */
    private const COMMANDS = [
        'CREATE' => 4,
        'AUTHORIZE' => 1,
        'CAPTURE' => 1,
        'VOID' => 1,
        'REFUND' => 1,
        'SETTLE' => 1,
        'SETTLEMENT' => 1,
        'STATUS' => 1,
        'AUDIT' => 1,
        'LIST' => 0,
        'EXIT' => 0,
    ];

    public function parse(string $line): ParseResult
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return ParseResult::ignored();
        }

        $tokens = preg_split('/\s+/', $trimmed);

        if ($tokens === false || $tokens === []) {
            return ParseResult::error('Malformed command line');
        }

        $command = $tokens[0] ?? null;

        if (!is_string($command) || $command === '') {
            return ParseResult::error('Malformed command line');
        }

        // Check if the command start with #
        if(str_starts_with($command, '#')) {
            return ParseResult::error('Malformed command line (invalid comment position)');
        }

        if (!array_key_exists($command, self::COMMANDS)) {
            return ParseResult::error("Unknown command: {$command}");
        }

        $requiredArgs = self::COMMANDS[$command];
        $minTokens = 1 + $requiredArgs;

        foreach ($tokens as $index => $token) {
            if (!is_string($token) || !str_starts_with($token, '#')) {
                continue;
            }

            // comment allowed ONLY after required args
            if ($index >= $minTokens) {
                $tokens = array_slice($tokens, 0, $index);
                break;
            }

            return ParseResult::error('Malformed command line (invalid comment position)');
        }

        $name = array_shift($tokens);

        if (!is_string($name) || $name === '') {
            return ParseResult::error('Malformed command line');
        }

        /** @var list<string> $arguments */
        $arguments = array_values(array_map(
            static fn (string $value): string => trim($value),
            $tokens
        ));

        return ParseResult::command(
            new ParsedCommand($name, $arguments)
        );
    }
}