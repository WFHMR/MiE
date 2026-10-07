<?php

namespace Database\Factories;

/** @var \Illuminate\Database\Eloquent\Factory $factory */

use App\Classes\Hook;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition()
    {
        $name = $this->faker->name;

        return Hook::filter( 'ns-unit-factory', [
            'name' => $name,
            /**
             * nexopos_units.identifier is NOT NULL and unique. Without it here
             * Unit::factory() always failed on an integrity constraint.
             */
            'identifier' => Str::slug( $name ) . '-' . $this->faker->unique()->numberBetween( 1, 999999 ),
            'description' => $this->faker->sentence,
            'base_unit' => $this->faker->randomElement( [ 0, 1 ] ),
            'value' => $this->faker->numberBetween( 5, 20 ),
            'author_id' => $this->faker->randomElement( User::get()->map( fn( $user ) => $user->id ) ),
        ] );
    }
}
