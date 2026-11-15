<?php

namespace App\Models;

use App\Enums\AdmissionRequestStatus;
use Database\Factories\AdmissionRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $business_name
 * @property string $contact_email
 * @property string|null $phone
 * @property string|null $city
 * @property int|null $tables
 * @property string|null $message
 * @property AdmissionRequestStatus $status
 */
final class AdmissionRequest extends Model
{
    /** @use HasFactory<AdmissionRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'business_name',
        'contact_email',
        'phone',
        'city',
        'tables',
        'message',
        'status',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'tables' => 'integer',
            'status' => AdmissionRequestStatus::class,
        ];
    }
}
