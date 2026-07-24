<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Services;

use InvalidArgumentException;

class JalaliService
{
    /**
     * Convert a Jalali date string (YYYY/MM/DD) to the Iranian day-of-week integer.
     *
     * 0 = شنبه   (Saturday)
     * 1 = یکشنبه (Sunday)
     * 2 = دوشنبه (Monday)
     * 3 = سه‌شنبه (Tuesday)
     * 4 = چهارشنبه (Wednesday)
     * 5 = پنجشنبه (Thursday)
     * 6 = جمعه   (Friday)
     *
     * @param string $shamsiDate Format: YYYY/MM/DD  e.g. "1403/02/15"
     * @return int 0–6
     *
     * @throws InvalidArgumentException if format is invalid
     */
    public static function dayOfWeek(string $shamsiDate): int
    {
        if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $shamsiDate)) {
            throw new InvalidArgumentException(
                "Invalid Jalali date format. Expected YYYY/MM/DD, got: {$shamsiDate}"
            );
        }

        [$jy, $jm, $jd] = array_map('intval', explode('/', $shamsiDate));

        [$gy, $gm, $gd] = self::toGregorian($jy, $jm, $jd);

        // PHP date('w'): 0=Sunday, 1=Monday, ..., 6=Saturday
        $timestamp = mktime(12, 0, 0, $gm, $gd, $gy);
        $phpDow    = (int) date('w', $timestamp);

        // Convert PHP DOW to Iranian DOW:
        // PHP: 0=Sun,1=Mon,2=Tue,3=Wed,4=Thu,5=Fri,6=Sat
        // IR : 0=Sat,1=Sun,2=Mon,3=Tue,4=Wed,5=Thu,6=Fri
        // Formula: ($phpDow + 1) % 7
        return ($phpDow + 1) % 7;
    }

    /**
     * Convert Jalali (Solar Hijri) date to Gregorian date.
     *
     * @param int $jy  Jalali year  (e.g. 1403)
     * @param int $jm  Jalali month (1–12)
     * @param int $jd  Jalali day   (1–31)
     * @return array{int, int, int}  [$gy, $gm, $gd]
     */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy -= 979;
        $jm -= 1;
        $jd -= 1;

        $jDayNo = 365 * $jy + (int) ($jy / 33) * 8 + (int) (($jy % 33 + 3) / 4);

        for ($i = 0; $i < $jm; ++$i) {
            $jDayNo += ($i < 6) ? 31 : 30;
        }

        $jDayNo += $jd;

        $gDayNo = $jDayNo + 79;

        $gy = 1600 + 400 * (int) ($gDayNo / 146097);
        $gDayNo %= 146097;

        $leap = true;
        if ($gDayNo >= 36525) {
            $gDayNo--;
            $gy += 100 * (int) ($gDayNo / 36524);
            $gDayNo %= 36524;

            if ($gDayNo >= 365) {
                $gDayNo++;
            } else {
                $leap = false;
            }
        }

        $gy += 4 * (int) ($gDayNo / 1461);
        $gDayNo %= 1461;

        if ($gDayNo >= 366) {
            $leap = false;
            $gDayNo--;
            $gy += (int) ($gDayNo / 365);
            $gDayNo %= 365;
        }

        $gDaysInMonth = [31, ($leap ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        $gm = 0;
        for ($i = 0; $i < 12; ++$i) {
            if ($gDayNo < $gDaysInMonth[$i]) {
                $gm = $i + 1;
                $gd = $gDayNo + 1;
                break;
            }
            $gDayNo -= $gDaysInMonth[$i];
        }

        return [$gy, $gm, $gd];
    }
}
