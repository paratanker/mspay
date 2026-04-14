<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PaymentPipelineSimulationCommandTest extends TestCase
{
    public function test_happy_path_create_authorize_capture_settle_status(): void
    {
        $lines = $this->runCommands([
            'CREATE P1001 10.00 MYR M01',
            'AUTHORIZE P1001',
            'CAPTURE P1001',
            'SETTLE P1001',
            'STATUS P1001',
        ]);

        $out = $this->joinedOutput($lines);
        $this->assertStringContainsString('OK CREATE P1001 INITIATED', $out);
        $this->assertStringContainsString('OK AUTHORIZE P1001 AUTHORIZED', $out);
        $this->assertStringContainsString('OK CAPTURE P1001 CAPTURED', $out);
        $this->assertStringContainsString('OK SETTLE P1001 SETTLED', $out);
        $this->assertStringContainsString('P1001', $out);
        $this->assertStringContainsString('SETTLED', $out);
        $this->assertStringContainsString('10.00', $out);
        $this->assertStringContainsString('MYR', $out);
        $this->assertStringContainsString('M01', $out);
    }

    public function test_happy_path_create_authorize_void(): void
    {
        $lines = $this->runCommands([
            'CREATE P1002 11.00 MYR M01',
            'AUTHORIZE P1002',
            'VOID P1002 FRAUD',
            'STATUS P1002',
        ]);

        $out = $this->joinedOutput($lines);
        $this->assertStringContainsString('OK VOID P1002 VOIDED FRAUD', $out);
        $this->assertStringContainsString('P1002', $out);
        $this->assertStringContainsString('VOIDED', $out);
        $this->assertStringContainsString('11.00', $out);
        $this->assertStringContainsString('FRAUD', $out);
    }

    public function test_happy_path_create_authorize_capture_refund(): void
    {
        $lines = $this->runCommands([
            'CREATE P1003 20.00 MYR M01',
            'AUTHORIZE P1003',
            'CAPTURE P1003',
            'REFUND P1003 5.00',
            'STATUS P1003',
        ]);

        $out = $this->joinedOutput($lines);
        $this->assertStringContainsString('OK REFUND P1003 REFUNDED 5.00 MYR', $out);
        $this->assertStringContainsString('P1003', $out);
        $this->assertStringContainsString('REFUNDED', $out);
        $this->assertStringContainsString('20.00', $out);
        $this->assertStringContainsString('5.00', $out);
    }

    public function test_invalid_transitions_are_rejected_without_state_mutation(): void
    {
        $lines = $this->runCommands([
            'CREATE P1004 30.00 MYR M01',
            'REFUND P1004',
            'CAPTURE P1004',
            'AUTHORIZE P1004',
            'CAPTURE P1004',
            'VOID P1004',
            'STATUS P1004',
        ]);

        $out = $this->joinedOutput($lines);
        $this->assertStringContainsString('ERROR REFUND not allowed: cannot process payment in "INITIATED" state', $out);
        $this->assertStringContainsString('ERROR CAPTURE not allowed: cannot process payment in "INITIATED" state', $out);
        $this->assertStringContainsString('ERROR VOID not allowed: cannot process payment in "CAPTURED" state', $out);
        $this->assertStringContainsString('P1004', $out);
        $this->assertStringContainsString('CAPTURED', $out);
        $this->assertStringContainsString('30.00', $out);
    }

    public function test_idempotency_for_create_and_settle_is_enforced(): void
    {
        $lines = $this->runCommands([
            'CREATE P1005 40.00 MYR M01',
            'CREATE P1005 40.00 MYR M01',
            'AUTHORIZE P1005',
            'CAPTURE P1005',
            'SETTLE P1005',
            'SETTLE P1005',
            'STATUS P1005',
        ]);

        $out = $this->joinedOutput($lines);
        $this->assertStringContainsString('OK CREATE IDEMPOTENT P1005', $out);
        $this->assertStringContainsString('OK SETTLE IDEMPOTENT P1005', $out);
        $this->assertStringContainsString('SETTLED', $out);
        $this->assertStringContainsString('40.00', $out);
    }

    public function test_parser_hash_behavior_matches_spec_examples(): void
    {
        $lines = $this->runCommands([
            '# CREATE P1006 11.00 MYR M01',
            'CREATE P1006 11.00 MYR M01 # test payment',
            'AUTHORIZE P1006 # retry',
            'STATUS P1006',
        ]);

        $out = $this->joinedOutput($lines);
        $this->assertStringContainsString('ERROR Malformed command line (invalid comment position)', $out);
        $this->assertStringContainsString('OK CREATE P1006 INITIATED', $out);
        $this->assertStringContainsString('OK AUTHORIZE P1006 AUTHORIZED', $out);
        $this->assertStringContainsString('AUTHORIZED', $out);
        $this->assertStringContainsString('11.00', $out);
    }

    public function test_create_conflict_marks_existing_payment_failed(): void
    {
        $lines = $this->runCommands([
            'CREATE P1007 50.00 MYR M01',
            'CREATE P1007 50.01 MYR M01',
            'STATUS P1007',
        ]);

        $out = $this->joinedOutput($lines);
        $this->assertStringContainsString('ERROR CREATE conflict for Payment ID: P1007. Existing payment marked FAILED', $out);
        $this->assertStringContainsString('FAILED', $out);
        $this->assertStringContainsString('CREATE_CONFLICT', $out);
        $this->assertStringContainsString('50.00', $out);
    }

    public function test_settlement_and_audit_do_not_mutate_payment_state(): void
    {
        $lines = $this->runCommands([
            'CREATE P1008 60.00 MYR M01',
            'AUTHORIZE P1008',
            'CAPTURE P1008',
            'SETTLEMENT BATCH001',
            'AUDIT P1008',
            'STATUS P1008',
        ]);

        $out = $this->joinedOutput($lines);
        $this->assertStringContainsString('SETTLEMENT BATCH001 RECORDED', $out);
        $this->assertStringContainsString('TOTAL SETTLED COUNT: 0', $out);
        $this->assertStringContainsString('AUDIT RECEIVED P1008', $out);
        $this->assertStringContainsString('CAPTURED', $out);
        $this->assertStringContainsString('60.00', $out);
    }

    /**
     * @param list<string> $lines
     */
    private function joinedOutput(array $lines): string
    {
        return implode("\n", $lines);
    }

    private function runCommands(array $commands): array
    {
        config()->set('payment_pipeline.storage_driver', 'in_memory');

        $file = tempnam(sys_get_temp_dir(), 'payment_pipeline_');
        if ($file === false) {
            $this->fail('Unable to create temporary input file');
        }

        file_put_contents($file, implode(PHP_EOL, $commands).PHP_EOL);
        Artisan::call('payments:simulate', ['inputFile' => $file]);
        $output = Artisan::output();
        unlink($file);

        $lines = array_values(array_filter(array_map('trim', explode(PHP_EOL, $output)), static fn (string $line): bool => $line !== ''));

        return $lines;
    }
}
