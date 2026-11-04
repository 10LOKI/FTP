<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

class IssuedCustomerCode extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['code', 'customer_profile_id', 'issued_at'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Issued customer codes cannot be changed.');
        });
        static::deleting(function (): void {
            throw new LogicException('Issued customer codes cannot be deleted.');
        });
    }

    public static function issueFor(CustomerProfile $profile, ?string $requestedCode): string
    {
        return DB::transaction(function () use ($profile, $requestedCode): string {
            $sequence = DB::table('customer_code_sequences')->where('name', 'customer')->lockForUpdate()->firstOrFail();
            $existing = self::where('customer_profile_id', $profile->id)->first();
            $code = $profile->customer_code ?? $requestedCode;

            abort_if($profile->customer_code !== null && $requestedCode !== null && $profile->customer_code !== $requestedCode,
                409, 'The existing customer code must be preserved.');

            if ($existing !== null) {
                abort_if($code !== null && $code !== $existing->code, 409, 'The issued customer code cannot be changed.');

                return $existing->code;
            }

            if ($code === null) {
                $next = (int) $sequence->next_number;

                do {
                    abort_if($next >= PHP_INT_MAX, 409, 'The customer code sequence is exhausted.');
                    $code = sprintf('CLI-%06d', $next++);
                } while (self::whereKey($code)->exists() || CustomerProfile::where('customer_code', $code)->exists());

                DB::table('customer_code_sequences')->where('name', 'customer')->update(['next_number' => $next]);
            }

            abort_if(self::whereKey($code)->exists()
                || CustomerProfile::where('customer_code', $code)->whereKeyNot($profile->id)->exists(),
                409, 'This customer code has already been issued.');

            self::create(['code' => $code, 'customer_profile_id' => $profile->id, 'issued_at' => now('UTC')]);

            return $code;
        });
    }
}
