<?php

namespace App\Http\Controllers;

use App\Enums\GigStatus;
use App\Models\Category;
use App\Models\Gig;
use App\Services\GigStudio;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

final class StudioGigController extends Controller
{
    public function __construct(private readonly GigStudio $studio) {}

    public function index(Request $request): View
    {
        return view('pages.studio.gigs.index', [
            'gigs' => $request->user()->gigs()->with('category')->latest()->orderByDesc('id')->paginate(12),
            'canCreate' => Gate::allows('create', Gig::class),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Gig::class);

        return $this->form();
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Gig::class);
        $this->studio->save($request->user(), $this->validateTerms($request), $request->file('portfolio'));

        return to_route('studio.gigs.index')->with('status', 'Jasa berhasil diterbitkan.');
    }

    public function edit(Gig $gig): View
    {
        Gate::authorize('update', $gig);

        return $this->form($gig);
    }

    public function update(Request $request, Gig $gig): RedirectResponse
    {
        Gate::authorize('update', $gig);
        $this->studio->save($request->user(), $this->validateTerms($request), $request->file('portfolio'), $gig);

        return to_route('studio.gigs.index')->with('status', 'Perubahan jasa disimpan.');
    }

    public function toggle(Request $request, Gig $gig): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'in:active,inactive']]);
        $status = GigStatus::from($data['status']);
        $this->studio->changeStatus($request->user(), $gig, $status);
        $message = $status === GigStatus::Active ? 'Jasa diaktifkan.' : 'Jasa dinonaktifkan.';

        return to_route('studio.gigs.index')->with('status', $message);
    }

    private function form(?Gig $gig = null): View
    {
        return view('pages.studio.gigs.form', [
            'gig' => $gig,
            'categories' => Category::active()->orderBy('name')->get(),
        ]);
    }

    private function validateTerms(Request $request): array
    {
        $terms = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:150'],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'category_id' => ['required', 'integer', 'min:1'],
            'base_price' => ['required', 'integer', 'between:1,100000000'],
            'delivery_days' => ['required', 'integer', 'between:1,365'],
            'max_revisions' => ['required', 'integer', 'between:0,20'],
            'remove_portfolio' => ['sometimes', 'boolean'],
            'portfolio' => [
                'bail', 'nullable', 'file', 'mimetypes:image/jpeg,image/png,application/pdf',
                function (string $attribute, UploadedFile $file, Closure $fail): void {
                    $maximum = $file->getMimeType() === 'application/pdf' ? 20 : 5;
                    if ($file->getSize() > $maximum * 1024 * 1024) {
                        $fail("Ukuran portofolio maksimal {$maximum} MB.");
                    }
                },
            ],
        ]);
        unset($terms['portfolio']);
        $terms['base_price'] = (int) $terms['base_price'];

        return $terms;
    }
}
