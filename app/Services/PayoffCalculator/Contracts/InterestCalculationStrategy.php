<?php

namespace App\Services\PayoffCalculator\Contracts;

interface InterestCalculationStrategy {
    public function calculateInterest(float $balance, float $rate): float;
}

