<?php

namespace App\Support\Forms;

use App\Support\Money;
use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

class MoneyInput
{
    public static function make(string $name, string $label = 'Tutar'): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->prefix('₺')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.', 2)
            JS))
            ->dehydrateStateUsing(fn ($state) => Money::store($state))
            ->formatStateUsing(function ($state) {
                if (blank($state)) {
                    return null;
                }

                // Money::parse handles raw "233999.99", Turkish "233.999,99", and floats
                return Money::format(Money::parse($state));
            })
            ->inputMode('decimal');
    }
}
