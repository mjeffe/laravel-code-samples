<?php

namespace App\Services\PayoffCalculator\Strategies\Payoff;

use App\Services\PayoffCalculator\PayableDebt;
use App\Services\PayoffCalculator\DebtCollection;

/**
 * Avalanche method: 
 * 
 * payoff the highest interest rate first, regardless of balance.
*/
class AvalanchePayoffStrategy extends BasePayoffStrategy
{
    /**
     * Prioritize debts so that highest interest rates are paid off first.
     * For tiebreakers use lowest balance, then id.
     * 
     * @param \App\Services\PayoffCalculator\DebtCollection $debts
     * @param array $options
     * @return DebtCollection
     */
    public function prioritizeDebts(DebtCollection $debts, ?array $options = null): DebtCollection
    {
        return $debts->sortByDesc([
            fn (PayableDebt $a, PayableDebt $b) => $b->interest_rate <=> $a->interest_rate,
            fn (PayableDebt $a, PayableDebt $b) => $a->balance <=> $b->balance,
            fn (PayableDebt $a, PayableDebt $b) => $a->id <=> $b->id,
        ])->values();
    }
}
