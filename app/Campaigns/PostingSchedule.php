<?php

namespace App\Campaigns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a campaign's drafts are due: its posts per day spread evenly over a daily window, in the
 * campaign timezone (Malaysia by default), whatever timezone the server runs in.
 */
class PostingSchedule
{
    public function timezone(): string
    {
        return (string) config('services.campaigns.timezone');
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function slots(int $perDay, CarbonInterface $day): array
    {
        if ($perDay < 1) {
            return [];
        }

        [$start, $end] = $this->window($day);
        $length = (int) $start->diffInSeconds($end);

        return array_map(
            fn (int $i): CarbonImmutable => $start->addSeconds(intdiv($length * $i, $perDay)),
            range(0, $perDay - 1),
        );
    }

    public function dueCount(int $perDay, CarbonInterface $now): int
    {
        return count(array_filter($this->slots($perDay, $now), fn (CarbonImmutable $slot): bool => $slot->lte($now)));
    }

    public function inWindow(CarbonInterface $now): bool
    {
        [$start, $end] = $this->window($now);

        return $now->betweenIncluded($start, $end);
    }

    /**
     * The next slot after the ones already used today, or tomorrow's first.
     */
    public function nextSlot(int $perDay, int $usedToday, CarbonInterface $now): ?CarbonImmutable
    {
        return $this->slots($perDay, $now)[$usedToday] ?? $this->slots($perDay, $this->local($now)->addDay())[0] ?? null;
    }

    public function startOfDay(CarbonInterface $now): CarbonImmutable
    {
        return $this->local($now)->startOfDay();
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function window(CarbonInterface $day): array
    {
        $local = $this->local($day);

        return [
            $local->setTimeFromTimeString((string) config('services.campaigns.window_start')),
            $local->setTimeFromTimeString((string) config('services.campaigns.window_end')),
        ];
    }

    private function local(CarbonInterface $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone($this->timezone());
    }
}
