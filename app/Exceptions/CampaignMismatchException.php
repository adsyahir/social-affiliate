<?php

namespace App\Exceptions;

use LogicException;

/**
 * Thrown when a post would send a campaign's content to an account or product outside that campaign.
 */
class CampaignMismatchException extends LogicException {}
