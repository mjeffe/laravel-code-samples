<?php

namespace App\Services\PayoffCalculator\Strategies\Payoff;

use Illuminate\Support\Str;
use App\Services\PayoffCalculator\Contracts\PayoffStrategy;
use App\Services\PayoffCalculator\DebtCollection;

abstract class BasePayoffStrategy implements PayoffStrategy
{
    /**
     * Abstract function must be implemented by each strategy to return a
     * DebtCollection with debts ordered by the strategies payoff priorities.
     * 
     * @param \App\Services\PayoffCalculator\DebtCollection $debts
     * @param array $options
     * @return void
     */
    abstract public function prioritizeDebts(DebtCollection $debts, ?array $options = null): DebtCollection;

    /**
     * Return the strategy type string. Implemented to work for all strategies
     * by extracting the type from the class name.
     * 
     * @return string
     */
    public function getType(): string
    {
        $className = (new \ReflectionClass($this))->getShortName();

        // strip suffix off our class name
        $strippedName = str_replace('PayoffStrategy', '', $className, $count);

        return Str::snake($strippedName);
    }

    /**
     * Run the monthly payment calculations until all debts are paid off.
     * 
     * @param \App\Services\PayoffCalculator\DebtCollection $debts
     * @param float $totalAvailablePayment
     * @return void
     */
    public function executePayoff(DebtCollection $debts, float $totalAvailablePayment): void
    {
        $debts = $this->prioritizeDebts($debts);

        while ($debts->unpaid()->count() > 0 && $totalAvailablePayment > 0) {
            $extraAmount = $this->calculateExtraAmount($debts, $totalAvailablePayment);

            // pay minimum on each debt for this month
            foreach ($debts->unpaid() as $debt) {
                $leftover = $debt->processPaymentCycle($debt->min_payment);
                $extraAmount += $leftover;
            }

            // apply the extra towards this strategy's top debt
            $this->applyExtraPayments($debts, $extraAmount);
        }
    }

    /**
     * Calculate and return the difference between the total amount availabale
     * for servicing debt and the sum of all minimum payments.  This Extra
     * Amount is also known as the Acceleration Margin.
     * 
     * @param \App\Services\PayoffCalculator\DebtCollection $debts
     * @param float $totalAvailablePayment
     * @return float|int
     */
    protected function calculateExtraAmount(DebtCollection $debts, float $totalAvailablePayment): float
    {
        if ($totalAvailablePayment > 0 && $debts->unpaid()->count() > 0) {
            $totalMinimumPayment = $debts->unpaid()->sum('min_payment');

            return $totalAvailablePayment - $totalMinimumPayment;
        }

        return 0;
    }

    /**
     * This will apply the extra payment to the highest priority debt for this
     * payoff strategy. It processes debts in their sorted order, so apply the
     * extra amount to the first unpaid debt. It handles the case where it pays
     * off the target debt and still has some left over, by simply iterating
     * down the sorted list of debts until all remaining amount is used up.
     * 
     * @param \App\Services\PayoffCalculator\DebtCollection $debts
     * @param float $remainingAmount
     * @return void
     */
    protected function applyExtraPayments(DebtCollection $debts, float $remainingAmount): float
    {
        foreach ($debts->unpaid() as $debt) {
            if ($remainingAmount > 0) {
                // if the debt pays off, any leftover will be returned, which will then be applied to the next debt
                $remainingAmount = $debt->processExtraPayment($remainingAmount);
                // FIXME: if a debt pays off (we have $leftover), we *should* re-prioritize
                // debts here. Unfortunately, we don't have access to $totalAvailablePayment
                // In discussion with client...
            }
        }

        // if we have any remaining, it should mean all debts are paid
        return $remainingAmount;
    }
}
