<?php

namespace App\Social\Exceptions;

/**
 * The token was rejected (expired, revoked or missing a permission). Retrying will not help;
 * the account needs a new token.
 */
class AccessTokenException extends SocialApiException {}
