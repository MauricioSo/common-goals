<?php
/**
 * Deterministic, seedable pseudo-random generator for property-based tests.
 *
 * Uses a self-contained xorshift64* implementation so it does not pollute
 * PHP's global mt_srand state (which Brain Monkey and other code may rely on).
 * Every property test constructs a generator with a fixed seed recorded in the
 * PHPUnit message, making any failing counter-example fully reproducible.
 *
 * @package CommonGoals\Tests\Property\Support
 */

namespace CommonGoals\Tests\Property\Support;

/**
 * Seedable RNG used by all property generators.
 */
final class PropertyRng
{
    private int $state;

    public function __construct(int $seed)
    {
        // xorshift32 works on a 32-bit state; avoid zero and keep within int range.
        $this->state = ($seed & 0xFFFFFFFF) ?: 0x9E3779B9;
    }

    /**
     * Returns a non-negative 32-bit integer.
     */
    public function int(): int
    {
        return $this->next() & 0x7FFFFFFF;
    }

    /**
     * Uniform integer in [$min, $max] inclusive.
     */
    public function between(int $min, int $max): int
    {
        if ($max < $min) {
            return $min;
        }
        $span = $max - $min + 1;
        return $min + ($this->int() % $span);
    }

    public function bool(): bool
    {
        return ($this->int() & 1) === 1;
    }

    /**
     * Random element of an array.
     *
     * @template T
     * @param array<int, T> $values
     * @return T
     */
    public function element(array $values)
    {
        return $values[$this->between(0, count($values) - 1)];
    }

    /**
     * Random ASCII string of up to $maxLen characters from the given alphabet.
     */
    public function string(int $maxLen, string $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789 _-'): string
    {
        $len = $this->between(0, $maxLen);
        $out = '';
        $size = strlen($alphabet);
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[$this->between(0, $size - 1)];
        }
        return $out;
    }

    /**
     * Random UTF-8 multibyte string (for length-boundary properties).
     */
    public function multibyteString(int $maxLen): string
    {
        $len = $this->between(0, $maxLen);
        $out = '';
        $pool = ['a', 'b', 'ñ', 'ü', '€', '中', '🚀', ' ', '<', '>'];
        for ($i = 0; $i < $len; $i++) {
            $out .= $pool[$this->between(0, count($pool) - 1)];
        }
        return $out;
    }

    private function next(): int
    {
        $x = $this->state & 0xFFFFFFFF;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= ($x >> 17);
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;
        return $this->state;
    }
}
