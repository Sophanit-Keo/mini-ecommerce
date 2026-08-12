<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Every lookup is scoped through the authenticated user's own notifications — never
 * fetch-then-check — following the same IDOR discipline as AddressController/OrderController.
 */
class NotificationController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        // Pagination executes a COUNT(*) alongside the offset query. Cap this untrusted input
        // so an accidental or hostile `?perPage=1000000` cannot hydrate a huge response.
        $perPage = min(max((int) $request->query('perPage', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        $notifications = $request->user()->notifications()
            ->when($request->boolean('unreadOnly'), fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => NotificationResource::collection($notifications->items()),
            'page' => [
                'currentPage' => $notifications->currentPage(),
                'lastPage' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    /**
     * Idempotent: marking an already-read notification as read again is not an error.
     */
    public function markRead(Request $request, string $notificationId): NotificationResource
    {
        $notification = $this->findForUser($request, $notificationId);

        $notification->markAsRead();

        return NotificationResource::make($notification);
    }

    public function markAllRead(Request $request): Response
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->noContent();
    }

    private function findForUser(Request $request, string $notificationId): Notification
    {
        $notification = $request->user()->notifications()->wherePublicId($notificationId)->first();

        if ($notification === null) {
            throw ProblemException::notFound('No such notification.');
        }

        return $notification;
    }
}
