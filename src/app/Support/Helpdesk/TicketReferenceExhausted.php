<?php

namespace App\Support\Helpdesk;

use RuntimeException;

/** Every bounded attempt to issue a ticket reference collided. The creation is rolled back whole; nothing was stored or sent. */
class TicketReferenceExhausted extends RuntimeException {}
