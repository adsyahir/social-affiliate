<?php

namespace App\Http\Controllers;

use App\Telegram\UpdateHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController
{
    public function __invoke(Request $request, UpdateHandler $handler): Response
    {
        $secret = (string) config('services.telegram.webhook_secret');

        // Telegram echoes the secret given to setWebhook; anything else is not Telegram.
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        try {
            $handler->handle($request->json()->all());
        } catch (Throwable $e) {
            // Answer OK anyway: an error response makes Telegram re-send the same update over and over.
            Log::error('Telegram update could not be handled', ['error' => $e->getMessage()]);
            report($e);
        }

        return response()->noContent(200);
    }
}
