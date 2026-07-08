<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sanctum の個人アクセストークン管理（一覧・発行・失効）。すべて本人のトークンのみ対象。
 */
class TokenController extends Controller
{
    /** 自分のトークン一覧（ハッシュ値 token カラムは Sanctum 側で $hidden のため漏れない） */
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->orderByDesc('id')
            ->get(['id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at']);

        return response()->json($tokens);
    }

    /** 新しい名前付きトークンを発行。平文は発行時の一度だけ返す */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'expires_in_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $expiresAt = isset($data['expires_in_days'])
            ? now()->addDays($data['expires_in_days'])
            : null;

        $token = $request->user()->createToken($data['name'], ['*'], $expiresAt);

        return response()->json([
            'name' => $data['name'],
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt,
        ], 201);
    }

    /** 自分のトークンを個別失効。他人/不在は 404 */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($id)->first();

        if (! $token) {
            abort(404);
        }

        $token->delete();

        return response()->json(null, 204);
    }
}
