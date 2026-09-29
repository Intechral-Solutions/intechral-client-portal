<?php

namespace App\Shared\Navigation;

/**
 * The Operational presentation's per-workspace contextual-panel default (Direction D §5.3).
 *
 * This is the server half of the drawer-state contract: the default a client falls back to when it
 * holds no valid remembered state for `(user, workspace)`. It is a presentation hint, namespaced
 * under `presentation.operational`, and carries no authority over navigation content — dropping it
 * must leave the authorized model untouched (EPIC-013 §12.3 rule 4).
 *
 * A workspace with one surface and no contextual navigation has no panel at all, expressed as a
 * null hint beside an empty `context` (§12.3 rule 6), never as a case here.
 */
enum PanelDefault: string
{
    case Open = 'open';

    case Collapsed = 'collapsed';
}
