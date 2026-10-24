<?php

namespace App\Http\Controllers\Ai;

use App\Ai\Transcriber;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Starter\Ai\Agents\AgentManager;
use Starter\Ai\Connections\ErrorClassifier;
use Starter\Ai\Exceptions\AgentLimitExceededException;
use Starter\Ai\Exceptions\AgentNotAvailableException;
use Starter\Ai\Exceptions\BudgetExceededException;
use Starter\Ai\Exceptions\ModelNotUsableException;
use Throwable;

/**
 * Microphone: a completed recording is transcribed and returned as editable
 * text. Used by the chat page, the floating chat and the REST API.
 */
class TranscriptionController extends Controller
{
    public function __invoke(Request $request, AgentManager $agents, Transcriber $transcriber): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'agent_id' => ['nullable'],
            'surface' => ['nullable', Rule::in(['page', 'floating', 'api'])],
        ]);

        $surface = $request->is('api/*') ? 'api' : ($request->input('surface') === 'page' ? 'page' : 'floating');

        try {
            $version = $agents->resolve($user, $request->input('agent_id'), $surface);
        } catch (AgentNotAvailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        $maxSeconds = (int) $version->settings()->get('microphone.max_seconds', 120);

        $request->validate([
            /** A completed recording (webm, ogg, mp3, m4a or wav), detected from its content. */
            'audio' => ['required', 'file', 'max:'.Transcriber::maxKilobytes($version), 'mimetypes:'.implode(',', Transcriber::MIME_TYPES)],
            /** Duration in seconds measured by the recorder. */
            'duration' => ['nullable', 'numeric', 'min:0', 'max:'.($maxSeconds + 5)],
        ], [
            'audio.mimetypes' => __('This audio format is not supported.'),
            'audio.max' => __('The recording is too long.'),
            'duration.max' => __('The recording is too long.'),
        ]);

        /** @var UploadedFile $audio */
        $audio = $request->file('audio');

        try {
            ['text' => $text, 'request' => $ledger] = $transcriber->transcribe($user, $version, $audio, $request->filled('duration') ? (float) $request->input('duration') : null);
        } catch (AgentNotAvailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (AgentLimitExceededException|BudgetExceededException $exception) {
            return response()->json(['message' => $exception->getMessage()], 429);
        } catch (ModelNotUsableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => __(ErrorClassifier::classify($exception)->message())], 502);
        }

        return response()->json([
            'text' => $text,
            'request_id' => $ledger->id,
            /** Transcription cost in USD, or null when unknown. */
            'cost' => $ledger->cost !== null ? (string) $ledger->cost : null,
            'cost_status' => $ledger->cost_status->value,
        ]);
    }
}
