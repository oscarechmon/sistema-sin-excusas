<?php

declare(strict_types=1);

namespace Modules\Agenda\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalog\Models\Product;
use Modules\Contacts\Models\Customer;
use Modules\Staff\Models\Employee;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/** Cita de un cliente con un especialista. */
class Appointment extends Model implements Auditable
{
    use AuditableTrait;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_ATTENDED = 'attended';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_POSTPONED = 'postponed';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_ATTENDED,
        self::STATUS_CANCELLED, self::STATUS_POSTPONED, self::STATUS_NO_SHOW,
    ];

    /** Estados que ya no ocupan el horario del especialista. */
    public const FREE_STATUSES = [self::STATUS_CANCELLED, self::STATUS_POSTPONED, self::STATUS_NO_SHOW];

    protected $fillable = [
        'customer_id', 'service_id', 'employee_id', 'appointment_date',
        'start_time', 'end_time', 'title', 'status', 'notes', 'created_by',
    ];

    protected $casts = ['appointment_date' => 'date'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'service_id')->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
