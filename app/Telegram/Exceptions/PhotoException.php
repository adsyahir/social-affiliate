<?php

namespace App\Telegram\Exceptions;

use RuntimeException;

/**
 * A photo sent to the bot that cannot be used. The message is shown to the sender.
 */
class PhotoException extends RuntimeException {}
