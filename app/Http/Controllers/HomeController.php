<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function home(Request $request)
    {
        return Inertia::render('Home', [
            'test' => "test string"
        ]);
    }
}
