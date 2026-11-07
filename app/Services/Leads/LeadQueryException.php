<?php

declare(strict_types=1);

namespace App\Services\Leads;

use RuntimeException;

/** The AI could not produce a usable search from the brief. */
final class LeadQueryException extends RuntimeException {}
