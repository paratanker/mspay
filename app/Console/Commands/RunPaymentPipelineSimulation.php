<?php

namespace App\Console\Commands;

use App\Domain\Payments\Parsing\CommandParser;
use App\Domain\Payments\Repository\DatabasePaymentRepository;
use App\Domain\Payments\Repository\DatabaseSettlementBatchRepository;
use App\Domain\Payments\Repository\InMemoryPaymentRepository;
use App\Domain\Payments\Repository\InMemorySettlementBatchRepository;
use App\Domain\Payments\Repository\PaymentRepository;
use App\Domain\Payments\Repository\SettlementBatchRepository;
use App\Domain\Payments\Service\PaymentPipelineEngine;
use App\Domain\Payments\Service\PaymentStateMachine;
use Illuminate\Console\Command;

final class RunPaymentPipelineSimulation extends Command
{
    /**
     * @var string
     */
    protected $signature = 'payments:simulate {inputFile? : Optional path to a command input file}';

    /**
     * @var string
     */
    protected $description = 'Run the payment pipeline simulation command processor';

    public function handle(): int
    {
        $engine = $this->makeEngine();

        $inputFile = $this->argument('inputFile');
        if (is_string($inputFile) && $inputFile !== '') {
            if (! is_file($inputFile)) {
                $this->line('ERROR Input file not found: '.$inputFile);

                return self::FAILURE;
            }

            $lines = file($inputFile, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                $this->line('ERROR Unable to read input file: '.$inputFile);

                return self::FAILURE;
            }

            foreach ($lines as $line) {
                $output = $engine->processLine($line);
                foreach ($output->lines() as $message) {
                    $this->line($message);
                }

                if ($output->shouldExit()) {
                    return self::SUCCESS;
                }
            }

            return self::SUCCESS;
        }

        $stdin = fopen('php://stdin', 'r');
        if ($stdin === false) {
            $this->line('ERROR Unable to open stdin');

            return self::FAILURE;
        }

        while (($line = fgets($stdin)) !== false) {
            $output = $engine->processLine($line);

            foreach ($output->lines() as $message) {
                $this->line($message);
            }

            if ($output->shouldExit()) {
                fclose($stdin);

                return self::SUCCESS;
            }
        }

        fclose($stdin);

        return self::SUCCESS;
    }

    private function makeEngine(): PaymentPipelineEngine
    {
        $currencyConfig = config('payment_pipeline.supported_currencies');

        if (! is_array($currencyConfig)) {
            $currencyConfig = [];
        }

        $thresholds = config('payment_pipeline.pre_settlement_review_thresholds');

        $storageDriver = strtolower((string) config('payment_pipeline.storage_driver', 'database'));

        [$paymentRepository, $batchRepository] = $this->makeRepositories($storageDriver);

        return new PaymentPipelineEngine(
            new CommandParser(),
            new PaymentStateMachine(),
            $paymentRepository,
            $batchRepository,
            $currencyConfig,
            is_array($thresholds) ? $thresholds : null,
        );
    }

    /**
     * @return array{PaymentRepository, SettlementBatchRepository}
     */
    private function makeRepositories(string $storageDriver): array
    {
        if ($storageDriver === 'in_memory') {
            return [new InMemoryPaymentRepository(), new InMemorySettlementBatchRepository()];
        }

        return [new DatabasePaymentRepository(), new DatabaseSettlementBatchRepository()];
    }
}
