<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Models\Conversation;
use Laravel\Sanctum\Sanctum;
use Starter\Ai\Agents\ConfiguredAgent;
use Starter\Ai\Agents\TitleAgent;
use Starter\Ai\Enums\CapabilityStatus;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\Attachment;
use Starter\Ai\Models\ModelCapability;
use Tests\Concerns\CreatesAiCatalog;
use Tests\TestCase;

class MultimediaTest extends TestCase
{
    use CreatesAiCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function events(TestResponse $response): array
    {
        $events = [];

        foreach (explode("\n\n", $response->streamedContent()) as $chunk) {
            $data = trim((string) preg_replace('/^data: /', '', trim($chunk)));

            if ($data !== '' && $data !== '[DONE]') {
                $events[] = json_decode($data, true);
            }
        }

        return $events;
    }

    /**
     * A tiny valid WAV recording (detected as audio by its content).
     */
    protected function wav(int $seconds = 1): UploadedFile
    {
        $samples = str_repeat("\x00\x00", 8000 * $seconds);
        $header = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples));
        $path = tempnam(sys_get_temp_dir(), 'wav');
        file_put_contents($path, $header.$samples);

        return new UploadedFile($path, 'recording.wav', 'audio/wav', null, true);
    }

    /**
     * A real upload (fake uploads guess the MIME type from the extension).
     */
    protected function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function imagesAgent(): void
    {
        $this->simulatedModel();
        $this->aiAgent(['multimedia' => ['images' => true, 'documents' => false, 'max_files' => 2, 'max_file_mb' => 1]]);
    }

    public function test_images_are_validated_stored_privately_and_sent_to_the_model(): void
    {
        $this->imagesAgent();
        $user = $this->userWithRole('reader');

        $events = $this->events($this->actingAs($user)->post(route('ai.chat.stream'), [
            'message' => '¿Qué ves?',
            'files' => [UploadedFile::fake()->image('foto.png', 40, 40)],
        ])->assertOk());

        $summary = collect($events)->firstWhere('type', 'summary');
        $attachment = Attachment::query()->sole();

        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame('foto.png', $attachment->name);
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertSame($summary['request_id'], $attachment->ai_request_id);
        $this->assertSame($summary['conversation_id'], $attachment->conversation_id);
        $this->assertNotNull($attachment->expires_at);

        $this->actingAs($user)
            ->getJson(route('ai.conversations.show', $summary['conversation_id']))
            ->assertOk()
            ->assertJsonPath('data.messages.0.attachments.0.name', 'foto.png');
    }

    public function test_incompatible_attachments_are_rejected(): void
    {
        $this->imagesAgent();
        $user = $this->userWithRole('reader');

        // A document disguised with an image extension is detected by content...
        $this->actingAs($user)->postJson(route('ai.chat.stream'), [
            'message' => 'hola',
            'files' => [$this->realFile('falsa.png', 'texto plano, no una imagen')],
        ])->assertUnprocessable()->assertJsonValidationErrors('files.0');

        $this->actingAs($user)->postJson(route('ai.chat.stream'), [
            'message' => 'hola',
            'files' => [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png'), UploadedFile::fake()->image('c.png')],
        ])->assertUnprocessable()->assertJsonValidationErrors('files');

        $this->actingAs($user)->postJson(route('ai.chat.stream'), [
            'message' => 'hola',
            'files' => [UploadedFile::fake()->image('grande.png')->size(2048)],
        ])->assertUnprocessable()->assertJsonValidationErrors('files.0');

        $model = AiModel::query()->where('model', 'simulated-chat')->sole();
        ModelCapability::query()->where('model_id', $model->id)->where('capability', 'vision')->update(['status' => CapabilityStatus::Unknown]);

        $this->actingAs($user)->postJson(route('ai.chat.stream'), [
            'message' => 'hola',
            'files' => [UploadedFile::fake()->image('a.png')],
        ])->assertUnprocessable()->assertJsonValidationErrors('files');

        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame(0, AiRequest::query()->count());
    }

    public function test_expired_attachments_are_deleted_and_skipped_in_the_context(): void
    {
        $this->imagesAgent();
        $user = $this->userWithRole('reader');

        $events = $this->events($this->actingAs($user)->post(route('ai.chat.stream'), [
            'message' => 'primera',
            'files' => [UploadedFile::fake()->image('foto.png')],
        ]));
        $conversation = collect($events)->firstWhere('type', 'summary')['conversation_id'];
        $attachment = Attachment::query()->sole();

        $this->travel(31)->days();
        $this->artisan('starter:ai-maintain')->assertSuccessful();

        Storage::disk('local')->assertMissing($attachment->path);
        $this->assertSame(0, Attachment::query()->count());

        // The conversation continues without the deleted file...
        $events = $this->events($this->actingAs($user)->post(route('ai.chat.stream'), ['message' => 'segunda', 'conversation_id' => $conversation]));
        $this->assertSame('calculated', collect($events)->firstWhere('type', 'summary')['cost_status']);
    }

    public function test_recordings_are_transcribed_with_their_own_cost_and_not_kept(): void
    {
        $this->simulatedModel();
        $transcriber = AiModel::query()->where('model', 'simulated-transcriber')->sole();
        $this->aiAgent(['microphone' => ['enabled' => true, 'transcription_model_id' => $transcriber->id, 'language' => 'es', 'max_seconds' => 60, 'auto_send' => false]]);

        $response = $this->actingAs($this->userWithRole('reader'))
            ->post(route('ai.transcriptions.store'), ['audio' => $this->wav(), 'duration' => 1.2])
            ->assertOk()
            ->assertJsonPath('cost_status', 'calculated');

        $this->assertStringContainsString('[Simulated transcription]', (string) $response->json('text'));

        $request = AiRequest::query()->sole();
        $this->assertSame(Feature::Transcription, $request->feature);
        $this->assertSame($transcriber->id, $request->model_id);
        $this->assertSame([], Storage::disk('local')->allFiles());

        Sanctum::actingAs($this->userWithRole('reader'), ['ai']);
        $this->post(route('api.v1.ai.transcriptions.store'), ['audio' => $this->realFile('a.wav', 'no es audio')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('audio');
    }

    public function test_the_microphone_must_be_enabled_in_the_agent(): void
    {
        $this->simulatedModel();
        $this->aiAgent();

        $this->actingAs($this->userWithRole('reader'))
            ->postJson(route('ai.transcriptions.store'), ['audio' => $this->wav()])
            ->assertUnprocessable();
    }

    public function test_new_conversations_are_named_by_the_model_through_the_gateway(): void
    {
        $model = $this->aiModel();
        $this->aiAgent(['execution' => ['model_id' => $model->id]]);
        ConfiguredAgent::fake([$this->fakeTextResponse('Hola, ¿en qué te ayudo?', 30, 10)]);
        TitleAgent::fake([$this->fakeTextResponse('"Saludo inicial."', 20, 4)]);

        $events = $this->events($this->actingAs($this->userWithRole('reader'))->post(route('ai.chat.stream'), ['message' => 'Hola, necesito ayuda con mi cuenta']));
        $conversation = collect($events)->firstWhere('type', 'summary')['conversation_id'];

        $this->assertSame('Saludo inicial', Conversation::query()->find($conversation)?->getAttribute('title'));
        $this->assertSame(1, AiRequest::query()->where('feature', Feature::Title->value)->count());
    }

    public function test_the_simulated_provider_keeps_a_title_from_the_first_message(): void
    {
        $this->simulatedModel();
        $this->aiAgent();

        $events = $this->events($this->actingAs($this->userWithRole('reader'))->post(route('ai.chat.stream'), ['message' => 'Quiero revisar el presupuesto del trimestre']));
        $conversation = collect($events)->firstWhere('type', 'summary')['conversation_id'];

        $this->assertSame('Quiero revisar el presupuesto del trimestre', Conversation::query()->find($conversation)?->getAttribute('title'));
        $this->assertSame(0, AiRequest::query()->where('feature', Feature::Title->value)->count());
    }
}
