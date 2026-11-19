<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ServiceRequest;
use App\Services\MarketplaceBrowse;
use App\Services\RequestBoard;
use App\Services\RequestDetail;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class RequestBoardController extends Controller
{
    public function __construct(
        private readonly MarketplaceBrowse $browse,
        private readonly RequestBoard $board,
        private readonly RequestDetail $detail,
    ) {}

    public function index(Request $request): View
    {
        $filters = $this->browse->filters($request);

        return view('pages.requests.index', [
            'requests' => $this->browse->requests($filters)->paginate(12)->withQueryString(),
            'categories' => Category::active()->orderBy('name')->get(),
            'filters' => $filters,
            'canPublishRequest' => Gate::forUser($request->user('web'))->allows('create', ServiceRequest::class),
        ]);
    }

    public function show(Request $request, ServiceRequest $serviceRequest): Response
    {
        return $this->detail->render($serviceRequest, $request->user('admin') ?? $request->user('web'));
    }

    public function create(): View
    {
        Gate::authorize('create', ServiceRequest::class);

        return $this->form();
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ServiceRequest::class);
        $serviceRequest = $this->board->save($request->user(), $this->validateTerms($request));

        return to_route('requests.show', $serviceRequest)->with('status', 'Permintaan berhasil diterbitkan.');
    }

    public function edit(ServiceRequest $serviceRequest): View
    {
        Gate::authorize('update', $serviceRequest);

        return $this->form($serviceRequest);
    }

    public function update(Request $request, ServiceRequest $serviceRequest): RedirectResponse
    {
        Gate::authorize('update', $serviceRequest);
        $this->board->save($request->user(), $this->validateTerms($request), $serviceRequest);

        return to_route('requests.show', $serviceRequest)->with('status', 'Perubahan permintaan disimpan.');
    }

    public function close(Request $request, ServiceRequest $serviceRequest): RedirectResponse
    {
        $this->board->close($request->user(), $serviceRequest);

        return to_route('requests.show', $serviceRequest)->with('status', 'Permintaan ditutup dan penawaran yang menunggu ditolak.');
    }

    private function form(?ServiceRequest $serviceRequest = null): View
    {
        return view('pages.requests.form', [
            'serviceRequest' => $serviceRequest,
            'categories' => Category::active()->orderBy('name')->get(),
        ]);
    }

    private function validateTerms(Request $request): array
    {
        $terms = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:150'],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'category_id' => ['required', 'integer', 'min:1'],
            'budget_min' => ['required', 'integer', 'between:0,100000000', 'lte:budget_max'],
            'budget_max' => ['required', 'integer', 'between:1,100000000'],
            'deadline_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Jakarta')->toDateString()],
        ]);
        $terms['budget_min'] = (int) $terms['budget_min'];
        $terms['budget_max'] = (int) $terms['budget_max'];

        return $terms;
    }
}
