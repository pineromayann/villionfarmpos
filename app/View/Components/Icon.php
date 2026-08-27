<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class Icon extends Component
{
    public function __construct(public string $name) {}

    public function render(): View
    {
        return view('components.icon');
    }
}
