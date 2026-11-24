<?php

namespace App\Customer\Infrastructure;

use RuntimeException;

/** A provider sign-in that cannot continue; the customer only sees one neutral message. */
final class SocialSignInFailed extends RuntimeException {}
