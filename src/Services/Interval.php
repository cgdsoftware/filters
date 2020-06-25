<?php

namespace LaravelEnso\Filters\Services;

use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Config;
use Iterator;
use LaravelEnso\Filters\DTOs\Segment;
use LaravelEnso\Filters\Enums\Adjustments;
use LaravelEnso\Filters\Enums\Intervals;
use LaravelEnso\Filters\Enums\TimeSegments;
use LaravelEnso\Filters\Exceptions\Interval as Exception;

class Interval implements Iterator
{
    private string $type;
    private ?Carbon $min;
    private ?Carbon $max;
    private array $labels;
    private ?int $adjustment;
    private Carbon $start;
    private Carbon $end;
    private Closure $incrementer;
    private string $labelFormat;
    private int $key;
    private int $timeSegment;

    public function __construct(string $type, ?Carbon $min = null, ?Carbon $max = null)
    {
        $this->type = $type;
        $this->min = $min;
        $this->max = $max;

        $this->validate();

        $this->labels = [];
        $this->adjustment = Adjustments::get($this->type);

        $this->scenario()->init();
    }

    public function labels(): array
    {
        return $this->labels;
    }

    public function min(): Carbon
    {
        return $this->min->copy();
    }

    public function max(): Carbon
    {
        return $this->max->copy();
    }

    public function current(): Segment
    {
        return new Segment($this->start->copy(), $this->end->copy());
    }

    public function key(): int
    {
        return $this->key;
    }

    public function next(): void
    {
        $this->labels[] = $this->label();

        $incrementer = $this->incrementer;
        $incrementer($this->start);
        $incrementer($this->end);
        $this->key++;
    }

    public function rewind(): void
    {
        $this->scenario()->init();
    }

    public function valid(): bool
    {
        return $this->start->isBefore($this->max);
    }

    public function timeSegment(): int
    {
        return $this->timeSegment;
    }

    private function scenario(): self
    {
        switch ($this->type) {
            case Intervals::Today:
            case Intervals::Yesterday:
            case Intervals::Tomorrow:
                return $this->days()->hourly();
            case Intervals::ThisWeek:
            case Intervals::LastWeek:
            case Intervals::NextWeek:
                return $this->weeks()->daily();
            case Intervals::ThisMonth:
            case Intervals::LastMonth:
            case Intervals::NextMonth:
                return $this->months()->daily();
            case Intervals::ThisYear:
            case Intervals::LastYear:
            case Intervals::NextYear:
                return $this->years()->monthly();
            case Intervals::Custom:
                return $this->custom();
            case Intervals::All:
                return $this->all();
        }
    }

    private function init(): void
    {
        $incrementer = $this->incrementer;
        $this->start = $this->min->copy();
        $this->end = $incrementer($this->start->copy());
        $this->key = 0;
    }

    private function hourly(): self
    {
        $this->incrementer = fn (Carbon $date) => $date->addHour();
        $this->labelFormat = 'H';
        $this->timeSegment = TimeSegments::Hourly;

        return $this;
    }

    private function days(): self
    {
        $this->min = Carbon::today()->addDays($this->adjustment)->startOfDay();
        $this->max = $this->min->copy()->addDay();

        return $this;
    }

    private function daily(): self
    {
        $this->incrementer = fn (Carbon $date) => $date->addDay();
        $this->labelFormat = Config::get('enso.config.dateFormat');
        $this->timeSegment = TimeSegments::Daily;

        return $this;
    }

    private function weeks(): self
    {
        $this->min = Carbon::today()->addWeeks($this->adjustment)->startOfWeek();
        $this->max = $this->min->copy()->addWeek();

        return $this;
    }

    private function months(): self
    {
        $this->min = Carbon::today()->addMonths($this->adjustment)->startOfMonth();
        $this->max = $this->min->copy()->addMonth();

        return $this;
    }

    private function monthly(): self
    {
        $this->incrementer = fn (Carbon $date) => $date->addMonth();
        $this->labelFormat = 'M-y';
        $this->timeSegment = TimeSegments::Monthly;

        return $this;
    }

    private function years(): self
    {
        $this->min = Carbon::today()->addYears($this->adjustment)->startOfYear();
        $this->max = $this->min->copy()->addYear();

        return $this;
    }

    private function yearly(): self
    {
        $this->incrementer = fn (Carbon $date) => $date->addYear();
        $this->labelFormat = 'Y';
        $this->timeSegment = TimeSegments::Yearly;

        return $this;
    }

    private function custom(): self
    {
        $days = $this->max->diffInDays($this->min);

        if ($days === 1) {
            $this->hourly();
        } elseif ($days <= 31) {
            $this->daily();
        } elseif ($days <= 365) {
            $this->monthly();
        } else {
            $this->yearly();
        }

        return $this;
    }

    private function all(): self
    {
        if ($this->min->isSameDay($this->max)) {
            $this->min->startOfHour();
            $this->max->startOfHour()->addHour();
            $this->hourly();
        } elseif ($this->min->copy()->addMonth()->gte($this->max)) {
            $this->min->startOfDay();
            $this->max->startOfDay()->addDay();
            $this->daily();
        } elseif ($this->min->copy()->addYear()->subMonth()->gte($this->max)) {
            $this->min->startOfMonth();
            $this->max->startOfMonth()->addMonth();
            $this->monthly();
        } else {
            $this->min->startOfYear();
            $this->max->startOfYear()->addYear();
            $this->yearly();
        }

        return $this;
    }

    private function label(): string
    {
        return in_array($this->type, [Intervals::Today, Intervals::Yesterday, Intervals::Tomorrow])
            ? $this->end->format($this->labelFormat)
            : $this->start->format($this->labelFormat);
    }

    private function validate(): void
    {
        if (! Intervals::keys()->contains($this->type)) {
            throw Exception::type($this->type);
        }

        if (Intervals::isManual($this->type)) {
            if (! $this->min || ! $this->max) {
                throw Exception::limit();
            }

            if ($this->min->isAfter($this->max)) {
                throw Exception::interval();
            }
        }
    }
}
