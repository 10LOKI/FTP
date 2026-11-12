<?php

namespace App\Http\Controllers;

use App\Http\Resources\CustomerOrderResource;
use App\Models\CustomerProfile;
use App\Services\SalesOrderReview;
use App\Services\SalesQuoteDrafts;
use App\Services\SalesQuotes;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SalesReviewController extends Controller
{
    private function authorizeReview(Request $request): void
    {
        Gate::authorize('ORDER_REVIEW');
        abort_if(CustomerProfile::query()->where('user_id', $request->user()->id)->exists(), 403);
    }

    public function page(Request $request): Response
    {
        $this->authorizeReview($request);

        return response()->view('sales-review')->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeReview($request);
        $filters = $request->validate([
            'number' => ['nullable', 'string', 'max:40'],
            'customer_code' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['SOUMISE', 'EN_VERIFICATION', 'ATTENTE_ACCORD', 'CONFIRMEE', 'EN_PREPARATION', 'EXPEDIEE', 'LIVREE', 'ANNULEE', 'PARTIELLEMENT_LIVREE'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ]);
        $query = DB::table('customer_orders')->orderByDesc('id');
        foreach (['number', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['customer_code'])) {
            $query->where('customer_snapshot->customer_code', $filters['customer_code']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        $page = $query->paginate(20)->through(function (object $order): array {
            $customer = json_decode($order->customer_snapshot, true, 512, JSON_THROW_ON_ERROR);

            return ['id' => (string) $order->id, 'number' => $order->number, 'status' => $order->status,
                'version' => (int) $order->version, 'submitted_at' => $order->created_at,
                'customer' => Arr::only($customer, ['id', 'customer_code', 'type', 'company_name'])];
        });

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $this->authorizeReview($request);
        $row = DB::table('customer_orders')->where('id', $order)->first();
        abort_unless($row !== null, 404);
        $data = (new CustomerOrderResource($row))->resolve($request);
        $data['customer'] = Arr::only(json_decode($row->customer_snapshot, true, 512, JSON_THROW_ON_ERROR),
            ['id', 'customer_code', 'type', 'company_name']);
        $terms = DB::table('customer_payment_terms')->where('customer_id', $row->user_id)->orderByDesc('version')->first(['mode', 'version']);
        $data['payment_mode'] = $terms?->mode;
        $data['payment_terms_version'] = $terms === null ? null : (int) $terms->version;
        $data['payment_terms_status'] = $terms === null ? 'terms_required' : 'available';
        $data['payment_policy_enabled'] = config('receivables.enabled') === true;
        $review = DB::table('sales_order_reviews')->where('order_id', $order)->first(['claimed_by', 'claimed_at']);
        $canManage = Gate::allows('ORDER_REVIEW_MANAGE');
        $data['can_claim'] = $canManage && $review === null && $row->status === 'SOUMISE';
        $data['can_manage'] = $canManage && $review !== null && (int) $review->claimed_by === (int) $request->user()->id
            && in_array($row->status, ['EN_VERIFICATION', 'ATTENTE_ACCORD'], true);
        $data['quote_drafts'] = DB::table('sales_quote_drafts')->where('order_id', $order)->orderBy('revision')
            ->get(['id', 'revision', 'actor_id', 'order_version', 'snapshot', 'created_at'])->map(function (object $draft): array {
                return ['id' => $draft->id, 'revision' => (int) $draft->revision, 'actor_id' => (string) $draft->actor_id,
                    'order_version' => (int) $draft->order_version, 'created_at' => $draft->created_at,
                    'snapshot' => json_decode($draft->snapshot, true, flags: JSON_THROW_ON_ERROR),
                    'issuance' => app(SalesQuotes::class)->readiness($draft)];
            });
        $data['quotes'] = DB::table('sales_quotes')->where('order_id', $order)->orderBy('revision')->get()
            ->map(fn (object $quote): array => ['id' => $quote->id, 'number' => $quote->number, 'revision' => (int) $quote->revision,
                'order_version' => (int) $quote->order_version, 'issued_at' => $quote->issued_at, 'valid_until' => $quote->valid_until,
                'document_url' => route('sales.quotes.document', $quote->id),
                'decision' => DB::table('sales_quote_decisions')->where('quote_id', $quote->id)->first(['actor_id', 'outcome', 'contact_method', 'contact_name', 'contacted_at'])]);
        $data['review'] = $review;
        $data['calls'] = DB::table('sales_order_calls')->where('order_id', $order)->orderBy('created_at')->orderBy('id')
            ->get(['id', 'actor_id', 'outcome', 'happened_at', 'note', 'order_version']);
        $data['revisions'] = DB::table('sales_order_revisions')->where('order_id', $order)->orderBy('order_version')
            ->get(['id', 'actor_id', 'kind', 'before', 'after', 'reason', 'order_version', 'created_at'])
            ->map(function (object $revision): array {
                return ['id' => $revision->id, 'actor_id' => (string) $revision->actor_id, 'kind' => $revision->kind,
                    'before' => json_decode($revision->before, true, 512, JSON_THROW_ON_ERROR),
                    'after' => json_decode($revision->after, true, 512, JSON_THROW_ON_ERROR),
                    'reason' => $revision->reason, 'order_version' => (int) $revision->order_version,
                    'created_at' => $revision->created_at, 'requires_agreement' => true];
            });

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function claim(Request $request, int $order, SalesOrderReview $reviews): JsonResponse
    {
        $this->authorizeReview($request);
        $values = $request->validate(['expected_version' => ['required', 'integer', 'min:1', 'max:4294967293']]);

        return response()->json(['data' => $reviews->claim($request->user(), $order, (int) $values['expected_version'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function proposeRevision(Request $request, int $order, SalesOrderReview $reviews): JsonResponse
    {
        $this->authorizeReview($request);
        $kind = $request->validate(['kind' => ['required', Rule::in(['contact', 'delivery_address', 'billing_address', 'lines'])]])['kind'];
        $rules = [
            'kind' => ['required', Rule::in(['contact', 'delivery_address', 'billing_address', 'lines'])],
            'request_key' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1', 'max:4294967293'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
        if ($kind === 'lines') {
            $rules += ['changes' => ['required', 'array:lines'], 'pricing_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]
                + $this->lineRules('changes.lines');
        } elseif ($kind === 'contact') {
            $rules += ['changes' => ['required', 'array:name,email,phone'],
                'changes.name' => ['required', 'string', 'max:160'], 'changes.email' => ['required', 'email', 'max:254'],
                'changes.phone' => ['required', 'string', 'max:40']];
        } else {
            $rules += ['changes' => ['required', 'array:address,city,postal_code,country'],
                'changes.address' => ['required', 'string', 'max:500'], 'changes.city' => ['required', 'string', 'max:120'],
                'changes.country' => ['required', 'string', 'max:120'], 'changes.postal_code' => ['nullable', 'string', 'max:30']];
        }

        return response()->json(['data' => $reviews->proposeRevision($request->user(), $order, $request->validate($rules))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function recordCall(Request $request, int $order, SalesOrderReview $reviews): JsonResponse
    {
        $this->authorizeReview($request);
        $values = $request->validate([
            'request_key' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1', 'max:4294967293'],
            'outcome' => ['required', Rule::in(['JOINED', 'UNREACHABLE', 'CALLBACK_REQUESTED', 'AGREEMENT', 'REFUSED'])],
            'happened_at' => ['required', 'date', 'before_or_equal:now'], 'note' => ['nullable', 'string', 'max:2000'],
            'actor_id' => ['prohibited'], 'status' => ['prohibited'], 'lines' => ['prohibited'],
        ]);
        $values['happened_at'] = CarbonImmutable::parse($values['happened_at'])->utc()->format('Y-m-d H:i:s');

        return response()->json(['data' => $reviews->recordCall($request->user(), $order, $values)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function previewLines(Request $request, int $order, SalesOrderReview $reviews): JsonResponse
    {
        $this->authorizeReview($request);
        $values = $request->validate($this->lineRules('lines') + ['expected_version' => ['required', 'integer', 'min:1', 'max:4294967293']]);

        return response()->json(['data' => $reviews->previewLines($request->user(), $order, $values['lines'], (int) $values['expected_version'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function quoteDraft(Request $request, int $order, SalesQuoteDrafts $drafts): JsonResponse
    {
        $this->authorizeReview($request);
        $save = $request->routeIs('sales.review.quote-drafts');
        $values = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1', 'max:4294967293'],
            'valid_until' => ['required', 'date_format:Y-m-d'],
            'request_key' => [$save ? 'required' : 'prohibited', 'uuid'],
            'preview_token' => [$save ? 'required' : 'prohibited', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'lines' => ['prohibited'], 'payment_terms' => ['prohibited'], 'status' => ['prohibited'],
        ]);

        return response()->json(['data' => $drafts->prepare($request->user(), $order, $values, $save)])
            ->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string, list<string>> */
    private function lineRules(string $prefix): array
    {
        return [$prefix => ['required', 'array', 'list', 'min:1', 'max:200'],
            $prefix.'.*' => ['required', 'array:product_id,quantity,units_per_package'],
            $prefix.'.*.product_id' => ['required', 'integer', 'min:1'],
            $prefix.'.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            $prefix.'.*.units_per_package' => ['required', 'integer', 'min:1', 'max:2147483647']];
    }
}
