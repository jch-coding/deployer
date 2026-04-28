<?php

namespace App;

enum TaskJobQueue: string
{
    case Default = 'default';
    case First = 'first';
    case Second = 'second';
    case Third = 'third';
    case Fourth = 'fourth';

    /**
     * @return list<self>
     */
    public static function orderedCases(): array
    {
        return [
            self::Default,
            self::First,
            self::Second,
            self::Third,
            self::Fourth,
        ];
    }
}
