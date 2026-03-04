<?php

namespace App\Services\Statement\DataTransferObjects;

use Carbon\Carbon;

readonly class DebtPayment
{
    public function __construct(
        public Carbon $paymentDate,
        public string $debtId,
        public string $debtType,
        public string $creditorName,
        public float $originalBalance,
        public float $currentBalance,
        public float $balanceAfterPayment,
        public float $paymentAmount,
        public float $principalAmount,
        public float $interestAmount,
        public float $nextMonthPayment,
        public Carbon $payoffDate,
        public ?float $escrow = null
    ) {}

    /**
     * Create array representation
     */
    public function toArray(): array
    {
        return [
            'payment_date' => $this->paymentDate->format('M Y'),
            'debt_id' => $this->debtId,
            'debt_type' => $this->debtType,
            'creditor_name' => $this->creditorName,
            'original_balance' => $this->originalBalance,
            'current_balance' => $this->currentBalance,
            'balance_after_payment' => $this->balanceAfterPayment,
            'payment' => [
                'amount' => $this->paymentAmount,
                'principal' => $this->principalAmount,
                'interest' => $this->interestAmount,
            ],
            'next_month_payment' => $this->nextMonthPayment,
            'payoff_date' => $this->payoffDate->format('M Y'),
            'escrow' => $this->escrow
        ];
    }

    /**
     * Calculate percentage of payment that went to principal
     */
    public function getPrincipalPercentage(): float
    {
        if ($this->paymentAmount === 0.0) {
            return 0.0;
        }
        return ($this->principalAmount / $this->paymentAmount) * 100;
    }

    /**
     * Calculate percentage of payment that went to interest
     */
    public function getInterestPercentage(): float
    {
        if ($this->paymentAmount === 0.0) {
            return 0.0;
        }
        return ($this->interestAmount / $this->paymentAmount) * 100;
    }

    /**
     * Get the total reduction in balance
     */
    public function getBalanceReduction(): float
    {
        return $this->currentBalance - $this->balanceAfterPayment;
    }
}
