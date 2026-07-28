<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Every read filters by the authenticated user *in the query itself*, never by fetching and
 * then checking. The second form is how IDOR vulnerabilities happen: the check gets skipped
 * on one code path during a refactor and nothing fails visibly.
 *
 * Requesting another customer's address returns 404, not 403. A 403 confirms the resource
 * exists, which is itself a disclosure — an attacker can enumerate valid ids by watching
 * which ones answer differently.
 */
class AddressController extends Controller
{
    /**
     * Default address first — it is the one checkout preselects.
     */
    public function index(Request $request): JsonResponse
    {
        $addresses = $request->user()->addresses()
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => AddressResource::collection($addresses)]);
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $address = DB::transaction(function () use ($request) {
            $attributes = $request->toAttributes();

            if ($attributes['is_default'] ?? false) {
                $this->clearExistingDefault($request);
            }

            // Refreshed so database defaults (is_default, timestamps) are in the response
            // rather than appearing as nulls the client has to guess at.
            return $request->user()->addresses()->create($attributes)->refresh();
        });

        return AddressResource::make($address)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, string $addressId): AddressResource
    {
        return AddressResource::make($this->findForUser($request, $addressId));
    }

    /**
     * Editing an address never alters orders already placed to it — an order carries its own
     * `delivery_address_snapshot` of the address as it stood at placement.
     */
    public function update(StoreAddressRequest $request, string $addressId): AddressResource
    {
        $address = $this->findForUser($request, $addressId);

        DB::transaction(function () use ($request, $address) {
            $attributes = $request->toAttributes();

            // uq_addresses_default enforces one default per user in the database, so the old
            // one has to go before the new one lands.
            if (($attributes['is_default'] ?? false) && ! $address->is_default) {
                $this->clearExistingDefault($request);
            }

            $address->update($attributes);
        });

        return AddressResource::make($address->refresh());
    }

    public function destroy(Request $request, string $addressId): Response
    {
        $this->findForUser($request, $addressId)->delete();

        return response()->noContent();
    }

    private function findForUser(Request $request, string $addressId): Address
    {
        $address = $request->user()->addresses()->wherePublicId($addressId)->first();

        if ($address === null) {
            throw ProblemException::notFound('No such address.');
        }

        return $address;
    }

    private function clearExistingDefault(Request $request): void
    {
        $request->user()->addresses()->where('is_default', true)->update(['is_default' => false]);
    }
}
