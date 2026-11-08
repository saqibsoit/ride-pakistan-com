<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** An account credit or referral rule said no (milestone 10e). Safe to show an admin. */
final class CreditRejected extends RuntimeException {}
