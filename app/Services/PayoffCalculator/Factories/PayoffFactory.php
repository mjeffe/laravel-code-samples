<?php

namespace App\Services\PayoffCalculator\Factories;

use App\Services\PayoffCalculator\Contracts\PayoffStrategy;

class PayoffFactory
{
    /**
     * Find, instantiate, and return an instance of the given Payoff strategy
     * 
     * @param string $type  Type of payoff strategy
     * @throws \Exception
     * @return \App\Services\PayoffCalculator\Contracts\PayoffStrategy
     */
    public static function create(string $type): PayoffStrategy
    {
        // convert snake_case to PascalCase (aka StudlyCase)
        $payoffType = implode('', array_map('ucfirst', explode('_', $type)));

        $class = "App\\Services\\PayoffCalculator\\Strategies\\Payoff\\{$payoffType}PayoffStrategy";

        if (class_exists($class)) {
            return new $class;
        }

        throw new \Exception("Unimplemented payoff strategy type '$type': Unable to find $class class");
    }
}
