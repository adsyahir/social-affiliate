<?php

namespace App\Social\Contracts;

use App\Social\Data\AccessToken;
use App\Social\Exceptions\MissingTokenException;
use App\Social\Exceptions\SocialApiException;

/**
 * For platforms whose tokens expire but can be renewed before they do.
 */
interface RefreshesAccessToken
{
    /**
     * Exchange the current token for a fresh one. The caller stores it.
     *
     * @throws MissingTokenException
     * @throws SocialApiException
     */
    public function refreshAccessToken(): AccessToken;
}
