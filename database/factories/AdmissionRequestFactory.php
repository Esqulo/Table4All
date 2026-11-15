<?php

namespace Database\Factories;

use App\Enums\AdmissionRequestStatus;
use App\Models\AdmissionRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionRequest>
 */
class AdmissionRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_name' => fake()->company(),
            'contact_email' => fake()->safeEmail(),
            'phone' => '(31) 99999-9999',
            'city' => fake()->city(),
            'tables' => fake()->numberBetween(4, 40),
            'message' => fake()->sentence(),
            'status' => AdmissionRequestStatus::PENDING,
        ];
    }
}
