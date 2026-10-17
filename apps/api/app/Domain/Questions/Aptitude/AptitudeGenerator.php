<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude;

use Closure;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Number-varied aptitude questions in English and Tamil. Each one is built on
 * a method the Std 8 Mathematics book teaches (Chapter 4, Life Mathematics)
 * and cites the page that teaches it: the numbers change, the method and the
 * citation do not. Every answer is computed, so it is right by construction.
 *
 * Generation is deterministic. The same seed gives the same questions, and a
 * larger count gives the same questions plus more, so the file can be made
 * bigger later and loaded again without duplicates.
 */
final class AptitudeGenerator
{
    public const SOURCE = 'Std 8 Mathematics Term-Full (English)';

    public const BREADCRUMB = 'Std 8 Mathematics (English) — Chapter 4: Life Mathematics';

    private const PERCENT = 'simplification-and-percentage';

    private const MONEY = 'ratio-proportion-and-averages';

    private const WORK = 'time-work-and-distance';

    /** How many tries a method gets per question before it is out of new numbers. */
    private const ATTEMPTS_PER_QUESTION = 60;

    /**
     * @return list<array<string, mixed>> questions in the questions:import format
     */
    public function generate(int $perTemplate, int $seed): array
    {
        $questions = [];

        foreach ($this->templates() as $name => $template) {
            // Each method draws from its own stream, so adding a method later
            // does not change the questions the others already made.
            $random = new Randomizer(new Mt19937($seed ^ crc32($name)));
            $seen = [];

            for ($tries = 0; count($seen) < $perTemplate && $tries < $perTemplate * self::ATTEMPTS_PER_QUESTION; $tries++) {
                $draft = $template($random);

                if ($draft === null || isset($seen[$draft['en_q']])) {
                    continue;
                }

                $question = $this->finish($draft);

                if ($question === null) {
                    continue;
                }

                $seen[$draft['en_q']] = true;
                $questions[] = $question;
            }
        }

        return $questions;
    }

    /**
     * The methods, each a closure from a random source to a draft question
     * (or null when the numbers drawn do not give a clean answer).
     *
     * @return array<string, Closure(Randomizer): ?array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            'percent-of' => fn (Randomizer $r): ?array => $this->percentOf($r),
            'percent-base' => fn (Randomizer $r): ?array => $this->percentBase($r),
            'percent-decrease-original' => fn (Randomizer $r): ?array => $this->percentDecreaseOriginal($r),
            'percent-increase-original' => fn (Randomizer $r): ?array => $this->percentIncreaseOriginal($r),
            'successive-change' => fn (Randomizer $r): ?array => $this->successiveChange($r),
            'profit-loss-percent' => fn (Randomizer $r): ?array => $this->profitLossPercent($r),
            'selling-price-for-gain' => fn (Randomizer $r): ?array => $this->sellingPriceForGain($r),
            'cost-price-from-loss' => fn (Randomizer $r): ?array => $this->costPriceFromLoss($r),
            'discount-percent' => fn (Randomizer $r): ?array => $this->discountPercent($r),
            'successive-discounts' => fn (Randomizer $r): ?array => $this->successiveDiscounts($r),
            'successive-discount-price' => fn (Randomizer $r): ?array => $this->successiveDiscountPrice($r),
            'simple-interest' => fn (Randomizer $r): ?array => $this->simpleInterest($r),
            'simple-interest-years' => fn (Randomizer $r): ?array => $this->simpleInterestYears($r),
            'compound-interest' => fn (Randomizer $r): ?array => $this->compoundInterest($r),
            'work-together' => fn (Randomizer $r): ?array => $this->workTogether($r),
            'work-find-other' => fn (Randomizer $r): ?array => $this->workFindOther($r),
            'men-days-hours' => fn (Randomizer $r): ?array => $this->menDaysHours($r),
            'wages-share' => fn (Randomizer $r): ?array => $this->wagesShare($r),
        ];
    }

    // ── Percentage ──────────────────────────────────────────────────────

    private function percentOf(Randomizer $r): ?array
    {
        $x = $this->pick($r, [5, 10, 12, 15, 20, 25, 30, 35, 40, 45, 60, 75, 80, 120, 150]);
        $n = $r->getInt(2, 80) * 20;
        $answer = $x * $n / 100;

        return $this->draft(self::PERCENT, 'easy', 122,
            'We know that Per Cent means per hundred or out of a hundred. It is denoted by the symbol %. x% denotes the fraction x/100.',
            "What is {$x}% of {$n}?",
            "{$n} இல் {$x}% என்பது எவ்வளவு?",
            "{$x}% of {$n} = {$x}/100 × {$n} = ".$this->n($answer).'.',
            "{$n} இல் {$x}% = {$x}/100 × {$n} = ".$this->n($answer).'.',
            $answer, [$x * $n / 10, $answer + $x, $n - $answer, $answer * 2],
            $this->plain(), $this->plain(),
        );
    }

    private function percentBase(Randomizer $r): ?array
    {
        $x = $this->pick($r, [8, 12, 15, 16, 20, 24, 25, 30, 32, 40, 45, 48, 60, 64, 75, 80, 90]);
        $number = $r->getInt(2, 60) * 25;
        $part = $x * $number / 100;

        if (! $this->whole($part)) {
            return null;
        }

        return $this->draft(self::PERCENT, 'medium', 123,
            'Akila scored 80% of marks in an examination. If her score was 576 marks, then find the maximum marks of the examination.',
            "If {$x}% of a number is ".$this->n($part).', what is the number?',
            "ஓர் எண்ணின் {$x}% என்பது ".$this->n($part).' எனில், அந்த எண் எது?',
            "{$x}/100 × number = ".$this->n($part).", so the number = ".$this->n($part)." × 100/{$x} = {$number}.",
            "{$x}/100 × எண் = ".$this->n($part).'; எனவே எண் = '.$this->n($part)." × 100/{$x} = {$number}.",
            $number, [$part * $x / 100, $part * (100 + $x) / 100, $number + $part, $number * 2],
            $this->plain(), $this->plain(),
        );
    }

    private function percentDecreaseOriginal(Randomizer $r): ?array
    {
        $x = $this->pick($r, [5, 10, 15, 20, 25, 30, 35, 40, 50, 60, 75]);
        $original = $r->getInt(2, 60) * 20;
        $after = $original * (100 - $x) / 100;

        if (! $this->whole($after)) {
            return null;
        }

        return $this->draft(self::PERCENT, 'medium', 123,
            'If we start with a quantity A and then decrease that quantity by x%, we will get the decreased quantity as, D = (1 − x/100) A',
            "When a number is decreased by {$x}%, it becomes ".$this->n($after).'. Find the number.',
            "ஓர் எண் {$x}% குறைக்கப்படும்போது ".$this->n($after).' ஆகிறது எனில், அந்த எண் எது?',
            'D = (1 − x/100) A, so '.$this->n($after)." = ".(100 - $x)."/100 × A and A = ".$this->n($after).' × 100/'.(100 - $x)." = {$original}.",
            'D = (1 − x/100) A; எனவே '.$this->n($after).' = '.(100 - $x).'/100 × A, A = '.$this->n($after).' × 100/'.(100 - $x)." = {$original}.",
            $original, [$after * (100 + $x) / 100, $after * (100 - $x) / 100, $after + $x, $original + $x],
            $this->plain(), $this->plain(),
        );
    }

    private function percentIncreaseOriginal(Randomizer $r): ?array
    {
        $x = $this->pick($r, [5, 10, 15, 20, 25, 30, 40, 50, 60, 75]);
        $original = $r->getInt(4, 120) * 10;
        $after = $original * (100 + $x) / 100;

        if (! $this->whole($after)) {
            return null;
        }

        return $this->draft(self::PERCENT, 'medium', 123,
            'If we start with a quantity A and then increase that quantity by x%, we will get the increased quantity as, I = (1 + x/100) A',
            "After a {$x}% increase, the price of an article is ₹".$this->n($after).'. What was its original price?',
            "{$x}% விலை உயர்வுக்குப் பின் ஒரு பொருளின் விலை ₹".$this->n($after).' எனில், அதன் முந்தைய விலை என்ன?',
            'I = (1 + x/100) A, so A = '.$this->n($after).' × 100/'.(100 + $x)." = ₹{$original}.",
            'I = (1 + x/100) A; எனவே A = '.$this->n($after).' × 100/'.(100 + $x)." = ₹{$original}.",
            $original, [$after * (100 - $x) / 100, $after - $x, $after * (100 + $x) / 100, $original - $x],
            $this->rupees(), $this->rupees(),
        );
    }

    private function successiveChange(Randomizer $r): ?array
    {
        $up = $this->pick($r, [10, 20, 25, 30, 40, 50]);
        $down = $this->pick($r, [10, 20, 25, 30, 40, 50]);
        $net = $up - $down - $up * $down / 100;

        return $this->draft(self::PERCENT, 'hard', 124,
            'For any given number, when it is increased first by x % and then decreased by y %, then the value of the number is increased or decreased by (x + y + xy/100)%. Use ‘negative’ sign for decrease',
            "A number is increased by {$up}% and then decreased by {$down}%. What is the net percentage change?",
            "ஓர் எண் {$up}% அதிகரிக்கப்பட்டுப் பின்னர் {$down}% குறைக்கப்படுகிறது. நிகர மாற்றம் எத்தனை சதவீதம்?",
            "Net change = x + y + xy/100 with y = −{$down}: {$up} − {$down} − ({$up} × {$down})/100 = ".$this->n($net).'%. A negative value is a decrease.',
            "நிகர மாற்றம் = x + y + xy/100 (y = −{$down}): {$up} − {$down} − ({$up} × {$down})/100 = ".$this->n($net).'%. குறை எண் குறைவைக் குறிக்கும்.',
            $net, [$up - $down, -$net, $up - $down + $up * $down / 100, -($up * $down / 100)],
            $this->change('increase', 'decrease', 'No change'), $this->change('அதிகரிப்பு', 'குறைவு', 'மாற்றம் இல்லை'),
            signed: true,
        );
    }

    // ── Profit, loss, discount ──────────────────────────────────────────

    private function profitLossPercent(Randomizer $r): ?array
    {
        $cost = $r->getInt(4, 200) * 50;
        $pct = $this->pick($r, [4, 5, 6, 8, 10, 12, 15, 16, 20, 25, 30, 40]);
        $gain = $r->getInt(0, 1) === 1;
        $sell = $cost * (100 + ($gain ? $pct : -$pct)) / 100;

        if (! $this->whole($sell)) {
            return null;
        }

        $signed = $gain ? $pct : -$pct;
        $onSelling = round(abs($sell - $cost) / $sell * 100, 2);

        return $this->draft(self::MONEY, 'easy', 126,
            'Profit or Gain % = (Profit / C.P × 100)% (ii) Loss % = (Loss / C.P × 100)%',
            "An article bought for ₹{$cost} is sold for ₹".$this->n($sell).'. Find the gain or loss percentage.',
            "₹{$cost}க்கு வாங்கிய ஒரு பொருள் ₹".$this->n($sell).'க்கு விற்கப்படுகிறது எனில், இலாப அல்லது நட்ட சதவீதம் என்ன?',
            ($gain ? 'Gain' : 'Loss').' = ₹'.$this->n(abs($sell - $cost))." and it is always calculated on the cost price: ".$this->n(abs($sell - $cost))."/{$cost} × 100 = {$pct}% ".($gain ? 'gain' : 'loss').'.',
            ($gain ? 'இலாபம்' : 'நட்டம்').' = ₹'.$this->n(abs($sell - $cost)).'; இது அடக்க விலையின் மீதே கணக்கிடப்படும்: '.$this->n(abs($sell - $cost))."/{$cost} × 100 = {$pct}% ".($gain ? 'இலாபம்' : 'நட்டம்').'.',
            $signed, [-$signed, $gain ? $onSelling : -$onSelling, $signed + ($gain ? 5 : -5), $signed + ($gain ? -2 : 2)],
            $this->change('gain', 'loss', 'No gain or loss'), $this->change('இலாபம்', 'நட்டம்', 'இலாபமும் நட்டமும் இல்லை'),
            signed: true,
        );
    }

    private function sellingPriceForGain(Randomizer $r): ?array
    {
        $cost = $r->getInt(4, 200) * 50;
        $pct = $this->pick($r, [4, 5, 6, 8, 10, 12, 15, 16, 20, 25, 30, 40]);
        $sell = $cost * (100 + $pct) / 100;

        if (! $this->whole($sell)) {
            return null;
        }

        return $this->draft(self::MONEY, 'easy', 126,
            '(iii) Selling Price, S.P = ((100 + Profit%)/100) × C.P',
            "An article costs ₹{$cost}. At what price should it be sold to gain {$pct}%?",
            "₹{$cost} அடக்க விலையுள்ள ஒரு பொருளை {$pct}% இலாபத்தில் விற்க, அதன் விற்பனை விலை என்ன?",
            'S.P = (100 + '.$pct.')/100 × '.$cost.' = ₹'.$this->n($sell).'.',
            'விற்பனை விலை = (100 + '.$pct.')/100 × '.$cost.' = ₹'.$this->n($sell).'.',
            $sell, [$cost * (100 - $pct) / 100, $cost + $pct, $cost * 100 / (100 - $pct), $sell + $pct],
            $this->rupees(), $this->rupees(),
        );
    }

    private function costPriceFromLoss(Randomizer $r): ?array
    {
        $pct = $this->pick($r, [4, 5, 8, 10, 12, 15, 20, 25]);
        $cost = $r->getInt(4, 200) * 50;
        $sell = $cost * (100 - $pct) / 100;

        if (! $this->whole($sell)) {
            return null;
        }

        return $this->draft(self::MONEY, 'medium', 128,
            'By selling a bicycle for ₹4275, a shopkeeper loses 5%. ... ∴ C.P = (100/(100 − Loss%)) × S.P = 100/95 × 4275 = ₹4500',
            'By selling an article for ₹'.$this->n($sell).", a shopkeeper loses {$pct}%. What is its cost price?",
            'ஒரு பொருளை ₹'.$this->n($sell)."க்கு விற்பதால் ஒரு கடைக்காரருக்கு {$pct}% நட்டம் ஏற்படுகிறது எனில், அதன் அடக்க விலை என்ன?",
            'C.P = 100/(100 − '.$pct.') × S.P = 100/'.(100 - $pct).' × '.$this->n($sell)." = ₹{$cost}.",
            'அடக்க விலை = 100/(100 − '.$pct.') × விற்பனை விலை = 100/'.(100 - $pct).' × '.$this->n($sell)." = ₹{$cost}.",
            $cost, [$sell * (100 + $pct) / 100, $sell * (100 - $pct) / 100, $sell + $pct, $cost + $sell * $pct / 100],
            $this->rupees(), $this->rupees(),
        );
    }

    private function discountPercent(Randomizer $r): ?array
    {
        $marked = $r->getInt(4, 200) * 50;
        $pct = $this->pick($r, [4, 5, 8, 10, 12, 15, 16, 20, 25, 30, 40]);
        $discount = $marked * $pct / 100;

        if (! $this->whole($discount)) {
            return null;
        }

        $sell = $marked - $discount;

        return $this->draft(self::MONEY, 'easy', 127,
            'Discount % = Discount / marked price × 100%',
            "An article marked at ₹{$marked} is sold for ₹".$this->n($sell).'. What is the rate of discount?',
            "₹{$marked} எனக் குறிக்கப்பட்ட ஒரு பொருள் ₹".$this->n($sell).'க்கு விற்கப்படுகிறது எனில், தள்ளுபடி சதவீதம் என்ன?',
            'Discount = '.$marked.' − '.$this->n($sell).' = ₹'.$this->n($discount).', and Discount % = '.$this->n($discount)."/{$marked} × 100 = {$pct}%.",
            'தள்ளுபடி = '.$marked.' − '.$this->n($sell).' = ₹'.$this->n($discount).'; தள்ளுபடி % = '.$this->n($discount)."/{$marked} × 100 = {$pct}%.",
            $pct, [round($discount / $sell * 100, 2), $pct + 5, 100 - $pct, $pct * 2],
            $this->percent(), $this->percent(),
        );
    }

    private function successiveDiscounts(Randomizer $r): ?array
    {
        $a = $this->pick($r, [10, 15, 20, 25, 30, 40, 50]);
        $b = $this->pick($r, [10, 15, 20, 25, 30, 40, 50]);
        $single = $a + $b - $a * $b / 100;

        return $this->draft(self::MONEY, 'hard', 130,
            'If there are 2 successive discounts of a % and b % respectively, then S.P = (1 − a/100)(1 − b/100) × M.P.',
            "What single discount is equivalent to two successive discounts of {$a}% and {$b}%?",
            "அடுத்தடுத்து வழங்கப்படும் {$a}%, {$b}% ஆகிய இரு தள்ளுபடிகளுக்குச் சமமான ஒற்றைத் தள்ளுபடி எத்தனை சதவீதம்?",
            'On ₹100, the price after both = (1 − '.$a.'/100)(1 − '.$b.'/100) × 100 = ₹'.$this->n(100 - $single).', so the single discount = '.$this->n($single).'%.',
            '₹100க்கு, இரு தள்ளுபடிகளுக்குப் பின் விலை = (1 − '.$a.'/100)(1 − '.$b.'/100) × 100 = ₹'.$this->n(100 - $single).'; எனவே ஒற்றைத் தள்ளுபடி = '.$this->n($single).'%.',
            $single, [$a + $b, $a + $b + $a * $b / 100, abs($a - $b), 100 - $single],
            $this->percent(), $this->percent(),
        );
    }

    private function successiveDiscountPrice(Randomizer $r): ?array
    {
        $a = $this->pick($r, [5, 10, 15, 20, 25, 30]);
        $b = $this->pick($r, [5, 10, 15, 20, 25]);
        $marked = $r->getInt(4, 160) * 100;
        $paid = $marked * (100 - $a) * (100 - $b) / 10000;

        if (! $this->whole($paid)) {
            return null;
        }

        return $this->draft(self::MONEY, 'medium', 130,
            'If there are 2 successive discounts of a % and b % respectively, then S.P = (1 − a/100)(1 − b/100) × M.P.',
            "An article is marked at ₹{$marked} and is offered at two successive discounts of {$a}% and {$b}%. How much does the customer pay?",
            "₹{$marked} எனக் குறிக்கப்பட்ட ஒரு பொருளுக்கு {$a}%, {$b}% என அடுத்தடுத்து இரு தள்ளுபடிகள் வழங்கப்படுகின்றன. வாடிக்கையாளர் செலுத்த வேண்டிய தொகை என்ன?",
            'S.P = (1 − '.$a.'/100)(1 − '.$b.'/100) × '.$marked.' = ₹'.$this->n($paid).'.',
            'விற்பனை விலை = (1 − '.$a.'/100)(1 − '.$b.'/100) × '.$marked.' = ₹'.$this->n($paid).'.',
            $paid, [$marked * (100 - $a - $b) / 100, $marked - $paid, $marked * (100 - $a) / 100, $paid + $marked * $a / 100],
            $this->rupees(), $this->rupees(),
        );
    }

    // ── Interest ────────────────────────────────────────────────────────

    private function simpleInterest(Randomizer $r): ?array
    {
        $p = $r->getInt(2, 100) * 500;
        $n = $r->getInt(1, 6);
        $rate = $this->pick($r, [4, 5, 6, 7, 8, 9, 10, 12, 15]);
        $i = $p * $n * $rate / 100;

        if (! $this->whole($i)) {
            return null;
        }

        $compound = round($p * ((1 + $rate / 100) ** $n) - $p);

        return $this->draft(self::MONEY, 'easy', 132,
            'When money is borrowed or deposited on simple interest (I = PNR/100), then the interest is calculated evenly on the principal throughout the loan or deposit period.',
            "Find the simple interest on ₹{$p} for {$n} ".($n === 1 ? 'year' : 'years')." at {$rate}% per annum.",
            "₹{$p}க்கு ஆண்டுக்கு {$rate}% வட்டி வீதத்தில் {$n} ஆண்டுகளுக்கான தனிவட்டி எவ்வளவு?",
            "I = PNR/100 = {$p} × {$n} × {$rate}/100 = ₹".$this->n($i).'.',
            "தனிவட்டி I = PNR/100 = {$p} × {$n} × {$rate}/100 = ₹".$this->n($i).'.',
            $i, [$p + $i, $p * $rate / 100, $compound, $i * 2],
            $this->rupees(), $this->rupees(),
        );
    }

    private function simpleInterestYears(Randomizer $r): ?array
    {
        $p = $r->getInt(2, 60) * 500;
        $rate = $this->pick($r, [4, 5, 6, 8, 10, 12, 15, 20]);
        $n = $r->getInt(2, 10);
        $amount = $p + $p * $n * $rate / 100;

        if (! $this->whole($amount)) {
            return null;
        }

        return $this->draft(self::MONEY, 'medium', 132,
            'Try these ... 3. In how many years will ₹2000 become ₹3600 at 10% p.a simple interest?',
            "In how many years will ₹{$p} become ₹".$this->n($amount)." at {$rate}% per annum simple interest?",
            "ஆண்டுக்கு {$rate}% தனிவட்டி வீதத்தில் ₹{$p} எத்தனை ஆண்டுகளில் ₹".$this->n($amount).' ஆகும்?',
            'Interest = '.$this->n($amount)." − {$p} = ₹".$this->n($amount - $p).", and N = I × 100/(P × R) = ".$this->n($amount - $p)." × 100/({$p} × {$rate}) = {$n} years.",
            'வட்டி = '.$this->n($amount)." − {$p} = ₹".$this->n($amount - $p).'; N = I × 100/(P × R) = '.$this->n($amount - $p)." × 100/({$p} × {$rate}) = {$n} ஆண்டுகள்.",
            $n, [$n + 1, $n - 1, $amount * 100 / ($p * $rate), $n * 2],
            $this->unit('years', 'year'), $this->unit('ஆண்டுகள்', 'ஆண்டு'),
        );
    }

    private function compoundInterest(Randomizer $r): ?array
    {
        // Rate, years and a principal step that keeps the amount a whole number.
        [$rate, $years, $step] = $this->pick($r, [[10, 2, 100], [10, 3, 1000], [5, 2, 400], [20, 2, 100], [20, 3, 125], [4, 2, 625], [8, 2, 625], [15, 2, 400]]);
        $p = $r->getInt(2, 40) * $step;
        $amount = $p * ((100 + $rate) ** $years) / (100 ** $years);

        if (! $this->whole($amount) || $p < 1000) {
            return null;
        }

        $ci = $amount - $p;
        $si = $p * $rate * $years / 100;

        return $this->draft(self::MONEY, 'medium', 134,
            'When the interest is compounded annually, we have A = P(1 + r/100)^n where A is the amount, P is the principal, r is the rate of interest per annum and n is the number of years and we shall get the compound interest as C.I = Amount – Principal.',
            "Find the compound interest on ₹{$p} for {$years} years at {$rate}% per annum, compounded annually.",
            "₹{$p}க்கு ஆண்டுக்கு {$rate}% வட்டி வீதத்தில், ஆண்டுதோறும் கூட்டப்படும் {$years} ஆண்டுகளுக்கான கூட்டுவட்டி எவ்வளவு?",
            "A = P(1 + r/100)^n = {$p} × (1 + {$rate}/100)^{$years} = ₹".$this->n($amount).', so C.I = '.$this->n($amount)." − {$p} = ₹".$this->n($ci).'.',
            "தொகை A = P(1 + r/100)^n = {$p} × (1 + {$rate}/100)^{$years} = ₹".$this->n($amount).'; கூட்டுவட்டி = '.$this->n($amount)." − {$p} = ₹".$this->n($ci).'.',
            $ci, [$si, $amount, $ci + ($ci - $si), $ci - $p * $rate / 100],
            $this->rupees(), $this->rupees(),
        );
    }

    // ── Time and work ───────────────────────────────────────────────────

    private function workTogether(Randomizer $r): ?array
    {
        [$a, $b, $t] = $this->workTriple($r) ?? [0, 0, 0];

        if ($t === 0) {
            return null;
        }

        return $this->draft(self::WORK, 'easy', 148,
            'If two persons X and Y can complete some work individually in a and b days, then their one day’s work will be 1/a and 1/b respectively. ... X and Y together can complete the work in ab/(a + b) days.',
            "A can complete a work in {$a} days and B in {$b} days. In how many days can they complete it working together?",
            "ஒரு வேலையை A தனியாக {$a} நாட்களிலும், B தனியாக {$b} நாட்களிலும் முடிப்பர். இருவரும் சேர்ந்து அவ்வேலையை எத்தனை நாட்களில் முடிப்பர்?",
            "Together = ab/(a + b) = ({$a} × {$b})/({$a} + {$b}) = {$t} days.",
            "இருவரும் சேர்ந்து = ab/(a + b) = ({$a} × {$b})/({$a} + {$b}) = {$t} நாட்கள்.",
            $t, [($a + $b) / 2, $a + $b, abs($b - $a), $t + 2],
            $this->unit('days', 'day'), $this->unit('நாட்கள்', 'நாள்'),
        );
    }

    private function workFindOther(Randomizer $r): ?array
    {
        [$a, $b, $t] = $this->workTriple($r) ?? [0, 0, 0];

        if ($t === 0) {
            return null;
        }

        return $this->draft(self::WORK, 'medium', 148,
            'A and B together can do a piece of work in 16 days and A alone can do it in 48 days. How long will B take to complete the work? ... ∴ B alone can complete the work in 24 days.',
            "A and B together can do a piece of work in {$t} days, and A alone can do it in {$a} days. How long will B alone take?",
            "A, B இருவரும் சேர்ந்து ஒரு வேலையை {$t} நாட்களில் முடிப்பர்; A தனியாக அதை {$a} நாட்களில் முடிப்பார் எனில், B தனியாக எத்தனை நாட்களில் முடிப்பார்?",
            "B’s 1 day’s work = 1/{$t} − 1/{$a} = 1/{$b}, so B alone takes {$b} days.",
            "B இன் 1 நாள் வேலை = 1/{$t} − 1/{$a} = 1/{$b}; எனவே B தனியாக {$b} நாட்களில் முடிப்பார்.",
            $b, [$a - $t, $a + $t, $a * $t / ($a + $t), $b + $t],
            $this->unit('days', 'day'), $this->unit('நாட்கள்', 'நாள்'),
        );
    }

    private function menDaysHours(Randomizer $r): ?array
    {
        $p1 = $r->getInt(2, 30) * 4;
        $h1 = $r->getInt(4, 10);
        $d1 = $r->getInt(4, 40);
        $p2 = $r->getInt(2, 30) * 4;
        $h2 = $r->getInt(4, 10);
        $d2 = $p1 * $d1 * $h1 / ($p2 * $h2);

        if (! $this->whole($d2) || $p1 === $p2 || $d2 < 2 || $d2 > 150) {
            return null;
        }

        return $this->draft(self::WORK, 'hard', 142,
            'Identify the data from the given statement as Persons (P), Days (D), Hours (H) and Work (W) and use the formula, P1 × D1 × H1 / W1 = P2 × D2 × H2 / W2',
            "If {$p1} men working {$h1} hours a day can do a work in {$d1} days, in how many days will {$p2} men working {$h2} hours a day complete the same work?",
            "{$p1} பேர் நாளொன்றுக்கு {$h1} மணி நேரம் வேலை செய்து ஒரு வேலையை {$d1} நாட்களில் முடிப்பர் எனில், {$p2} பேர் நாளொன்றுக்கு {$h2} மணி நேரம் வேலை செய்து அதே வேலையை எத்தனை நாட்களில் முடிப்பர்?",
            "P1 × D1 × H1 = P2 × D2 × H2, so D2 = ({$p1} × {$d1} × {$h1})/({$p2} × {$h2}) = ".$this->n($d2).' days.',
            "P1 × D1 × H1 = P2 × D2 × H2; எனவே D2 = ({$p1} × {$d1} × {$h1})/({$p2} × {$h2}) = ".$this->n($d2).' நாட்கள்.',
            $d2, [$d1 * $p2 * $h2 / ($p1 * $h1), $d1 * $p1 / $p2, $d1 * $h1 / $h2, $d2 + $d1],
            $this->unit('days', 'day'), $this->unit('நாட்கள்', 'நாள்'),
        );
    }

    private function wagesShare(Randomizer $r): ?array
    {
        $x = $r->getInt(3, 30);
        $y = $r->getInt(3, 30);

        if ($x === $y) {
            return null;
        }

        $total = ($x + $y) * $r->getInt(2, 60) * 50;
        $share = $total * $y / ($x + $y);

        return $this->draft(self::WORK, 'medium', 149,
            'If the ratio of the time taken by A and B in doing a work is x : y, then the ratio of work done by A and B is 1/x : 1/y = y : x. This is the ratio for their separate wages too.',
            "A can do a work in {$x} days and B in {$y} days. They do it together and are paid ₹{$total}. What is A’s share?",
            "ஒரு வேலையை A {$x} நாட்களிலும் B {$y} நாட்களிலும் முடிப்பர். இருவரும் சேர்ந்து அவ்வேலையை முடித்து ₹{$total} ஊதியம் பெறுகின்றனர் எனில், A இன் பங்கு எவ்வளவு?",
            "Wages are shared in the ratio 1/{$x} : 1/{$y} = {$y} : {$x}, so A gets {$y}/".($x + $y)." × {$total} = ₹".$this->n($share).'.',
            "ஊதியம் 1/{$x} : 1/{$y} = {$y} : {$x} என்ற விகிதத்தில் பகிரப்படும்; எனவே A இன் பங்கு = {$y}/".($x + $y)." × {$total} = ₹".$this->n($share).'.',
            $share, [$total * $x / ($x + $y), $total / 2, $share + $total / ($x + $y), $share - $total / ($x + $y)],
            $this->rupees(), $this->rupees(),
        );
    }

    /**
     * Two people's days and their days together, all whole: with t days
     * together and any d dividing t², a = t + d and b = t + t²/d.
     *
     * @return array{int, int, int}|null
     */
    private function workTriple(Randomizer $r): ?array
    {
        $t = $r->getInt(2, 24);
        $divisors = array_values(array_filter(range(1, $t * $t), static fn (int $d): bool => ($t * $t) % $d === 0 && $d < $t));

        if ($divisors === []) {
            return null;
        }

        $d = $this->pick($r, $divisors);
        $a = $t + $d;
        $b = $t + intdiv($t * $t, $d);

        return $b > 120 ? null : [$a, $b, $t];
    }

    // ── Assembly ────────────────────────────────────────────────────────

    /**
     * @param  list<int|float>  $wrong  likely mistakes, best first
     * @return array<string, mixed>
     */
    private function draft(
        string $topic, string $difficulty, int $page, string $quote,
        string $enQ, string $taQ, string $enX, string $taX,
        int|float $answer, array $wrong, Closure $formatEn, Closure $formatTa,
        bool $signed = false,
    ): array {
        return [
            'topic' => $topic, 'difficulty' => $difficulty, 'page' => $page, 'quote' => $quote,
            'en_q' => $enQ, 'ta_q' => $taQ, 'en_x' => $enX, 'ta_x' => $taX,
            'answer' => $answer, 'wrong' => $wrong, 'en_fmt' => $formatEn, 'ta_fmt' => $formatTa, 'signed' => $signed,
        ];
    }

    /**
     * Picks three wrong options (likely mistakes first, then near misses) and
     * places the answer at a position fixed by the question's wording, so the
     * same question always has the same letter.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private function finish(array $draft): ?array
    {
        $answer = $draft['answer'];
        $key = fn (int|float $v): string => ($draft['en_fmt'])($v);
        $taken = [$key($answer) => true];
        $wrong = [];

        $fallback = [];
        $step = max(1, (int) round(abs((float) $answer) / 10));
        foreach ([1, -1, 2, -2, 3, -3, 4, 5] as $k) {
            $fallback[] = $answer + $k * $step;
        }

        foreach ([...$draft['wrong'], ...$fallback] as $value) {
            if (count($wrong) === 3) {
                break;
            }

            $value = round((float) $value, 2);

            if (! $draft['signed'] && $value <= 0) {
                continue;
            }

            if (isset($taken[$key($value)])) {
                continue;
            }

            $taken[$key($value)] = true;
            $wrong[] = $value;
        }

        if (count($wrong) < 3) {
            return null;
        }

        $slot = crc32($draft['en_q']) % 4;
        $values = $wrong;
        array_splice($values, $slot, 0, [$answer]);

        return [
            'topic' => $draft['topic'],
            'format' => 'mcq4',
            'difficulty' => $draft['difficulty'],
            'bloom' => 'apply',
            'page' => $draft['page'],
            'quote' => $draft['quote'],
            'answer' => ['A', 'B', 'C', 'D'][$slot],
            'en' => [
                'q' => $draft['en_q'],
                'options' => array_map($draft['en_fmt'], $values),
                'explanation' => $draft['en_x'],
            ],
            'ta' => [
                'q' => $draft['ta_q'],
                'options' => array_map($draft['ta_fmt'], $values),
                'explanation' => $draft['ta_x'],
            ],
        ];
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    private function pick(Randomizer $r, array $items): mixed
    {
        return $items[$r->getInt(0, count($items) - 1)];
    }

    private function whole(int|float $value): bool
    {
        return abs($value - round($value)) < 1e-9;
    }

    /** A number as the book writes it: no grouping, at most two decimals. */
    private function n(int|float $value): string
    {
        if ($this->whole($value)) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function plain(): Closure
    {
        return fn (int|float $v): string => $this->n($v);
    }

    private function rupees(): Closure
    {
        return fn (int|float $v): string => '₹'.$this->n($v);
    }

    private function percent(): Closure
    {
        return fn (int|float $v): string => $this->n($v).'%';
    }

    private function unit(string $plural, string $singular): Closure
    {
        return fn (int|float $v): string => $this->n($v).' '.($this->whole($v) && (int) round($v) === 1 ? $singular : $plural);
    }

    /** A signed percentage read as a rise, a fall or neither. */
    private function change(string $rise, string $fall, string $none): Closure
    {
        return fn (int|float $v): string => match (true) {
            abs((float) $v) < 1e-9 => $none,
            $v > 0 => $this->n($v).'% '.$rise,
            default => $this->n(abs((float) $v)).'% '.$fall,
        };
    }
}
