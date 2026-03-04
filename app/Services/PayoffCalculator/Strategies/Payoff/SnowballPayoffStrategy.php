<?php

namespace App\Services\PayoffCalculator\Strategies\Payoff;

use App\Services\PayoffCalculator\PayableDebt;
use App\Services\PayoffCalculator\DebtCollection;

/**
 * Snowball method: 
 * 
 * payoff the lowest balance first, regardless of interest rate.
*/
class SnowballPayoffStrategy extends BasePayoffStrategy
{
    /**
     * Prioritize debts so that lowest balances are paid off first.
     * Use debt id as the tie breaker
     * 
     * @param \App\Services\PayoffCalculator\DebtCollection $debts
     * @param array $options
     * @return DebtCollection
     */
    public function prioritizeDebts(DebtCollection $debts, ?array $options = null): DebtCollection
    {
        return $debts->sortBy([
            fn (PayableDebt $a, PayableDebt $b) => $a->balance <=> $b->balance,
            fn (PayableDebt $a, PayableDebt $b) => $a->id <=> $b->id,
        ])->values();
    }
}
