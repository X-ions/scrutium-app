<?php

namespace App\Http\Controllers;

use App\Support\Tour;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class TourController extends Controller
{
    /**
     * The always-available written version of the walkthrough, for anyone who
     * dismissed the overlay and wants it back.
     */
    public function index(Request $request): View
    {
        $tour = Tour::for($request->user());

        return view('pages.scrutium.how-it-works', [
            'title' => 'How it works',
            'steps' => $tour['steps'],
            'storageKey' => $tour['storage_key'],
            'currentRoute' => Tour::currentRoute(),
        ]);
    }
}
