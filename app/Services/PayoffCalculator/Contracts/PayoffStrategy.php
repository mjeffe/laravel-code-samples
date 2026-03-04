<?php

namespace App\Services\PayoffCalculator\Contracts;

use App\Services\PayoffCalculator\DebtCollection;

interface PayoffStrategy
{
    /**
     * Return the type string which could be used to instantiate this type
     * 
     * @return string
     */
    public function getType(): string;

    /**
     * Orders the collection of debts in the order they should be paid off, from
     * highest priority to lowest. It can take an optional array of options.
     * 
     * Currently, this is sort based so that an iteration of the collection
     * (foreach, etc) will deliver the highest priority debt first. However, in
     * the future, we may convert this to a flag based method, such that the
     * currently prioritized debt has an indicator of some sort.
     * 
     * @param \App\Services\PayoffCalculator\DebtCollection $debts
     * @param float $extraAmount
     * @return void
     */
    public function prioritizeDebts(DebtCollection $debts, ?array $options = null): DebtCollection;

    /**
     * Run monthly payoff calculations until all debts are paid.
     * 
     * @param \App\Services\PayoffCalculator\DebtCollection $debts
     * @param float $totalAvailablePayment
     * @return void
     */
    public function executePayoff(DebtCollection $debts, float $totalAvailablePayment): void;

}
