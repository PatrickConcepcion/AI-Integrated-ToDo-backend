<?php

namespace App\Enums;

enum StatusEnum: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}