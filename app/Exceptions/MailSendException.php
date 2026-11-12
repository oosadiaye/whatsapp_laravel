<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A send that cannot go ahead, with a message already written for the person to read. */
class MailSendException extends RuntimeException {}
