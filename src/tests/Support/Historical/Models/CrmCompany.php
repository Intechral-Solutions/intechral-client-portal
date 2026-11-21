<?php

namespace Tests\Support\Historical\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only (Release 1.0, ORG-2): the RETIRED `crm_companies` row, for the tests under tests/Historical that build the schema as it was before ORG-2. The
 * production model, its factory and its tenant scope were removed with the table; nothing outside tests/Historical may use this class. No global scope: a
 * historical test seeds rows with nobody signed in or with an operator.
 */
class CrmCompany extends Model
{
    use HasFactory;

    protected $table = 'crm_companies';

    protected $guarded = [];
}
