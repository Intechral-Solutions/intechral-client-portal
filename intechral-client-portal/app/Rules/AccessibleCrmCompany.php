<?php

namespace App\Rules;

use App\Models\CrmCompany;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Require a CRM company visible through the current tenant scope.
 */
class AccessibleCrmCompany implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! CrmCompany::query()->whereKey($value)->exists()) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
