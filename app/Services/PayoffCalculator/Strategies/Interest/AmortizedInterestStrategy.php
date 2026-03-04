<?php

namespace App\Services\PayoffCalculator\Strategies\Interest;

use App\Services\PayoffCalculator\Contracts\InterestCalculationStrategy;

class AmortizedInterestStrategy implements InterestCalculationStrategy {
    public function calculateInterest(float $balance, float $rate): float {
        return $balance * $rate / 100 / 12;
    }
}
