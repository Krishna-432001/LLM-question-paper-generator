<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude\Books;

use App\Domain\Questions\Aptitude\AptitudeBook;
use Random\Randomizer;

/** Time conversion, railway time and journeys, and profit and loss, from the Std 6 Mathematics Term-II book (Chapters 2 and 3). */
final class Std6Term2 extends AptitudeBook
{
    private const TIME = 'time-work-and-distance';

    private const MONEY = 'simplification-and-percentage';

    private const RAILWAY = 'For example, 5 p.m is denoted as 17:00 hours.';

    private const PROFIT = 'If C.P. < S.P., there is Profit';

    private const LOSS = 'If C.P. > S.P., there is loss';

    public function source(): string
    {
        return 'Std 6 Mathematics Term-II (English)';
    }

    public function breadcrumb(): string
    {
        return 'Std 6 Mathematics Term-II (English) — Chapters 2 and 3: Measurements (Time), Bill, Profit and Loss';
    }

    public function file(): string
    {
        return 'aptitude-std6-term2.json';
    }

    protected function templates(): array
    {
        return [
            'hours-to-minutes' => fn (Randomizer $r): ?array => $this->toMinutes($r),
            'hours-to-seconds' => fn (Randomizer $r): ?array => $this->toSeconds($r),
            'to-railway-time' => fn (Randomizer $r): ?array => $this->toRailway($r),
            'from-railway-time' => fn (Randomizer $r): ?array => $this->fromRailway($r),
            'overnight-journey' => fn (Randomizer $r): ?array => $this->journey($r),
            'profit-or-loss' => fn (Randomizer $r): ?array => $this->profitOrLoss($r),
            'bulk-profit' => fn (Randomizer $r): ?array => $this->bulkProfit($r),
        ];
    }

    private function toMinutes(Randomizer $r): ?array
    {
        $h = $r->getInt(2, 15);
        $m = $r->getInt(1, 59);
        $total = $h * 60 + $m;

        return $this->draft(self::TIME, 'easy', 34, '= 3 x 60 minutes + 35 minutes',
            "How many minutes are there in {$h} hours {$m} minutes?",
            "{$h} மணி {$m} நிமிடங்களில் எத்தனை நிமிடங்கள் உள்ளன?",
            "1 hour = 60 minutes, so {$h} hours {$m} minutes = {$h} × 60 + {$m} = {$total} minutes.",
            "1 மணி = 60 நிமிடங்கள்; எனவே {$h} மணி {$m} நிமிடங்கள் = {$h} × 60 + {$m} = {$total} நிமிடங்கள்.",
            $total, [$h * 100 + $m, $h * 60, ($h + 1) * 60 + $m, $h * 60 - $m],
            $this->unit('minutes', 'minute'), $this->unit('நிமிடங்கள்', 'நிமிடம்'),
        );
    }

    private function toSeconds(Randomizer $r): ?array
    {
        $h = $r->getInt(2, 9);
        $m = $r->getInt(1, 59);
        $s = $r->getInt(1, 59);
        $total = $h * 3600 + $m * 60 + $s;

        return $this->draft(self::TIME, 'medium', 34, '= (7 x 60 x 60) seconds +(16 x 60) seconds +20 seconds',
            "Express {$h} hours {$m} minutes {$s} seconds in seconds.",
            "{$h} மணி {$m} நிமிடங்கள் {$s} விநாடிகளை விநாடிகளில் குறிப்பிடுக.",
            "({$h} × 60 × 60) + ({$m} × 60) + {$s} = ".($h * 3600).' + '.($m * 60)." + {$s} = {$total} seconds.",
            "({$h} × 60 × 60) + ({$m} × 60) + {$s} = ".($h * 3600).' + '.($m * 60)." + {$s} = {$total} விநாடிகள்.",
            $total, [$h * 3600 + $m * 100 + $s, $h * 60 + $m * 60 + $s, $h * 3600 + $m * 60, $total + 3600],
            $this->unit('seconds', 'second'), $this->unit('விநாடிகள்', 'விநாடி'),
        );
    }

    private function toRailway(Randomizer $r): ?array
    {
        $h = $r->getInt(1, 11);
        $m = 5 * $r->getInt(0, 11);
        $pm = $r->getInt(0, 1) === 1;
        $mm = sprintf('%02d', $m);
        $right = $pm ? $h + 12 : $h;
        $hours = [$right, $pm ? $h : $h + 12, $right + 2 > 23 ? $right - 2 : $right + 2, $right - 1 < 1 ? $right + 1 : $right - 1, $right + 1];
        $clock = fn (int $hh): string => sprintf('%02d', $hh).":{$mm}";
        $en = array_map(fn (int $hh): string => $clock($hh).' hours', $hours);
        $ta = array_map(fn (int $hh): string => $clock($hh).' மணி', $hours);
        $enTime = "{$h}.{$mm} ".($pm ? 'p.m.' : 'a.m.');
        $taTime = "{$h}.{$mm} ".($pm ? 'பி.ப. (p.m.)' : 'மு.ப. (a.m.)');

        return $this->textDraft(self::TIME, 'easy', 35, self::RAILWAY,
            "Write {$enTime} in the 24-hour (railway time) format.",
            "{$taTime} என்ற நேரத்தை 24 மணி நேர (இரயில்வே நேர) வடிவில் எழுதுக.",
            $pm ? "From 1 p.m. onwards, add 12 to the hours: {$h} + 12 = {$right}, so {$enTime} = {$en[0]}." : "Morning times up to 12 noon keep their hours, so {$enTime} = {$en[0]}.",
            $pm ? "பிற்பகல் 1 மணி முதல் உள்ள நேரத்துக்கு மணியுடன் 12 கூட்டுக: {$h} + 12 = {$right}; எனவே {$taTime} = {$ta[0]}." : "நண்பகல் 12 மணி வரை மணி மாறாது; எனவே {$taTime} = {$ta[0]}.",
            $en, $ta,
        );
    }

    private function fromRailway(Randomizer $r): ?array
    {
        $hh = $r->getInt(13, 23);
        $m = 5 * $r->getInt(0, 11);
        $mm = sprintf('%02d', $m);
        $h = $hh - 12;
        // Likely slips: taking 10 off instead of 12, or an hour either side; only real 12-hour times.
        $slips = array_filter([$hh - 10, $h + 1, $h - 1, $h + 2, $h - 2], fn (int $x): bool => $x >= 1 && $x <= 12 && $x !== $h);
        $options = [[$h, 'p'], [$h, 'a'], ...array_map(fn (int $x): array => [$x, 'p'], array_values($slips))];
        $en = array_map(fn (array $o): string => "{$o[0]}.{$mm} ".($o[1] === 'p' ? 'p.m.' : 'a.m.'), $options);
        $ta = array_map(fn (array $o): string => "{$o[0]}.{$mm} ".($o[1] === 'p' ? 'பி.ப.' : 'மு.ப.'), $options);

        return $this->textDraft(self::TIME, 'easy', 35, 'Subtract 12:00 hours from any hours from 13:00 hours.',
            "A train leaves at {$hh}:{$mm} hours. Write this time in the 12-hour format.",
            "ஒரு தொடர்வண்டி {$hh}:{$mm} மணிக்குப் புறப்படுகிறது. இந்த நேரத்தை 12 மணி நேர வடிவில் எழுதுக.",
            "For 13:00 hours and later, subtract 12 from the hours: {$hh} − 12 = {$h}, so the time is {$en[0]}",
            "13:00 மணியும் அதற்குப் பின்னும் உள்ள நேரத்துக்கு மணியிலிருந்து 12 கழிக்க: {$hh} − 12 = {$h}; எனவே நேரம் {$ta[0]}",
            $en, $ta,
        );
    }

    private function journey(Randomizer $r): ?array
    {
        $start = $r->getInt(18 * 12, 23 * 12 + 11) * 5;
        $end = $r->getInt(1 * 12, 9 * 12 + 11) * 5;
        $before = 24 * 60 - $start;
        $total = $before + $end;
        $clock = fn (int $mins): string => sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
        $spans = [$total, $total - 60, $total + 60, abs($start - $end) % (24 * 60), $total + 30];
        $en = array_map(fn (int $t): string => $this->span($t, 'hours', 'minutes'), $spans);
        $ta = array_map(fn (int $t): string => $this->span($t, 'மணி', 'நிமிடங்கள்'), $spans);

        return $this->textDraft(self::TIME, 'medium', 37, 'Total journey time = Duration up to midnight + Duration from midnight to arrival',
            "A train leaves Chennai at {$clock($start)} hours and reaches its destination at {$clock($end)} hours the next day. How long is the journey?",
            "ஒரு தொடர்வண்டி {$clock($start)} மணிக்குச் சென்னையிலிருந்து புறப்பட்டு, மறுநாள் {$clock($end)} மணிக்குச் சேருமிடத்தை அடைகிறது. பயண நேரம் எவ்வளவு?",
            'Duration up to midnight = '.$this->span($before, 'hours', 'minutes').'; from midnight to arrival = '.$this->span($end, 'hours', 'minutes')."; total = {$en[0]}.",
            'நள்ளிரவு வரை = '.$this->span($before, 'மணி', 'நிமிடங்கள்').'; நள்ளிரவு முதல் சேரும் வரை = '.$this->span($end, 'மணி', 'நிமிடங்கள்')."; மொத்தம் = {$ta[0]}.",
            $en, $ta,
        );
    }

    private function span(int $minutes, string $hours, string $mins): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m === 0 ? "{$h} {$hours}" : "{$h} {$hours} {$m} {$mins}";
    }

    private function profitOrLoss(Randomizer $r): ?array
    {
        $cp = $r->getInt(4, 400) * 5;
        $diff = $r->getInt(1, 60) * 5;
        $profit = $r->getInt(0, 1) === 1;
        $sp = $profit ? $cp + $diff : $cp - $diff;

        if ($sp <= 0 || $diff >= $cp) {
            return null;
        }

        $item = $this->pick($r, [['bicycle', 'மிதிவண்டி'], ['wrist watch', 'கைக்கடிகாரம்'], ['table fan', 'மேசை மின்விசிறி'], ['school bag', 'பள்ளிப் பை'], ['mobile phone cover', 'கைப்பேசி உறை'], ['wall clock', 'சுவர்க் கடிகாரம்'], ['pressure cooker', 'அழுத்தச் சமையற்கலன்']]);
        $en = [($profit ? 'Profit' : 'Loss')." ₹{$diff}", ($profit ? 'Loss' : 'Profit')." ₹{$diff}", ($profit ? 'Profit' : 'Loss').' ₹'.($cp + $sp), ($profit ? 'Profit' : 'Loss').' ₹'.($diff + 10)];
        $ta = [($profit ? 'இலாபம்' : 'நட்டம்')." ₹{$diff}", ($profit ? 'நட்டம்' : 'இலாபம்')." ₹{$diff}", ($profit ? 'இலாபம்' : 'நட்டம்').' ₹'.($cp + $sp), ($profit ? 'இலாபம்' : 'நட்டம்').' ₹'.($diff + 10)];

        return $this->textDraft(self::MONEY, 'easy', 49, $profit ? self::PROFIT : self::LOSS,
            "A shopkeeper buys a {$item[0]} for ₹{$cp} and sells it for ₹{$sp}. Find the profit or loss.",
            "ஒரு கடைக்காரர் {$item[1]} ஒன்றை ₹{$cp} க்கு வாங்கி ₹{$sp} க்கு விற்கிறார். இலாபம் அல்லது நட்டம் காண்க.",
            $profit ? "S.P. > C.P., so there is a profit: {$sp} − {$cp} = ₹{$diff}." : "C.P. > S.P., so there is a loss: {$cp} − {$sp} = ₹{$diff}.",
            $profit ? "விற்பனை விலை > அடக்க விலை; எனவே இலாபம் = {$sp} − {$cp} = ₹{$diff}." : "அடக்க விலை > விற்பனை விலை; எனவே நட்டம் = {$cp} − {$sp} = ₹{$diff}.",
            $en, $ta,
        );
    }

    private function bulkProfit(Randomizer $r): ?array
    {
        $count = 10 * $r->getInt(2, 20);
        $buy = $r->getInt(2, 40);
        $sell = $buy + $r->getInt(1, 10);
        $cost = $count * $buy;
        $profit = $count * ($sell - $buy);
        $fruit = $this->pick($r, [['mangoes', 'மாம்பழங்களை'], ['oranges', 'ஆரஞ்சுப் பழங்களை'], ['coconuts', 'தேங்காய்களை'], ['guavas', 'கொய்யாப் பழங்களை'], ['pens', 'பேனாக்களை']]);

        return $this->draft(self::MONEY, 'medium', 51, 'Selling Price > Cost Price',
            "A vendor buys {$count} {$fruit[0]} for ₹{$cost} and sells them at ₹{$sell} each. Find the profit.",
            "ஒரு வியாபாரி {$count} {$fruit[1]} ₹{$cost} க்கு வாங்கி, ஒன்று ₹{$sell} வீதம் விற்கிறார். இலாபம் காண்க.",
            "Selling price = {$count} × {$sell} = ₹".($count * $sell).", so profit = ".($count * $sell)." − {$cost} = ₹{$profit}.",
            "விற்பனை விலை = {$count} × {$sell} = ₹".($count * $sell)."; இலாபம் = ".($count * $sell)." − {$cost} = ₹{$profit}.",
            $profit, [$count * $sell, $sell - $buy, $profit + $count, $profit - $count],
            $this->rupees(), $this->rupees(),
        );
    }
}
