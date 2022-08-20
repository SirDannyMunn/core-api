<?php

namespace Fleetbase\Macros;

class Arr
{
    public function every()
    {
        return function ($array, $callback) {
            return  !in_array(false, array_map($callback, $array));
        };
    }

    public static function target()
    {
        return \Illuminate\Support\Arr::class;
    }
}
