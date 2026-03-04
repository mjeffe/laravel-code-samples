<?php

namespace App\Services\PayoffCalculator;

/**
 * helpful sources I used to learn and validate results
 * 
 * https://www.calculatorsoup.com/calculators/financial/index-interest-apr-calculators.php
 * https://www.thecalculatorsite.com/finance/calculators/compoundinterestcalculator.php
 */
class Finance
{
    /**
     * Uses a simple interest calculation to return the amount of interest that
     * would be applied for a single period, when calculated over the given
     * number of periods. The default periods is 12, assuming the interest rate
     * is an APR.
     * 
     * If the interest rate is calculated daily, to get a months payment:
     * round(Finance::simple(400000, 6, 360), 3) = 66.667 * 30 = 2000.1
     * 
     * @param float $principal The principal balance amount
     * @param float $apr The Annual Percentage Rate, such as 4.75
     * @param mixed $period The number of periods, defaults to 12
     * @return float The amount of interest that would acrue for a given period
     */
    public static function simple(float $principal, float $apr, $periods = 12): float
    {
        return $principal * $apr / 100 / $periods;
    }

    /**
     * Calculate how long a given principal will last, when making regular
     * withdrawls. This uses the formula for the present value of an ordinary
     * annuity, rearanged to solve for number of periods.
     * 
     * Ref:
     *  https://www.investopedia.com/retirement/calculating-present-and-future-value-of-annuities/
     *  https://www.mutualofomaha.com/calculator/how-long-will-my-money-last
     *  https://money.stackexchange.com/questions/101498/how-to-calculate-how-long-my-money-would-last
     *  https://money.stackexchange.com/questions/102580/what-is-the-formula-for-loan-payoff-date/102581#102581
     * 
     * Where:
     *      w is the withdrawl amount
     *      r is the monthly interest rate (APR / 12)
     *      p is the principal
     *      n is the number of months
     * 
     *  n = -(log(1 - (r * p)/w) / log(1 + r))
     * 
     * @param float $balance
     * @param float $apr
     * @param float $monthlyContrib
     * @return float
     */
    public static function monthsFromValue(float $principal, float $apr, float $monthlyWithdrawl = 0): float 
    {
        // convert annual interest rate to monthly decimal
        $monthlyRate = $apr / 100 / 12;

        // validate inputs
        if ($principal <= 0 || $monthlyWithdrawl <= 0) {
            return 0;  // allows a greater variety of input
        }
        if ($apr < 0) {
            throw new \InvalidArgumentException("Invalid input: apr must not be negative");
        }

        // if not earning/paying interest, it's pretty simple
        if ($monthlyRate == 0) {
            return $principal / $monthlyWithdrawl;
        }
        
        $months = -(log(1 - ($monthlyRate * $principal)/$monthlyWithdrawl)/log(1 + $monthlyRate));

        return $months;
    }

    /**
     * Calculate and return the number of payments it will take to pay off the balance.
     *
     * Reference:
     *   - https://www.financeformulas.net/Loan_Payment_Formula.html
     *   - https://www.thebalancemoney.com/loan-payment-calculations-315564
     *   - https://www.inchcalculator.com/loan-payoff-calculator/
     *
     *   Iterative approach. May be helpful if we need month-by-month details
     *      // iterate until balance is paid off
     *      while ($balance > 0) {
     *          $interest = $balance * $monthlyRate;
     * 
     *          // reduce balance by the payment minus interest
     *          $principalPayment = $monthlyPayment - $interest;
     *          $balance -= $principalPayment;
     * 
     *          $numberOfPayments++;
     * 
     *          if ($balance < 0) {
     *              $balance = 0;
     *          }
     *      }
     *      return $numberOfPayments;
     *
     * The formula for the number of payments N to pay off a loan is derived
     * from the loan amortization formula:
     * 
     *              r(1+r)^N
     *      M = P x --------
     *              (1+r)^N − 1
     *
     * Where:
     *      M is the monthly payment (minimum payment + extra payment),
     *      P is the loan balance,
     *      r is the monthly interest rate (APR / 12),
     *      N is the number of payments.
     *      
     * Rearranging to solve for N:
     * 
     *          log⁡(M/M-Pr)
     *      N = ----------
     *          log⁡(1+r)
     *
     *
     * @param float $balance
     * @param float $annualRate
     * @param mixed $monthlyPayment
     * @throws \Exception
     * @return float
     */
    public static function numberOfPayments(float $balance, float $annualRate, float $monthlyPayment): float
    {
        // convert annual rate to monthly rate
        $monthlyRate = $annualRate / 12 / 100;

        // validate inputs
        if ($balance <= 0 || $monthlyPayment <= 0) {
            throw new \InvalidArgumentException("Invalid input: balance and monthlyPayment must be greater than 0");
        }
        if ($annualRate < 0) {
            throw new \InvalidArgumentException("Invalid input: interestRate must not be negative");
        }
        if ($monthlyRate > 0 && $monthlyPayment <= $balance * $monthlyRate) {
            // FIXME: we could really benefit from a custom exception here, that could contain all this data
            throw new \RangeException(
                "The monthly payment must be greater than the interest accrued each month: "
                . "monthlyPayment ({$monthlyPayment}) must be > than monthlyRate ({$monthlyRate}) * balance ({$balance})"
            );
        }

        // if the interest rate is 0, calculate payments directly
        if ($monthlyRate == 0) {
            return $balance / $monthlyPayment;
        }

        return log($monthlyPayment / ($monthlyPayment - $balance * $monthlyRate)) / log(1 + $monthlyRate);
    }

    /**
     * Calculate the present value of a loan or an investment based on periodic, constant payments and a constant
     * interest rate.  This is equivalent to Excel's PV function.
     * 
     * Reference:
     *   https://support.microsoft.com/en-us/office/pv-function-23879d31-0e02-4321-be01-da16e8168cbd
     * 
     * The formula for present value is:
     *   PV = PMT × (1 - (1 + rate)^(-nper)) / rate + FV × (1 + rate)^(-nper)
     * 
     * Where:
     *   PV = Present value (the result)
     *   PMT = Payment made each period
     *   rate = Interest rate per period
     *   nper = Total number of payment periods
     *   FV = Future value (cash balance after last payment)
     *   type = When payments are due (0 = end of period, 1 = beginning of period)
     * 
     * @param float $rate Interest rate per period. For example, if you get a car loan at 10% annual interest and make
     *                    monthly payments, the rate per period is 10/100/12 or 0.00833. If you make annual payments,
     *                    the rate is 10/100.
     * @param int $nper Total number of payment periods. For example, if you make monthly payments on a four-year car
     *                  loan, your loan has 4*12 (or 48) periods.
     * @param float $pmt Payment made each period. Cannot change over the life of the investment.
     * @param float $fv [Optional] Future value, or cash balance you want to attain after the last payment is made. 
     *                  Default is 0 (for example, the future value of a loan is 0).
     * @param int $type [Optional] When payments are due. 0 = end of period (default), 1 = beginning of period.
     * @return float The present value of an investment.
     */
    public static function pv(float $rate, int $nper, float $pmt, ?float $fv = 0, ?int $type = 0): float
    {
        // validate inputs
        if ($nper <= 0) {
            throw new \InvalidArgumentException("Invalid input: nper must be greater than 0");
        }
        if ($type !== 0 && $type !== 1) {
            throw new \InvalidArgumentException("Invalid input: type must be 0 or 1");
        }

        // if rate is 0, the formula is simple
        if ($rate == 0) {
            return -($pmt * $nper + $fv);
        }

        $pvFactor = pow(1 + $rate, -$nper);
        $presentValue = $fv * $pvFactor;
        
        // adjust for payment timing (beginning or end of period)
        if ($type == 1) {
            $presentValue += $pmt * (1 - $pvFactor) / $rate * (1 + $rate);
        } else {
            $presentValue += $pmt * (1 - $pvFactor) / $rate;
        }
        
        // return negative of the result to match Excel's behavior
        // (Excel returns negative for money paid out, positive for money received)
        return -$presentValue;
    }

    /**
     * Calculate the future value of an investment based on periodic, constant payments and a constant interest rate.
     * This is equivalent to Excel's FV function.
     * 
     * Reference:
     *   https://support.microsoft.com/en-us/office/fv-function-2eef9f44-a084-4c61-bdd8-4fe4bb1b71b3
     * 
     * The formula for future value is:
     *   FV = PV × (1 + rate)^nper + PMT × ((1 + rate)^nper - 1) / rate × (1 + rate × type)
     * 
     * Where:
     *   FV = Future value (the result)
     *   PV = Present value (initial investment)
     *   PMT = Payment made each period
     *   rate = Interest rate per period
     *   nper = Total number of payment periods
     *   type = When payments are due (0 = end of period, 1 = beginning of period)
     * 
     * @param float $rate Interest rate per period. For example, if you get a car loan at 10% annual interest and make
     *                    monthly payments, the rate per period is 10/100/12 or 0.00833.
     * @param int $nper Total number of payment periods. For example, if you make monthly payments on a four-year car
     *                  loan, your loan has 4*12 (or 48) periods.
     * @param float $pmt Payment made each period. Cannot change over the life of the investment.
     * @param float $pv [Optional] Present value, or lump-sum investment. Default is 0.
     * @param int $type [Optional] When payments are due. 0 = end of period (default), 1 = beginning of period.
     * @return float The future value of an investment.
     */
    public static function fv(float $rate, int $nper, float $pmt, ?float $pv = 0, ?int $type = 0): float
    {
        // validate inputs
        if ($nper <= 0) {
            throw new \InvalidArgumentException("Invalid input: nper must be greater than 0");
        }
        if ($type !== 0 && $type !== 1) {
            throw new \InvalidArgumentException("Invalid input: type must be 0 or 1");
        }

        // if rate is 0, the formula is simple
        if ($rate == 0) {
            return -($pv + ($pmt * $nper));
        }

        $futureValue = $pv * pow(1 + $rate, $nper);
        
        // add in the future value of the payments
        $futureValueOfPayments = $pmt * ((pow(1 + $rate, $nper) - 1) / $rate);
        
        // adjust for payment timing (beginning or end of period)
        if ($type == 1) {
            $futureValueOfPayments *= (1 + $rate);
        }
        
        $futureValue += $futureValueOfPayments;
        
        // return negative of the result to match Excel's behavior
        // (Excel returns negative for money paid out, positive for money received)
        return -$futureValue;
    }

    /**
     * Calculate the number of periods for an investment based on periodic, constant payments and a constant interest rate.
     * This is equivalent to Excel's NPER function.
     * 
     * Reference:
     *   https://support.microsoft.com/en-us/office/nper-function-240535b5-6653-4d2d-bfcf-b6a38151d815
     * 
     * The formula for NPER when rate != 0 is:
     *   NPER = log((PMT * (1 + RATE * TYPE) - FV * RATE) / (PV * RATE + PMT * (1 + RATE * TYPE))) / log(1 + RATE)
     * 
     * When rate = 0, the formula simplifies to:
     *   NPER = -(PV + FV) / PMT
     * 
     * Where:
     *   NPER = Number of periods (the result)
     *   PMT = Payment made each period
     *   PV = Present value (initial investment)
     *   FV = Future value
     *   RATE = Interest rate per period
     *   TYPE = When payments are due (0 = end of period, 1 = beginning of period)
     * 
     * @param float $rate Interest rate per period. For example, if you get a car loan at 10% annual interest and make
     *                    monthly payments, the rate per period is 10/100/12 or 0.00833.
     * @param float $pmt Payment made each period. Cannot change over the life of the investment.
     * @param float $pv Present value, or the lump-sum amount that a series of future payments is worth right now.
     * @param float $fv [Optional] Future value, or a cash balance you want to attain after the last payment is made.
     *                  Default is 0.
     * @param int $type [Optional] When payments are due. 0 = end of period (default), 1 = beginning of period.
     * @return float The number of periods for the investment.
     */
    public static function nper(float $rate, float $pmt, float $pv, ?float $fv = 0, ?int $type = 0): float
    {
        // validate inputs
        if ($type !== 0 && $type !== 1) {
            throw new \InvalidArgumentException("Invalid input: type must be 0 or 1");
        }
        if ($pmt == 0 && $rate == 0) {
            throw new \InvalidArgumentException("When rate is 0, pmt cannot be 0");
        }
        
        // if rate is 0, the formula is simple
        if ($rate == 0) {
            return -($pv + $fv) / $pmt;
        }
        
        // adjust for payment timing (beginning or end of period)
        $paymentAdjustment = 1 + $rate * $type;
        
        $numerator = $pmt * $paymentAdjustment - $fv * $rate;
        $denominator = $pv * $rate + $pmt * $paymentAdjustment;
        
        // check if the calculation would result in a negative or zero value inside the logarithm
        if ($numerator * $denominator <= 0) {
            throw new \InvalidArgumentException("The combination of parameters results in an impossible calculation");
        }
        
        return log($numerator / $denominator) / log(1 + $rate);
    }

    /**
     * Calculate the payment for a loan based on constant payments and a constant interest rate.
     * This is equivalent to Excel's PMT function.
     * 
     * Reference:
     *   https://support.microsoft.com/en-us/office/pmt-function-0214da64-9a63-4996-bc20-214433fa6441
     * 
     * The formula for PMT is:
     *   PMT = (PV * rate * (1 + rate)^nper) / ((1 + rate)^nper - 1) * (1 + rate * type)
     *   
     * When rate = 0, the formula simplifies to:
     *   PMT = PV / nper
     * 
     * Where:
     *   PMT = Payment amount per period (the result)
     *   PV = Present value (loan amount)
     *   rate = Interest rate per period
     *   nper = Total number of payment periods
     *   FV = Future value (default is 0)
     *   type = When payments are due (0 = end of period, 1 = beginning of period)
     * 
     * @param float $rate Interest rate per period. For example, if you get a car loan at 10% annual interest and make
     *                    monthly payments, the rate per period is 10/100/12 or 0.00833.
     * @param int $nper Total number of payment periods. For example, if you make monthly payments on a four-year car
     *                  loan, your loan has 4*12 (or 48) periods.
     * @param float $pv Present value, or the lump-sum amount that a series of future payments is worth right now.
     *                  For a loan, this is the loan amount.
     * @param float $fv [Optional] Future value, or a cash balance you want to attain after the last payment is made.
     *                  Default is 0. For a loan, the future value is 0.
     * @param int $type [Optional] When payments are due. 0 = end of period (default), 1 = beginning of period.
     * @return float The payment amount per period.
     */
    public static function pmt(float $rate, int $nper, float $pv, ?float $fv = 0, ?int $type = 0): float
    {
        // validate inputs
        if ($nper <= 0) {
            throw new \InvalidArgumentException("Invalid input: nper must be greater than 0");
        }
        if ($type !== 0 && $type !== 1) {
            throw new \InvalidArgumentException("Invalid input: type must be 0 or 1");
        }
        
        // if rate is 0, the formula is simple
        if ($rate == 0) {
            return ($pv + $fv) / $nper * -1;
        }
        
        // calculate the payment
        $pvFactor = pow(1 + $rate, $nper);
        $payment = $rate * ($pv * $pvFactor + $fv) / ($pvFactor - 1);
        
        // adjust for payment timing (beginning or end of period)
        if ($type == 1) {
            $payment /= (1 + $rate);
        }
        
        // return negative of the result to match Excel's behavior
        // (Excel returns negative for money paid out, positive for money received)
        return $payment * -1;
    }

}
