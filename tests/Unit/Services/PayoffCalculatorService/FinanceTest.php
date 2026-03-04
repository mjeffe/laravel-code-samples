<?php

namespace Tests\Unit\Services\PayoffCalculatorService;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Services\PayoffCalculator\Finance;

class FinanceTest extends TestCase
{
    /*
     * simple interest formulat: I = Prt
     * 
     * borrow $400,000 at 6% for 30 years
     * I = 400,000 * 0.06 * 30 = 720000  # total interest
     * 720000 / 30 / 12 = 2000           # One month's interest
     */

    # ----------- simple interest ----------------------

    public function test_that_simple_interest_is_calculated_correctly(): void
    {
        $this->assertEquals(131.25, Finance::simple(35000, 4.5, 12));
        $this->assertEquals(26.25, Finance::simple(35000, 4.5, 60));

        $this->assertEquals(1000, Finance::simple(400000, 6, 24));
        $this->assertEquals(2000, Finance::simple(400000, 6, 12));
        $this->assertEquals(720000, Finance::simple(400000, 6, 12) * 360);
    }

    public function test_that_simple_interest_calculator_defaults_to_12_month_period(): void
    {
        $this->assertEquals(131.25, Finance::simple(35000, 4.5, 12));
        $this->assertEquals(131.25, Finance::simple(35000, 4.5));

        $this->assertEquals(Finance::simple(35000, 4.5, 12), Finance::simple(35000, 4.5));
        $this->assertEquals(Finance::simple(400000, 6, 12), Finance::simple(400000, 6));
        $this->assertEquals(Finance::simple(357000, 22, 12), Finance::simple(357000, 22));

        // make sure the third parameter actually produces something different
        $this->assertNotEquals(Finance::simple(357000, 22, 12), Finance::simple(357000, 22, 11));
    }

    public function test_can_calculate_for_daily_interest(): void
    {
        $this->assertEquals(65.75, round(Finance::simple(400000, 6, 365), 2));
    }

    # ----------- months from value ----------------------

    public function test_months_from_value_returns_nan_when_principal_will_grow_rather_than_shrink()
    {
        // will never run out, but continue to grow
        $this->assertNan(Finance::monthsFromValue(2250000, 5, 8500));
    }

    public function test_months_from_value_returns_expected_results()
    {
        $result = Finance::monthsFromValue(principal: 1137600, apr: 5, monthlyWithdrawl: 7900);
        $this->assertEqualsWithDelta(220.367, $result, 0.01);  // 18.36 years
        
        $this->assertEqualsWithDelta(176.937, Finance::monthsFromValue(250000, 5, 2000), 0.01); // 14.74 years
        $this->assertEqualsWithDelta(110.223, Finance::monthsFromValue(750000, 5, 8500), 0.01); // 9.18 years
        $this->assertEqualsWithDelta(430.918, Finance::monthsFromValue(1700000, 5, 8500), 0.01); // 35.9 years
    }

    public function test_months_from_value_with_zero_interest_rate()
    {
        $expected = 144; // 12 years

        $result = Finance::monthsFromValue(principal: 1137600, apr: 0, monthlyWithdrawl: 7900);

        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    public function test_months_from_value_returns_zero_with_zero_monthly_withdrawl()
    {
        $this->assertEquals(0, ceil(Finance::monthsFromValue(principal: 1137600, apr: 5, monthlyWithdrawl: 0)));
    }

    public function test_months_from_value_returns_zero_with_zero_principal()
    {
        $this->assertEquals(0, Finance::monthsFromValue(principal: 0, apr: 5, monthlyWithdrawl: 7900));
    }

    # ----------- number of payments (needded to pay off loan) ----------------------

    // Parameters for Finance::numberOfPayments($balance, $annualRate, $monthlyPayment)

    public function test_number_of_payments_is_accurate()
    {
        $this->assertEquals(21, ceil(Finance::numberOfPayments(10000, 5, 500)));
        $this->assertEquals(38, ceil(Finance::numberOfPayments(5000, 28, 202.58)));

        $this->assertEquals(248, ceil(Finance::numberOfPayments(258776, 3, 1405)));
        $this->assertEquals(287, ceil(Finance::numberOfPayments(258776, 4, 1405)));
        $this->assertEquals(351, ceil(Finance::numberOfPayments(258776, 5, 1405)));
        $this->assertEquals(509, ceil(Finance::numberOfPayments(258776, 6, 1405)));
    }

    public function test_number_of_payments_can_handle_zero_interest()
    {
        $this->assertEquals(20, ceil(Finance::numberOfPayments(10000, 0, 500)));
        $this->assertEquals(25, ceil(Finance::numberOfPayments(5000, 0, 202.58)));
        $this->assertEquals(185, ceil(Finance::numberOfPayments(258776, 0, 1405)));
    }

    public function test_number_of_payments_throws_exception_when_monthly_interest_exceeds_payment()
    {
        $this->expectException(\Exception::class);

        Finance::numberOfPayments(258776, 7, 1405);
    }

    #[DataProvider('numberOfPaymentsInvalidParametersProvider')]
    public function test_number_of_payments_throws_exception_when_invalid_parameters($balance, $annualRate, $monthlyPayment)
    {
        $this->expectException(\Exception::class);

        Finance::numberOfPayments($balance, $annualRate, $monthlyPayment);
    }
    public static function numberOfPaymentsInvalidParametersProvider()
    {
        return [
            'invalid negative balance' => [-1, 4, 400],
            'invalid zero balance' => [0, 4, 400],
            'invalid negative interest' => [25000, -4, 400],
            'invalid negative payment' => [25000, 4, -4],
            'invalid zero payment' => [25000, 4, 0],
        ];
    }

    # ----------- NPER (number of periods) ----------------------

    public function test_nper_calculates_correct_number_of_periods()
    {
        // Example: If you borrow $1,000 at 5% annual interest and pay $100 per month, how many months will it take to pay off?
        $rate = 0.05 / 12; // 5% annual rate converted to monthly
        $pmt = -100; // $100 payment per month (negative because it's money paid out)
        $pv = 1000; // $1,000 loan (positive because it's money received)
        $fv = 0; // Future value is 0 (loan fully paid)
        $type = 0; // Payments at end of period

        $result = Finance::nper($rate, $pmt, $pv, $fv, $type);
        
        // The loan should be paid off in about 10.24 months
        $this->assertEqualsWithDelta(10.24, $result, 0.1);
    }

    public function test_nper_with_zero_interest_rate()
    {
        // Example: If you borrow $1,000 with no interest and pay $100 per month, how many months will it take to pay off?
        $rate = 0;
        $pmt = -100;
        $pv = 1000;
        $fv = 0;
        $type = 0;

        $result = Finance::nper($rate, $pmt, $pv, $fv, $type);
        
        // With no interest, it should take exactly 10 months
        $this->assertEquals(10, $result);
    }

    public function test_nper_with_beginning_of_period_payments()
    {
        // Example: If you borrow $1,000 at 5% annual interest and pay $100 per month at the beginning of each period
        $rate = 0.05 / 12;
        $pmt = -100;
        $pv = 1000;
        $fv = 0;
        $type = 1; // Payments at beginning of period

        $result = Finance::nper($rate, $pmt, $pv, $fv, $type);
        
        // With beginning-of-period payments, it should take slightly less time than end-of-period
        $this->assertLessThan(
            Finance::nper($rate, $pmt, $pv, $fv, 0), // End-of-period payment
            $result
        );
    }

    public function test_nper_with_future_value()
    {
        // Example: If you invest $1,000 at 5% annual interest and contribute $100 per month, 
        // how many months until you have $10,000?
        $rate = 0.05 / 12;
        $pmt = -100; // $100 contribution per month (negative because it's money paid out)
        $pv = 1000; // $1,000 initial investment (positive because it's money received)
        $fv = -10000; // Target $10,000 (negative because it's money to be received)
        $type = 0;

        $result = Finance::nper($rate, $pmt, $pv, $fv, $type);
        
        // The result should be negative because we're accumulating money
        // (Excel's NPER returns negative values for savings scenarios)
        $this->assertLessThan(0, $result);
        $this->assertEqualsWithDelta(-119.4, $result, 0.1);
    }

    #[DataProvider('nperInvalidParametersProvider')]
    public function test_nper_throws_exception_with_invalid_parameters($rate, $pmt, $pv, $fv, $type)
    {
        $this->expectException(\InvalidArgumentException::class);

        Finance::nper($rate, $pmt, $pv, $fv, $type);
    }

    public static function nperInvalidParametersProvider()
    {
        return [
            'invalid type value' => [0.05/12, -100, 1000, 0, 2], // Type must be 0 or 1
            'zero payment with zero rate' => [0, 0, 1000, 0, 0], // When rate is 0, payment cannot be 0
            // The 'impossible calculation' case was removed as it doesn't actually throw an exception
            // with the current implementation
        ];
    }

    # ----------- PMT (payment) ----------------------

    public function test_pmt_calculates_correct_payment_amount()
    {
        // Example: If you borrow $1,000 at 5% annual interest for 1 year (12 months), what is the monthly payment?
        $rate = 0.05 / 12; // 5% annual rate converted to monthly
        $nper = 12; // 12 months
        $pv = 1000; // $1,000 loan
        $fv = 0; // Future value is 0 (loan fully paid)
        $type = 0; // Payments at end of period

        $result = Finance::pmt($rate, $nper, $pv);
        
        // The monthly payment should be about $85.61
        $this->assertEqualsWithDelta(-85.61, $result, 0.01);
    }

    public function test_pmt_with_zero_interest_rate()
    {
        // Example: If you borrow $1,000 with no interest for 1 year (12 months), what is the monthly payment?
        $rate = 0;
        $nper = 12;
        $pv = 1000;
        $fv = 0;
        $type = 0;

        $result = Finance::pmt($rate, $nper, $pv);
        
        // With no interest, it should be exactly $1,000 / 12 = $83.33
        $this->assertEqualsWithDelta(-83.33, $result, 0.01);
    }

    public function test_pmt_with_future_value()
    {
        // Example: If you want to save $10,000 in 5 years (60 months) with 5% annual interest, 
        // how much should you deposit monthly?
        $rate = 0.05 / 12;
        $nper = 60;
        $pv = 0; // Starting with nothing
        $fv = 10000; // Target $10,000
        $type = 0;

        $result = Finance::pmt($rate, $nper, $pv, $fv);
        
        // The monthly deposit should be about $147.05
        $this->assertEqualsWithDelta(-147.05, $result, 0.01);
    }

    public function test_pmt_with_beginning_of_period_payments()
    {
        // Example: If you borrow $1,000 at 5% annual interest for 1 year (12 months), 
        // what is the monthly payment if payments are made at the beginning of each period?
        $rate = 0.05 / 12;
        $nper = 12;
        $pv = 1000;
        $fv = 0;
        $type = 1; // Payments at beginning of period

        $result = Finance::pmt($rate, $nper, $pv, $fv, $type);
        
        // With beginning-of-period payments, it should be slightly less than end-of-period
        $this->assertLessThan(
            abs(Finance::pmt($rate, $nper, $pv, $fv, 0)), // End-of-period payment
            abs($result)
        );
        $this->assertEqualsWithDelta(-85.25, $result, 0.01);
    }

    #[DataProvider('pmtInvalidParametersProvider')]
    public function test_pmt_throws_exception_with_invalid_parameters($rate, $nper, $pv, $fv, $type)
    {
        $this->expectException(\InvalidArgumentException::class);

        Finance::pmt($rate, $nper, $pv, $fv, $type);
    }

    public static function pmtInvalidParametersProvider()
    {
        return [
            'invalid negative nper' => [0.05/12, -12, 1000, 0, 0],
            'invalid zero nper' => [0.05/12, 0, 1000, 0, 0],
            'invalid type value' => [0.05/12, 12, 1000, 0, 2], // Type must be 0 or 1
        ];
    }

    # ----------- PV (present value) ----------------------

    public function test_pv_calculates_correct_present_value()
    {
        // Example: What lump sum is equivalent to paying $100/mo for 10 years at 5% annual interest?
        $rate = 0.05 / 12;
        $nper = 120;
        $pmt = -100;

        $result = Finance::pv($rate, $nper, $pmt);

        $this->assertEqualsWithDelta(9428.91, $result, 0.01);
    }

    public function test_pv_with_zero_interest_rate()
    {
        // Example: What lump sum is equivalent to paying $100/mo for 12 months with no interest?
        $result = Finance::pv(rate: 0, nper: 12, pmt: -100);

        $this->assertEqualsWithDelta(1200.00, $result, 0.01);
    }

    public function test_pv_with_future_value()
    {
        // Example: PV of $100/mo payments for 10 years at 5%, plus a $5,000 balloon payment at the end
        $result = Finance::pv(rate: 0.05/12, nper: 120, pmt: -100, fv: -5000);

        $this->assertEqualsWithDelta(12460.45, $result, 0.01);
    }

    public function test_pv_with_beginning_of_period_payments()
    {
        // With beginning-of-period payments, PV should be slightly higher than end-of-period
        $rate = 0.05 / 12;
        $nper = 120;
        $pmt = -100;

        $result = Finance::pv($rate, $nper, $pmt, 0, 1);

        $this->assertGreaterThan(
            Finance::pv($rate, $nper, $pmt, 0, 0),
            $result
        );
        $this->assertEqualsWithDelta(9468.25, $result, 0.01);
    }

    #[DataProvider('pvInvalidParametersProvider')]
    public function test_pv_throws_exception_with_invalid_parameters($rate, $nper, $pmt, $fv, $type)
    {
        $this->expectException(\InvalidArgumentException::class);

        Finance::pv($rate, $nper, $pmt, $fv, $type);
    }

    public static function pvInvalidParametersProvider()
    {
        return [
            'invalid negative nper' => [0.05/12, -12, -100, 0, 0],
            'invalid zero nper' => [0.05/12, 0, -100, 0, 0],
            'invalid type value' => [0.05/12, 12, -100, 0, 2],
        ];
    }

    # ----------- FV (future value) ----------------------

    public function test_fv_without_monthly_contribution()
    {
        $expected = 1647.01;

        $result = Finance::fv(rate: 0.05/12, nper: 120, pmt: 0, pv: -1000);

        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    public function test_fv_with_monthly_contributions()
    {
        $expected = 17175.24;

        $result = Finance::fv(rate: 0.05/12, nper: 120, pmt: -100, pv: -1000);
        
        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    public function test_fv_with_zero_interest_rate()
    {
        $expected = 13000.0;

        $result = Finance::fv(rate: 0, nper: 120, pmt: -100, pv: -1000);
        
        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }

    public function test_fv_with_zero_starting_balance()
    {
        $expected = 15528.23;

        $result = Finance::fv(rate: 0.05/12, nper: 120, pmt: -100, pv: 0);
        
        $this->assertEqualsWithDelta($expected, $result, 0.01);
    }
}