<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class PageController extends Controller
{
    public function display(): View
    {
        return view('display');
    }

    public function participant(): View
    {
        return view('participant');
    }

    public function operator(): View
    {
        return view('operator');
    }
}
