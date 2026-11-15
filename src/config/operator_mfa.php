<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operator second factor (Release 1.0)
    |--------------------------------------------------------------------------
    |
    | An account holding `portal.operator` must have a confirmed second factor before it reaches anything privileged
    | (App\Shared\Mfa\OperatorMfaGate). This is a security floor, deliberately NOT an environment variable and not a rollout
    | switch: the only value that can turn it off is `false`, and that is honoured ONLY when APP_ENV is `testing` (the test
    | base class sets it, so suites about other operator features need not enrol an operator; the operator-MFA tests turn it back
    | on). In every other environment the value is ignored and enforcement is unconditional, with no grace period.
    | docs/architecture/operator-mfa.md
    |
    */

    'enforced' => true,

];
