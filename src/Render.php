<?php

declare(strict_types=1);

namespace Blokctl;

use function Termwind\render;

class Render
{
    public static function title(string $title): void
    {
        render(<<<HTML
            <div class="space-x-1">
                <span class="py-1 px-2 bg-blue-500 text-gray-100">{$title}</span>
            </div>
            HTML);
    }

    public static function labelValue(string $label, ?string $value = ""): void
    {
        render(<<<HTML
                <div class="flex mx-1 space-x-1">
                    <span class="font-bold">{$label}</span>
                    <span class="flex-1 content-repeat-[.] text-gray"></span>
                    <span class="font-bold text-green">{$value}</span>
                </div>
            HTML);
    }

    public static function titleSection(string $title): void
    {
        render(<<<HTML
            <div class="mt-1">
                <span class="font-bold text-green">{$title}</span>
            </div>
            HTML);
    }

    public static function labelValueCondition(
        string $label,
        bool $condition,
        string $valueTrue = "YES",
        string $valueFalse = "NO"
    ): void {
        $value = $valueFalse;
        $color = "red";
        if ($condition) {
            $value = $valueTrue;
            $color = "green";
        }

        render(<<<HTML
                <div class="flex mx-1 space-x-1">
                    <span class="font-bold">{$label}</span>
                    <span class="flex-1 content-repeat-[.] text-gray"></span>
                    <span class="font-bold text-{$color}">{$value}</span>
                </div>
            HTML);
    }

    public static function log(string $message): void
    {
        render(<<<HTML
            <div class="px-1 flex">
                <span class="bg-gray-500 text-yellow-100">LOG: {$message}</span>
                <span class="flex-1 content-repeat-[_] bg-gray-500 text-yellow-100"></span>
            </div>
            HTML);
    }

    public static function error(string $message): void
    {
        render(<<<HTML
            <div class="px-1 flex">
                <span class="bg-red-500 text-white">ERROR: {$message}</span>
                <span class="flex-1 content-repeat-[_] bg-red-500 text-white"></span>
            </div>
            HTML);
    }
}
