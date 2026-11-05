<?php

namespace App\Http\Controllers;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

/**
 * The component gallery of the redesign. A development aid: it exists only when APP_ENV is local and
 * answers 404 everywhere else, so production never shows it.
 */
class UiKitController extends Controller
{
    /**
     * Show every base component in its variants.
     */
    public function __invoke(): View
    {
        abort_unless(app()->isLocal(), 404);

        // One sample error, so the gallery shows a field in its error state.
        view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag(['kit-error' => __('This username is already taken.')])));

        return view('ui-kit');
    }
}
