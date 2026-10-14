/**
 * What the customer-pilot browser spec learns about the server before it runs, and what it does with it
 * (Release 0.9, R0.9-CS1).
 *
 * The probe is three statuses a signed-in customer receives. They distinguish the three customer surface modes by
 * behaviour, so the test never trusts a configuration value it cannot see:
 *
 *   legacy   /my/invoices answers 200        (the behaviour before the boundary)
 *   closed   /my/invoices and /tickets both answer 404
 *   pilot    /my/invoices answers 404, /tickets and /projects answer 200
 *
 * When the run REQUIRES pilot mode (`E2E_REQUIRE_PILOT_GATE=1`, set by the hosted job) anything but pilot is an
 * error: a legacy server, a closed one, a 500, a redirect or any other status never turns into a skip, because a
 * skipped required security test is indistinguishable from a passing one in a green build. When it is not required,
 * the only thing that skips the spec is a server that is plainly in legacy mode (the default development stack).
 */
export type PilotProbe = { invoices: number; tickets: number; projects: number };

export type PilotVerdict = 'run' | 'skip';

export type DetectedMode = 'pilot' | 'closed' | 'legacy' | 'unknown';

export function detectMode(probe: PilotProbe): DetectedMode {
    if (probe.invoices === 200) return 'legacy';
    if (probe.invoices === 404 && probe.tickets === 200 && probe.projects === 200) return 'pilot';
    if (probe.invoices === 404 && probe.tickets === 404) return 'closed';

    return 'unknown';
}

/** Whether a run demanded pilot mode: set and not one of the spellings of "no". */
export function pilotRequired(value: string | undefined): boolean {
    if (value === undefined) return false;

    return !['', '0', 'false', 'no', 'off'].includes(value.trim().toLowerCase());
}

export function describeProbe(probe: PilotProbe): string {
    return `/my/invoices ${probe.invoices}, /tickets ${probe.tickets}, /projects ${probe.projects}`;
}

/**
 * `run` when the server is in pilot mode. `skip` only for a legacy server when pilot mode was not required.
 * Everything else throws, with the observed behaviour in the message.
 */
export function decidePilotRun(probe: PilotProbe, required: boolean): PilotVerdict {
    const mode = detectMode(probe);

    if (mode === 'pilot') return 'run';

    if (required) {
        throw new Error(
            `The customer pilot browser spec is REQUIRED (E2E_REQUIRE_PILOT_GATE) but the server is not in pilot mode ` +
                `(detected: ${mode}; ${describeProbe(probe)}). Start the stack with PORTAL_CUSTOMER_SURFACE_MODE=pilot.`,
        );
    }

    if (mode === 'legacy') return 'skip';

    throw new Error(
        `The server is neither in legacy nor in pilot mode (detected: ${mode}; ${describeProbe(probe)}). ` +
            `This spec skips only on a legacy server; fix the stack configuration.`,
    );
}
