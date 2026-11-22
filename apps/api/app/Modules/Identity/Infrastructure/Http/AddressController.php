<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use App\Modules\Identity\Application\AddressBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class AddressController
{
    public function __construct(private readonly AddressBook $book) {}

    private function actor(): int
    {
        $id = Auth::guard('web')->id();
        abort_unless(is_int($id), 401);

        return $id;
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->book->all($this->actor())])->header('Cache-Control', 'no-store');
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->book->owned($this->actor(), $id))->header('Cache-Control', 'no-store');
    }

    public function store(Request $r): JsonResponse
    {
        return response()->json($this->book->save($this->actor(), $r), 201)->header('Cache-Control', 'no-store');
    }

    public function update(Request $r, string $id): JsonResponse
    {
        return response()->json($this->book->save($this->actor(), $r, $id))->header('Cache-Control', 'no-store');
    }

    public function destroy(string $id): Response
    {
        $this->book->archive($this->actor(), $id);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function setDefault(string $id): JsonResponse
    {
        return response()->json($this->book->setDefault($this->actor(), $id))->header('Cache-Control', 'no-store');
    }
}
