<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::all();

        $result = [];
        foreach ($orders as $order) {
            $user = User::find($order->user_id);
            $result[] = [
                'id' => $order->id,
                'user_name' => $user->name,
                'total' => $order->total,
            ];
        }

        return response()->json($result);
    }

    public function store(Request $request)
    {
        $order = new Order();
        $order->user_id = $request->user_id;
        $order->total = $request->total;
        $order->status = $request->status;
        $order->save();

        \DB::select("SELECT * FROM audit_log WHERE order_id = " . $order->id);

        return response()->json($order);
    }

    public function destroy($id)
    {
        $order = Order::find($id);
        $order->delete();
        return response()->json(['message' => 'Deleted']);
    }
}