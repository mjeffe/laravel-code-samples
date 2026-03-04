<?php

namespace App\Services\PayoffCalculator\Strategies\Interest;

use App\Services\PayoffCalculator\Finance;
use App\Services\PayoffCalculator\Contracts\InterestCalculationStrategy;

class SimpleInterestStrategy implements InterestCalculationStrategy {
    public function calculateInterest(float $balance, float $rate): float {
        return Finance::simple($balance, $rate, 12);
    }
}

