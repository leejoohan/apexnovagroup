<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $user = $request->user();

        $orders = Order::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->select(['id', 'total'])
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        // Every returned order belongs to this authenticated user,
        // so there is no need to query the user inside the loop.
        $orders->through(fn (Order $order) => [
            'id' => $order->id,
            'user_name' => $user->name,
            'total' => $order->total,
        ]);

        return response()->json($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'total' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'user_id' => ['prohibited'],
            'status' => ['prohibited'],
        ]);

        $order = new Order();
        $order->user_id = $request->user()->getAuthIdentifier();

        // For checkout orders, use a server-calculated total instead.
        $order->total = $validated['total'];

        // Use the initial status defined by your application's workflow.
        $order->status = 'pending';
        $order->save();

        return response()->json([
            'data' => [
                'id' => $order->id,
                'user_id' => $order->user_id,
                'total' => $order->total,
                'status' => $order->status,
            ],
        ], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $order = Order::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->findOrFail($id);

        $order->delete();

        return response()->json([
            'message' => 'Deleted',
        ]);
    }
}