<?php

namespace App\Shared\Shell;

/**
 * The shell presentation family (EPIC-013 §23.1, EPIC-017 P1).
 *
 * It names HOW the already-authorized navigation model is projected, never WHO may do what. Nothing
 * authorizes, gates or filters on it: routes, policies and queries never read it.
 */
enum ShellPresentation: string
{
    /** Rail, contextual drawer and utility bar: frequent or complex work. */
    case Operational = 'operational';

    /** Top bar with at most five plain-language entries: lighter participation (the customer shell). */
    case Focused = 'focused';
}
