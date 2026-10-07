<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class VatReturnReportTest extends TestCase
{
    use WithAuthentication;

    private function requestReport()
    {
        return $this->json( 'POST', '/api/reports/vat-return', [
            'startDate' => ns()->date->copy()->startOfMonth()->toDateTimeString(),
            'endDate' => ns()->date->copy()->endOfMonth()->toDateTimeString(),
        ] );
    }

    /**
     * The report aggregates a line count. "lines" is a reserved word in MySQL
     * and MariaDB, so an unquoted alias made the whole endpoint fail with a
     * syntax error before this was quoted.
     */
    public function test_vat_return_report_can_be_computed(): void
    {
        $this->attemptAuthenticate();

        $response = $this->requestReport();

        $response->assertStatus( 200 );
        $response->assertJsonStructure( [
            'period' => [ 'start', 'end', 'rate' ],
            'summary',
            'output_tax',
            'standard_net',
            'standard_gross',
            'zero_rated',
            'exempt',
            'total_sales',
            'invoices' => [ 'issued', 'cancelled' ],
        ] );
    }

    public function test_vat_return_report_totals_are_numeric(): void
    {
        $this->attemptAuthenticate();

        $response = $this->requestReport();

        $response->assertStatus( 200 );

        foreach ( [ 'output_tax', 'standard_net', 'standard_gross', 'zero_rated', 'exempt', 'total_sales' ] as $key ) {
            $this->assertIsNumeric( $response->json( $key ), sprintf( 'The "%s" total is not numeric.', $key ) );
        }
    }

    public function test_vat_return_report_summary_rows_are_labelled(): void
    {
        $this->attemptAuthenticate();

        $response = $this->requestReport();

        $response->assertStatus( 200 );

        $summary = $response->json( 'summary' );

        $this->assertNotEmpty( $summary, 'The report returned no summary rows.' );

        foreach ( $summary as $row ) {
            $this->assertArrayHasKey( 'label', $row );
            $this->assertArrayHasKey( 'total', $row );
            $this->assertNotSame( '', trim( (string) $row[ 'label' ] ), 'A summary row has an empty label.' );
        }
    }
}
