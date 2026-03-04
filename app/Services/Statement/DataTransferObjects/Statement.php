<?php

namespace App\Services\Statement\DataTransferObjects;

use Carbon\Carbon;
use App\Models\User;

readonly class Statement
{
    /**
     * @param Carbon $statementDate The month this statement is for
     * @param string $strategy The payoff strategy used (e.g. 'lamp', 'baseline')
     * @param User $user The user this statement belongs to
     * @param Carbon $blueprintLastUpdate When the payment plan was last calculated
     * @param Carbon $financialFreedomDate Projected date when all debts will be paid
     * @param StatementSummary $summary Calculated summary data
     * @param array<DebtPayment> $debtPayments Array of payments for this month
     */
    public function __construct(
        public Carbon $statementDate,
        public string $strategy,
        public User $user,
        public Carbon $blueprintLastUpdate,
        public Carbon $financialFreedomDate,
        public StatementSummary $summary,
        public array $debtPayments
    ) {}

    /**
     * Create array representation for JSON responses or PDF generation
     */
    public function toArray(): array
    {
        return [
            'statement_date' => $this->statementDate->format('m/d/Y'),
            'statement_month' => $this->statementDate->format('M-y'),
            'household_name' => $this->user->householdName(),
            'blueprint_last_update' => $this->blueprintLastUpdate->format('m/d/Y'),
            'financial_freedom_date' => $this->financialFreedomDate->format('F Y'),
            'summary' => $this->summary->toArray(),
            'debt_payments' => array_map(fn($payment) => $payment->toArray(), $this->debtPayments)
        ];
    }

    /**
     * Get statement date string YYYY-MM
     */
    public function getDateStr(): string
    {
        return $this->statementDate->format('Y-m');
    }

    /**
     * Get statement date as an Id YYYY-MM-01
     */
    public function getDateId(): string
    {
        return $this->statementDate->format('Y-m') . '-01';
    }

    /**
     * Get formatted statement date
     */
    public function getFormattedDate(): string
    {
        return $this->statementDate->format('F Y');
    }

    /**
     * Return a string that can be used as the filename base (no extension) for this statement.
     * 
     * @return string
     */
    public function getBaseFileName(): string
    {
        return 'lamp-statement-' . $this->getDateStr();
    }

    /**
     * Check if this is an upcoming statement
     */
    public function isUpcoming(): bool
    {
        return $this->statementDate->isAfter(Carbon::now());
    }
}
