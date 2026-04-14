<?php

namespace App\Domain\Payments\Service;

use App\Domain\Payments\Model\Payment;
use App\Domain\Payments\Model\PaymentState;
use App\Domain\Payments\Model\PaymentCommand;
use App\Domain\Payments\Model\PaymentVoidReason;
use App\Domain\Payments\Parsing\CommandParser;
use App\Domain\Payments\Parsing\ParsedCommand;
use App\Domain\Payments\Repository\PaymentRepository;
use App\Domain\Payments\Repository\SettlementBatchRepository;
use Illuminate\Support\Facades\Log as FacadesLog;
use Throwable;

final class PaymentPipelineEngine
{
    private CommandParser $parser;

    private PaymentStateMachine $stateMachine;

    private PaymentRepository $paymentRepository;

    private SettlementBatchRepository $batchRepository;

    /**
     * @var array<string, array{decimal_places?: int, name?: string, code?: string}>
     */
    private array $currencyConfig;

    private ?array $reviewAmountThresholds;

    public function __construct(
        CommandParser $parser,
        PaymentStateMachine $stateMachine,
        PaymentRepository $paymentRepository,
        SettlementBatchRepository $batchRepository,
        array $currencyConfig,
        ?array $reviewAmountThresholds = null
    ) {
        $this->parser = $parser;
        $this->stateMachine = $stateMachine;
        $this->paymentRepository = $paymentRepository;
        $this->batchRepository = $batchRepository;
        $this->currencyConfig = $currencyConfig;
        $this->reviewAmountThresholds = $reviewAmountThresholds;
    }

    public function processLine(string $line): EngineOutput
    {
        $result = $this->parser->parse($line);
        if ($result->isIgnored()) {
            return new EngineOutput([]);
        }

        if ($result->errorValue() !== null) {
            return new EngineOutput([$this->error($result->errorValue())]);
        }

        try {
            return $this->dispatch($result->commandValue());
        } catch (PaymentDomainException $exception) {
            return new EngineOutput([$this->error($exception->getMessage())]);
        } catch (Throwable $exception) {
            FacadesLog::error('PaymentPipelineEngine internal error', [
                'exception' => $exception,
            ]);
            return new EngineOutput([$this->error('Internal error while processing command')]);
        }
    }

    private function dispatch(?ParsedCommand $command): EngineOutput
    {
        if ($command === null) {
            return new EngineOutput([$this->error('Malformed command line')]);
        }

        switch ($command->name()) {
            case PaymentCommand::CREATE:
                return $this->createPayment($command);
            case PaymentCommand::AUTHORIZE:
                return $this->authorizePayment($command);
            case PaymentCommand::CAPTURE:
                return $this->capturePayment($command);
            case PaymentCommand::VOID:
                return $this->voidPayment($command);
            case PaymentCommand::REFUND:
                return $this->refundPayment($command);
            case PaymentCommand::SETTLE:
                return $this->settlePayment($command);
            case PaymentCommand::SETTLEMENT:
                return $this->settlement($command);
            case PaymentCommand::STATUS:
                return $this->status($command);
            case PaymentCommand::LIST:
                return $this->listPayments($command);
            case PaymentCommand::AUDIT:
                return $this->audit($command);
            case PaymentCommand::EXIT:
                return $this->exitCommand($command);
            default:
                return new EngineOutput([$this->error(sprintf('Unknown command: %s', $command->name()))]);
        }
    }

    private function createPayment(ParsedCommand $command): EngineOutput
    {
        if (count($command->arguments()) !== 4) {
            return new EngineOutput([$this->error('CREATE expects 4 arguments. Command format: CREATE <payment_id> <amount> <currency> <merchant_id>')]);
        }

        [$paymentId, $amount, $currency, $merchantId] = $command->arguments();

        if ($paymentId === '') {
            return new EngineOutput([$this->error('payment_id is required')]);
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            return new EngineOutput([$this->error('currency must be a 3-letter uppercase code')]);
        }

        if ($this->currencyConfig !== [] && ! array_key_exists($currency, $this->currencyConfig)) {
            return new EngineOutput([$this->error(sprintf('currency %s is not supported', $currency))]);
        }

        $amountMinor = $this->parseDecimalAmountToMinor($amount, $currency);
        if ($amountMinor === null) {
            return new EngineOutput([$this->error('amount must be a positive decimal with correct precision for the currency')]);
        }

        if (trim($merchantId) === '') {
            return new EngineOutput([$this->error('merchant_id must be non-empty')]);
        }

        $existing = $this->paymentRepository->find($paymentId);
        if ($existing !== null) {
            $isIdempotent = $existing->amountMinor() === $amountMinor
                && $existing->currency() === $currency
                && $existing->merchantId() === $merchantId;

            if ($isIdempotent) {
                return new EngineOutput([$this->ok(sprintf('CREATE IDEMPOTENT %s', $paymentId))]);
            }

            $existing->markFailed('CREATE_CONFLICT');
            $this->paymentRepository->save($existing);

            return new EngineOutput([$this->error(sprintf(
                'CREATE conflict for %s; existing payment marked FAILED',
                $paymentId
            ))]);
        }

        $payment = new Payment($paymentId, $amountMinor, $currency, $merchantId);
        $this->paymentRepository->save($payment);

        return new EngineOutput([$this->ok(sprintf('CREATE %s INITIATED', $paymentId))]);
    }

    private function authorizePayment(ParsedCommand $command): EngineOutput
    {
        if (count($command->arguments()) !== 1) {
            return new EngineOutput([$this->error('AUTHORIZE expects 1 argument. Command format: AUTHORIZE <payment_id>')]);
        }

        $payment = $this->paymentRepository->find($command->arguments()[0]);
        if ($payment === null) {
            return new EngineOutput([$this->error(sprintf('Payment not found: %s', $command->arguments()[0]))]);
        }

        $this->stateMachine->authorize($payment, $this->requiresPreSettlementReview($payment));
        $this->paymentRepository->save($payment);

        return new EngineOutput([$this->ok(sprintf('AUTHORIZE %s %s', $payment->id(), $payment->state()))]);
    }

    private function capturePayment(ParsedCommand $command): EngineOutput
    {
        if (count($command->arguments()) !== 1) {
            return new EngineOutput([$this->error('CAPTURE expects 1 argument. Command format: CAPTURE <payment_id>')]);
        }

        $payment = $this->paymentRepository->find($command->arguments()[0]);
        if ($payment === null) {
            return new EngineOutput([$this->error(sprintf('Payment not found: %s', $command->arguments()[0]))]);
        }

        $this->stateMachine->capture($payment);
        $this->paymentRepository->save($payment);

        return new EngineOutput([$this->ok(sprintf('CAPTURE %s CAPTURED', $payment->id()))]);
    }

    private function voidPayment(ParsedCommand $command): EngineOutput
    {
        $argCount = count($command->arguments());
        if ($argCount < 1 || $argCount > 2) {
            return new EngineOutput([$this->error('VOID expects 1 or 2 arguments. Command format: VOID <payment_id> [reason_code]')]);
        }

        [$paymentId] = $command->arguments();
        $reasonCode = $command->arguments()[1] ?? null;

        // Validate reason code but just proceed with logging instead of breaking the command
        if ($reasonCode !== null && ! in_array($reasonCode, PaymentVoidReason::values(), true)) {
            // Just log the warning and proceed with the command
            FacadesLog::warning('Invalid reason code: ' . $reasonCode, [
                'Allowed reason codes: ' . implode('|', PaymentVoidReason::values()),
            ]);
        }

        $payment = $this->paymentRepository->find($paymentId);
        if ($payment === null) {
            return new EngineOutput([$this->error(sprintf('Payment not found: %s', $paymentId))]);
        }

        $this->stateMachine->void($payment, $reasonCode);
        $this->paymentRepository->save($payment);

        $reasonSuffix = $reasonCode !== null ? sprintf(' %s', $reasonCode) : '';

        return new EngineOutput([$this->ok(sprintf('VOID %s VOIDED%s', $paymentId, $reasonSuffix))]);
    }

    private function refundPayment(ParsedCommand $command): EngineOutput
    {
        $argCount = count($command->arguments());
        if ($argCount < 1 || $argCount > 2) {
            return new EngineOutput([$this->error('REFUND expects 1 or 2 arguments. Command format: REFUND <payment_id> [amount]')]);
        }

        [$paymentId] = $command->arguments();
        $refundAmount = $command->arguments()[1] ?? null;

        $payment = $this->paymentRepository->find($paymentId);
        if ($payment === null) {
            return new EngineOutput([$this->error(sprintf('Payment not found: %s', $paymentId))]);
        }

        $refundAmountMinor = null;
        if ($refundAmount !== null) {
            $refundAmountMinor = $this->parseDecimalAmountToMinor($refundAmount, $payment->currency());
            if ($refundAmountMinor === null) {
                return new EngineOutput([$this->error('refund amount must be a positive decimal with correct precision for the currency')]);
            }

            if ($refundAmountMinor > $payment->amountMinor()) {
                return new EngineOutput([$this->error('refund amount cannot exceed original amount')]);
            }
        }
        else {
            $refundAmountMinor = $payment->amountMinor();
        }

        $this->stateMachine->refund($payment, $refundAmountMinor);
        $this->paymentRepository->save($payment);

        $formatted = $this->formatMinorUnits($refundAmountMinor, $payment->currency());

        return new EngineOutput([$this->ok(sprintf(
            'REFUND %s REFUNDED %s %s',
            $paymentId,
            $formatted,
            $payment->currency()
        ))]);
    }

    private function settlePayment(ParsedCommand $command): EngineOutput
    {
        if (count($command->arguments()) !== 1) {
            return new EngineOutput([$this->error('SETTLE expects 1 argument. Command format: SETTLE <payment_id>')]);
        }

        $paymentId = $command->arguments()[0];
        $payment = $this->paymentRepository->find($paymentId);
        if ($payment === null) {
            return new EngineOutput([$this->error(sprintf('Payment not found: %s', $paymentId))]);
        }

        $transitioned = $this->stateMachine->settle($payment);
        if (! $transitioned) {
            return new EngineOutput([$this->ok(sprintf('SETTLE IDEMPOTENT %s', $paymentId))]);
        }

        $this->paymentRepository->save($payment);

        return new EngineOutput([$this->ok(sprintf('SETTLE %s SETTLED', $paymentId))]);
    }

    private function settlement(ParsedCommand $command): EngineOutput
    {
        if (count($command->arguments()) !== 1) {
            return new EngineOutput([$this->error('SETTLEMENT expects 1 argument. Command format: SETTLEMENT <batch_id>')]);
        }

        $batchId = $command->arguments()[0];

        if ($batchId === '') {
            return new EngineOutput([$this->error('batch_id is required')]);
        }

        $isNewBatch = $this->batchRepository->markProcessed($batchId);

        $settledPayments = $this->paymentRepository->byState(PaymentState::SETTLED);

        $lines = [sprintf('SETTLEMENT %s %s', $batchId, $isNewBatch ? 'RECORDED' : 'IDEMPOTENT')];
        $lines[] = sprintf('TOTAL SETTLED COUNT: %d', count($settledPayments));

        /** @var array<string, int> $totalsMinorByCurrency */
        $totalsMinorByCurrency = [];
        foreach ($settledPayments as $payment) {
            $ccy = $payment->currency();
            $totalsMinorByCurrency[$ccy] = ($totalsMinorByCurrency[$ccy] ?? 0) + $payment->amountMinor();
        }
        ksort($totalsMinorByCurrency, SORT_STRING);

        $lines[] = 'TOTALS BY CURRENCY:';
        if ($totalsMinorByCurrency === []) {
            $lines[] = '  (none)';
        } else {
            foreach ($totalsMinorByCurrency as $ccy => $minor) {
                $lines[] = sprintf('  %s  %s', $ccy, $this->formatMinorUnits($minor, $ccy));
            }
        }

        $tableRows = [];
        foreach ($settledPayments as $payment) {
            $tableRows[] = [
                $payment->id(),
                $this->formatMinorUnits($payment->amountMinor(), $payment->currency()),
                $payment->currency(),
                $payment->merchantId(),
            ];
        }

        $lines[] = '';
        $lines = array_merge($lines, $this->formatAsciiTable(
            ['PAYMENT_ID', 'AMOUNT', 'CURRENCY', 'MERCHANT'],
            $tableRows,
            [1 => true]
        ));

        return new EngineOutput($lines);
    }

    private function status(ParsedCommand $command): EngineOutput
    {
        if (count($command->arguments()) !== 1) {
            return new EngineOutput([$this->error('STATUS expects 1 argument. Command format: STATUS <payment_id>')]);
        }

        $payment = $this->paymentRepository->find($command->arguments()[0]);
        if ($payment === null) {
            return new EngineOutput([$this->error(sprintf('Payment not found: %s', $command->arguments()[0]))]);
        }

        $parts = [
            $payment->id(),
            $payment->state(),
            $this->formatMinorUnits($payment->amountMinor(), $payment->currency()),
            $payment->currency(),
            $payment->merchantId(),
            $payment->voidReasonCode() ?? '',
            $payment->failedReason() ?? '',
            $payment->state() === PaymentState::REFUNDED && $payment->refundAmountMinor() !== null ? $this->formatMinorUnits($payment->refundAmountMinor(), $payment->currency()) : '',
        ];

        $lines = $this->formatAsciiTable(
            ['PAYMENT_ID', 'STATUS', 'AMOUNT', 'CURRENCY', 'MERCHANT', 'VOID_REASON', 'FAILED_REASON', 'REFUND_AMOUNT'],
            [$parts],
            [2 => true, 7 => true]
        );

        return new EngineOutput($lines);
    }

    private function listPayments(ParsedCommand $command): EngineOutput
    {
        if ($command->arguments() !== []) {
            return new EngineOutput([$this->error('LIST expects no arguments')]);
        }

        $payments = $this->paymentRepository->all();

        if ($payments === []) {
            return new EngineOutput([$this->error('No payments found')]);
        }

        $lines = [sprintf('LIST COUNT=%d', count($payments))];

        $tableRows = [];
        foreach ($payments as $payment) {
            $tableRows[] = [
                $payment->id(),
                $payment->state(),
                $this->formatMinorUnits($payment->amountMinor(), $payment->currency()),
                $payment->currency(),
                $payment->merchantId(),
                $payment->voidReasonCode() ?? '',
                $payment->failedReason() ?? '',
                $payment->state() === PaymentState::REFUNDED && $payment->refundAmountMinor() !== null ? $this->formatMinorUnits($payment->refundAmountMinor(), $payment->currency()) : '',
            ];
        }

        $lines[] = '';
        $lines = array_merge($lines, $this->formatAsciiTable(
            ['PAYMENT_ID', 'STATUS', 'AMOUNT', 'CURRENCY', 'MERCHANT','VOID_REASON', 'FAILED_REASON', 'REFUND_AMOUNT'],
            $tableRows,
            [2 => true]
        ));

        return new EngineOutput($lines);
    }

    private function audit(ParsedCommand $command): EngineOutput
    {
        if (count($command->arguments()) !== 1) {
            return new EngineOutput([$this->error('AUDIT expects 1 argument. Command format: AUDIT <payment_id>')]);
        }

        $exist = $this->paymentRepository->exists($command->arguments()[0]);

        FacadesLog::info('AUDIT command received', [
            'payment_id' => $command->arguments()[0],
            'exists' => $exist,
        ]);

        return new EngineOutput([sprintf('AUDIT RECEIVED %s', $command->arguments()[0])]);
    }

    private function exitCommand(ParsedCommand $command): EngineOutput
    {
        if ($command->arguments() !== []) {
            return new EngineOutput([$this->error('EXIT expects no arguments. Command format: EXIT')]);
        }

        return new EngineOutput(['OK EXIT'], true);
    }

    private function ok(string $message): string
    {
        return 'OK '.$message;
    }

    private function error(string $message): string
    {
        return 'ERROR '.$message;
    }

    private function requiresPreSettlementReview(Payment $payment): bool
    {
        if ($this->reviewAmountThresholds === null) {
            return false;
        }

        $raw = $this->reviewAmountThresholds[$payment->currency()] ?? null;
        if ($raw === null) {
            return false;
        }

        $thresholdMinor = is_int($raw) ? $raw : (int) $raw;
        if ($thresholdMinor <= 0) {
            return false;
        }

        return $payment->amountMinor() >= $thresholdMinor;
    }

    private function decimalPlacesFor(string $currency): int
    {
        if (array_key_exists($currency, $this->currencyConfig)) {
            return (int) ($this->currencyConfig[$currency]['decimal_places'] ?? 2);
        }

        return 2;
    }

    /**
     * Parses a decimal amount string into minor units using the currency's decimal_places.
     */
    private function parseDecimalAmountToMinor(string $amount, string $currency): ?int
    {
        $amount = trim($amount);
        $dp = $this->decimalPlacesFor($currency);

        if ($dp === 0) {
            if (! preg_match('/^\d+$/', $amount)) {
                return null;
            }

            $minor = (int) $amount;

            return $minor > 0 ? $minor : null;
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $amount)) {
            return null;
        }

        $parts = explode('.', $amount, 2);
        $wholePart = $parts[0];
        $fracPart = $parts[1] ?? '';

        if (strlen($fracPart) > $dp) {
            return null;
        }

        $fracPart = str_pad($fracPart, $dp, '0', STR_PAD_RIGHT);
        $whole = (int) $wholePart;
        $frac = (int) $fracPart;
        $factor = 10 ** $dp;
        $minor = $whole * $factor + $frac;

        return $minor > 0 ? $minor : null;
    }

    private function formatMinorUnits(int $minorUnits, ?string $currency = null): string
    {
        if ($currency === null) {
            $decimalPlaces = 2;
        } else {
            $decimalPlaces = $this->decimalPlacesFor($currency);
        }

        if ($decimalPlaces === 0) {
            return (string) $minorUnits;
        }

        $factor = 10 ** $decimalPlaces;
        $whole = intdiv($minorUnits, $factor);
        $fraction = $minorUnits % $factor;

        return sprintf('%d.%0'.$decimalPlaces.'d', $whole, $fraction);
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param array<int, bool> $rightAlignColumns column index => true to right-align
     * @return list<string>
     */
    private function formatAsciiTable(array $headers, array $rows, array $rightAlignColumns = []): array
    {
        if ($headers === []) {
            return [];
        }

        $colCount = count($headers);
        $widths = array_map('strlen', $headers);

        foreach ($rows as $row) {
            for ($i = 0; $i < $colCount; $i++) {
                $cell = $row[$i] ?? '';
                $widths[$i] = max($widths[$i], strlen($cell));
            }
        }

        $gap = '  ';
        $out = [];

        $pad = static function (string $cell, int $w, bool $right): string {
            return $right ? str_pad($cell, $w, ' ', STR_PAD_LEFT) : str_pad($cell, $w, ' ', STR_PAD_RIGHT);
        };

        $headerCells = [];
        $sepCells = [];
        for ($i = 0; $i < $colCount; $i++) {
            $right = $rightAlignColumns[$i] ?? false;
            $headerCells[] = $pad($headers[$i], $widths[$i], $right);
            $sepCells[] = str_repeat('-', $widths[$i]);
        }
        $out[] = implode($gap, $headerCells);
        $out[] = implode($gap, $sepCells);

        foreach ($rows as $row) {
            $cells = [];
            for ($i = 0; $i < $colCount; $i++) {
                $right = $rightAlignColumns[$i] ?? false;
                $cells[] = $pad($row[$i] ?? '', $widths[$i], $right);
            }
            $out[] = implode($gap, $cells);
        }

        $out[] = '';

        return $out;
    }
}
