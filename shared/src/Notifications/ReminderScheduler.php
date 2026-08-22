<?php

namespace ElektronNet\Payments\Core\Notifications;

/**
 * Pure date math for the reminder notifications described in the README
 * ("Countdown display"): a few days before T1 to the buyer, a few days
 * before T2 to the seller. The platform adapter is responsible for actually
 * sending the notification (email, in-portal message, ...) and for calling
 * this once per its own cron/scheduled-task cycle; this class only decides
 * whether "now" falls inside a reminder window.
 */
final class ReminderScheduler
{
    /** @var int[] days-before-threshold offsets to remind at */
    private $reminderOffsetsDays;

    /**
     * @param int[] $reminderOffsetsDays e.g. [5, 1] = remind 5 days and 1 day before
     */
    public function __construct(array $reminderOffsetsDays = [5, 1])
    {
        $this->reminderOffsetsDays = $reminderOffsetsDays;
    }

    /**
     * True if $nowUnixTime falls within the same day as one of the
     * configured offsets before $thresholdUnixTime.
     */
    public function isReminderDue(int $thresholdUnixTime, int $nowUnixTime): bool
    {
        foreach ($this->reminderOffsetsDays as $offsetDays) {
            $reminderDay = $thresholdUnixTime - $offsetDays * 86400;
            if ($nowUnixTime >= $reminderDay && $nowUnixTime < $reminderDay + 86400) {
                return true;
            }
        }

        return false;
    }
}
