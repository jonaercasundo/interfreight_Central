<?php

namespace App\Http\Controllers;

use App\Services\AuthorizedLandingPage;
use Illuminate\Http\Request;

class DashboardLandingController extends Controller
{
    /**
     * Landing priority for mixed-role accounts is centralized in
     * AuthorizedLandingPage: Administrator, Executive, Sales, then the other
     * department roles. A user without dashboard permission is sent to their
     * first authorized workspace instead of receiving a dashboard 403.
     */
    public function __invoke(Request $request, AuthorizedLandingPage $landingPage)
    {
        $user = $request->user();
        $destination = $landingPage->resolve($user);

        abort_if($destination === null, 403, 'Your account does not have an authorized IFC page. Contact an administrator.');

        if ($destination === '/dashboard') {
            return view('app', ['page' => 'dashboard']);
        }

        return redirect()->to($destination);
    }
}
