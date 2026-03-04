<?php

namespace App\Services\Statement\DataTransferObjects;

readonly class StatementSummary
{
    public function __construct(
        public float $totalMonthlyExpenditure,
        public float $totalOriginalBalance,
        public float $totalCurrentBalance,
        public float $newDebtSinceStart,
        public float $debtEliminatedSoFar,
        public float $principalToPayThisMonth,
        public float $totalInterestCostThisMonth
    ) {}

    /**
     * Create array representation
     */
    public function toArray(): array
    {
        return [
            'total_monthly_expenditure' => $this->totalMonthlyExpenditure,
            'total_original_balance' => $this->totalOriginalBalance,
            'total_current_balance' => $this->totalCurrentBalance,
            'new_debt_since_start' => $this->newDebtSinceStart,
            'debt_eliminated_so_far' => $this->debtEliminatedSoFar,
            'principal_to_pay_this_month' => $this->principalToPayThisMonth,
            'total_interest_cost_this_month' => $this->totalInterestCostThisMonth
        ];
    }

    /**
     * Calculate percentage of payment that went to principal
     */
    public function getPrincipalPercentage(): float
    {
        if ($this->totalMonthlyExpenditure === 0.0) {
            return 0.0;
        }
        return ($this->principalToPayThisMonth / $this->totalMonthlyExpenditure) * 100;
    }

    /**
     * Calculate percentage of payment that went to interest
     */
    public function getInterestPercentage(): float
    {
        if ($this->totalMonthlyExpenditure === 0.0) {
            return 0.0;
        }
        return ($this->totalInterestCostThisMonth / $this->totalMonthlyExpenditure) * 100;
    }
}
