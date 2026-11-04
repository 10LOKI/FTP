<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicContactController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $contact = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:254',
            'message' => 'required|string|max:5000',
        ]);
        DB::table('contact_requests')->insert($contact + ['created_at' => now(), 'updated_at' => now()]);

        return redirect()->route('public.contact')->with('contact_received', true);
    }
}
