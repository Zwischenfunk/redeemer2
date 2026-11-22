<?php

namespace App\Member;

/** A backend (LDAP, database, mail) refused or failed; the message is safe to show and contains no secrets. */
final class BackendFailure extends \RuntimeException
{
}
