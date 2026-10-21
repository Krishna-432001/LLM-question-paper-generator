<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Review\ChapterNotFound;
use App\Domain\Review\ChapterReview;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Chapter-at-a-time review of written questions: list the chapters still
 * waiting, read a random sample of one, approve the chapter on that sample.
 *
 * Written questions belong to the platform, not to an organisation, so this
 * needs the platform approval permission.
 */
final class ChapterReviewController extends Controller
{
    public function index(Request $request, ChapterReview $chapters): JsonResponse
    {
        $this->authorizePlatformReviewer($request);

        $data = $chapters->chapters();

        return response()->json([
            'data' => $data,
            'meta' => [
                'chapters' => count($data),
                'pending' => array_sum(array_column($data, 'pending')),
            ],
        ]);
    }

    public function sample(Request $request, string $key, ChapterReview $chapters): JsonResponse
    {
        $this->authorizePlatformReviewer($request);

        try {
            return response()->json([
                'data' => $chapters->sample($key, $request->integer('size', ChapterReview::DEFAULT_SAMPLE)),
            ]);
        } catch (ChapterNotFound $e) {
            abort(404, $e->getMessage());
        }
    }

    public function approve(Request $request, string $key, ChapterReview $chapters): JsonResponse
    {
        $this->authorizePlatformReviewer($request);

        $data = $request->validate([
            'checked_question_ids' => ['required', 'array', 'min:1', 'max:50'],
            'checked_question_ids.*' => ['uuid'],
            'batch' => ['sometimes', 'integer', 'between:1,'.ChapterReview::BATCH],
        ]);

        try {
            $result = $chapters->approve(
                $key,
                $request->user(),
                $data['checked_question_ids'],
                (int) ($data['batch'] ?? ChapterReview::BATCH),
            );
        } catch (ChapterNotFound $e) {
            abort(404, $e->getMessage());
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json(['data' => $result]);
    }

    private function authorizePlatformReviewer(Request $request): void
    {
        abort_unless(
            $request->user()?->can('questions.approve.platform'),
            403,
            'Approving written questions needs platform reviewer permission.',
        );
    }
}
