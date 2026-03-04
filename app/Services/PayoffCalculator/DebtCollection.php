<?php

namespace App\Services\PayoffCalculator;

use App\Models\Debt;
use App\Services\PayoffCalculator\PayableDebt;
use Illuminate\Database\Eloquent\Collection;

/**
 * A Decorator that adds some specific functionality to a Laravel's Eloquent Collection
 */
class DebtCollection extends Collection {

    /**
     * Override a Collection's add() method to ensure we only allow PayableDebt models
     * 
     * @param mixed $value
     * @throws \InvalidArgumentException
     * @return Collection<TKey, Debt>
     */
    public function add($model)
    {
        if (! $model instanceof PayableDebt) {
            throw new \InvalidArgumentException('Only PayableDebt instances can be added to the collection.');
        }

        return parent::add($model);
    }

    public function unpaid(): self {
        return $this->filter(fn($debt) => $debt->balance > 0);
    }

    public function payments()
    {
        // NOTE: using debt_name rather than the more obvious choice of debt_id as this is more useful in consuming code
        return $this->map(fn($debt) => [$debt->name => $debt->getPaymentHistory()]);
    }
}
