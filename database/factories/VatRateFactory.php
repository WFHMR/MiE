<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

class VatRateFactory extends Factory
{
    protected $model = VatRate::class;

    public function definition()
    {
        return [
            'label' => 'VAT ' . $this->faker->numberBetween( 5, 10 ) . '%',
            'rate' => $this->faker->numberBetween( 5, 10 ),
            'effective_from' => now()->subYear()->startOfDay(),
            'effective_to' => null,
            'legal_reference' => null,
            'author_id' => User::first()?->id,
        ];
    }

    /**
     * A closed period, useful to assert that a historical sale keeps the rate it
     * was taxed with.
     */
    public function closed( string $from, string $to, float $rate ): static
    {
        return $this->state( fn() => [
            'label' => 'VAT ' . $rate . '%',
            'rate' => $rate,
            'effective_from' => $from,
            'effective_to' => $to,
        ] );
    }
}
