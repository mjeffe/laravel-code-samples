<?php

namespace App\Services\PayoffCalculator\Factories;

use App\Models\Debt;
use App\Services\PayoffCalculator\PayableDebt;
use App\Services\PayoffCalculator\Contracts\InterestCalculationStrategy;
use App\Services\PayoffCalculator\Strategies\Interest\InterestOnlyStrategy;
use App\Services\PayoffCalculator\Strategies\Interest\SimpleInterestStrategy;
use App\Services\PayoffCalculator\Strategies\Interest\AmortizedInterestStrategy;

class PayableDebtFactory {
    /**
     * Instantiate a PayableDebt from a Debt model
     * 
     * @param \App\Models\Debt $debt
     * @return PayableDebt
     */
    public static function create(Debt $debt): PayableDebt {
        $strategy = self::resolveInterestStrategy($debt->type);

        return new PayableDebt($debt, $strategy);
    }

    private static function resolveInterestStrategy(string $type): InterestCalculationStrategy {
        return match($type) {
            'car' => new AmortizedInterestStrategy(),
            'other' => new SimpleInterestStrategy(),
            'student' => new AmortizedInterestStrategy(),
            'mortgage' => new AmortizedInterestStrategy(),
            'credit_card' => new AmortizedInterestStrategy(),
            'second_mortgage' => new AmortizedInterestStrategy(),
            // special/generic cases
            'amortizing' => new AmortizedInterestStrategy(),
            'interest_only' => new InterestOnlyStrategy(),
        };
    }
}
