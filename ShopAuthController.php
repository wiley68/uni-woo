<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\Shop;
use App\Support\ShopModuleDataBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopAuthController extends Controller
{
    /**
     * Authenticate shop and generate access token
     */
    public function authenticate(Request $request): JsonResponse
    {
        $request->validate([
            'unicid' => 'required|string',
            'name' => 'required|string',
            'secret' => 'required|string',
        ]);

        $shop = Shop::where(Shop::COLUMN_UNICID, $request->unicid)
            ->where(Shop::COLUMN_NAME, $request->name)
            ->first();

        if (! $shop) {
            return response()->json([
                'error' => 'Invalid credentials',
                'message' => 'Shop not found',
            ], 401);
        }

        // Проверяваме secret key (plain text сравнение)
        if ($request->secret !== $shop->secret_key) {
            return response()->json([
                'error' => 'Invalid credentials',
                'message' => 'Invalid secret key',
            ], 401);
        }

        // Генерираме токен
        $token = Str::random(64);
        $expiresAt = now()->addHours(24); // Токенът изтича след 24 часа

        // Запазваме токена (може в cache или в отделна таблица)
        cache()->put("shop_token_{$token}", [
            'shop_id' => $shop->id,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        return response()->json([
            'success' => true,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 86400, // 24 часа в секунди
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'unicid' => $shop->unicid,
            ],
        ]);
    }

    /**
     * Refresh access token
     */
    public function refresh(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'error' => 'Missing token',
                'message' => 'Authorization token is required',
            ], 401);
        }

        $tokenData = cache()->get("shop_token_{$token}");

        if (! $tokenData) {
            return response()->json([
                'error' => 'Invalid token',
                'message' => 'Token not found or expired',
            ], 401);
        }

        $shop = Shop::find($tokenData['shop_id']);

        if (! $shop) {
            return response()->json([
                'error' => 'Invalid token',
                'message' => 'Shop not found',
            ], 401);
        }

        // Изтриваме стария токен
        cache()->forget("shop_token_{$token}");

        // Генерираме нов токен
        $newToken = Str::random(64);
        $expiresAt = now()->addHours(24);

        cache()->put("shop_token_{$newToken}", [
            'shop_id' => $shop->id,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        return response()->json([
            'success' => true,
            'access_token' => $newToken,
            'token_type' => 'Bearer',
            'expires_in' => 86400,
        ]);
    }

    /**
     * Revoke access token
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if ($token) {
            cache()->forget("shop_token_{$token}");
        }

        return response()->json([
            'success' => true,
            'message' => 'Successfully logged out',
        ]);
    }

    /**
     * Display the specified calculator resource.
     */
    public function viewShops(Request $request, ShopModuleDataBuilder $moduleDataBuilder): JsonResponse
    {
        try {
            // Вземаме автентикирания calculator от middleware-а
            $shop = $request->attributes->get('authenticated_shop');

            if (! $shop) {
                return response()->json([
                    'error' => 'Authentication failed',
                    'message' => 'Shop not authenticated',
                ], 401);
            }

            return response()->json([
                'success' => true,
                'message' => 'Shop data retrieved successfully',
                'data' => $moduleDataBuilder->build($shop),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve shop data',
                'message' => 'An error occurred while retrieving the shop data',
            ], 500);
        }
    }

    /**
     * Store a newly created order in storage.
     */
    public function storeOrder(StoreOrderRequest $request): JsonResponse
    {
        try {
            // Вземаме автентикирания calculator от middleware-а
            $shop = $request->attributes->get('authenticated_shop');

            if (! $shop) {
                return response()->json([
                    'error' => 'Authentication failed',
                    'message' => 'Shop not authenticated',
                ], 401);
            }

            // Валидираме данните
            $validated = $request->validated();

            // Добавяме shop_id и user_id
            $validated['shop_id'] = $shop->id;
            $validated['user_id'] = $shop->user_id;

            // Ако няма date_on, задаваме текущата дата
            if (empty($validated['date_on'])) {
                $validated['date_on'] = now();
            }

            // Ако няма status, задаваме default
            if (empty($validated['status'])) {
                $validated['status'] = 'Регистрирана';
            }

            // Ако няма currency, задаваме default
            if (empty($validated['currency'])) {
                $validated['currency'] = 'BGN';
            }

            // Създаваме ордера
            $order = Order::create($validated);

            // Логваме успешното създаване
            Log::info('Order created via API', [
                'order_id' => $order->id,
                'shop_id' => $shop->id,
                'shop_unicid' => $shop->unicid,
                'shop_name' => $shop->name,
                'order_data' => $validated,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => [
                    'id' => $order->id,
                    'shop_id' => $order->shop_id,
                    'created_at' => $order->created_at && method_exists($order->created_at, 'format') ? $order->created_at->format('Y-m-d H:i:s') : $order->created_at,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create order via API', [
                'shop_id' => $shop->id ?? null,
                'shop_unicid' => $shop->unicid ?? null,
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'error' => 'Failed to create order',
                'message' => 'An error occurred while creating the order',
            ], 500);
        }
    }
}
