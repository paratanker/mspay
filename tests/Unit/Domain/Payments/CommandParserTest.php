<?php

namespace Tests\Unit\Domain\Payments;

use App\Domain\Payments\Parsing\CommandParser;
use PHPUnit\Framework\TestCase;

class CommandParserTest extends TestCase
{
    public function test_it_treats_inline_comment_as_comment_from_third_token_position(): void
    {
        $parser = new CommandParser();

        $create = $parser->parse('CREATE P1001 10.00 MYR M01 # test payment');
        $authorize = $parser->parse('AUTHORIZE P1001 # retry');

        $this->assertNull($create->errorValue());
        $createCmd = $create->commandValue();
        $this->assertNotNull($createCmd);
        $this->assertSame('CREATE', $createCmd->name());
        $this->assertSame(['P1001', '10.00', 'MYR', 'M01'], $createCmd->arguments());

        $this->assertNull($authorize->errorValue());
        $authorizeCmd = $authorize->commandValue();
        $this->assertNotNull($authorizeCmd);
        $this->assertSame('AUTHORIZE', $authorizeCmd->name());
        $this->assertSame(['P1001'], $authorizeCmd->arguments());
    }

    public function test_it_treats_hash_at_line_start_as_non_comment(): void
    {
        $parser = new CommandParser();

        $result = $parser->parse('# CREATE P1002 11.00 MYR M01');

        // `#` is not stripped as a comment here; first token is unknown (not a command name).
        $this->assertNull($result->commandValue());
        $this->assertSame('Unknown command: #', $result->errorValue());
    }
}
