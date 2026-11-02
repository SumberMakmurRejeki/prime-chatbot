<?php

namespace Tests\Feature\AI;

use App\Exceptions\DocumentAnswerUnavailable;
use App\Models\AiProviderConfiguration;
use App\Services\AI\DocumentGroundedAnswerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DocumentGroundedAnswerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_unexpected_inner_failure_is_wrapped_while_retaining_the_original_exception(): void
    {
        AiProviderConfiguration::query()->where('purpose', 'chat')->delete();

        try {
            app(DocumentGroundedAnswerService::class)->answer('Pertanyaan uji');
            $this->fail('An unexpected inner failure must convert to DocumentAnswerUnavailable.');
        } catch (DocumentAnswerUnavailable $exception) {
            $this->assertSame(
                'Assistant tidak dapat menjawab saat ini. Periksa konfigurasi Chat Provider dan layanan Knowledge Base, lalu coba lagi.',
                $exception->getMessage(),
            );
            $previous = $exception->getPrevious();
            $this->assertInstanceOf(RuntimeException::class, $previous);
            $this->assertSame('No unique eligible active chat provider is available.', $previous->getMessage());
            $this->assertNull($previous->getPrevious());
        }

        Http::assertNothingSent();
    }
}
