<?php

namespace Tests\Feature\AI;

use App\Services\AI\KnowledgeCitationMapper;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\Gemini\GeminiGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\Tools\FileSearch;
use Tests\TestCase;

/**
 * Batas aplikasi PRIME untuk memetakan anotasi `file_citation` Gemini File Search
 * ke record dokumen pengetahuan yang terbit. Pemeta diuji di sini adalah kode
 * produksi App\Services\AI\KnowledgeCitationMapper; integrasi runtime chatbot
 * belum ada. SDK laravel/ai v1.2.0 hanya memetakan `url_citation`, sehingga
 * anotasi `file_citation` hanya terbaca dari payload mentah respons.
 *
 * Bentuk anotasi mengikuti respons Gemini File Search yang terekam di sandbox
 * PRIME: `document_uri` berisi path File Search Store, bukan path dokumen
 * individual. Kontrak di sini tidak membuktikan file individual mana yang dikutip.
 */
class GeminiFileSearchCitationMappingTest extends TestCase
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    /** URI provider sintetis berformat resource name Google File Search Store. */
    private const PROVIDER_URI = 'fileSearchStores/prime-test-store';

    private const OTHER_PROVIDER_URI = 'fileSearchStores/other-store-fnc02';

    /** @var array<string, array{version: string, document_uri: string}> */
    private const TRUSTED_DOCUMENTS = [
        'PM-001' => ['version' => '1.0', 'document_uri' => self::PROVIDER_URI],
        'PM-002' => ['version' => '1.1', 'document_uri' => self::PROVIDER_URI],
        'PM-003' => ['version' => '1.10', 'document_uri' => self::PROVIDER_URI],
        'PM-004' => ['version' => '1', 'document_uri' => self::PROVIDER_URI],
    ];

    public function test_file_citation_matching_a_trusted_record_preserves_identity_uri_and_page_number(): void
    {
        $citation = (new KnowledgeCitationMapper)->map($this->fileCitation([
            'file_name' => 'PM-001-sop-pemeliharaan.pdf',
            'document_uri' => self::PROVIDER_URI,
            'source' => 'interval pemeriksaan 500 jam',
            'page_number' => 12,
            'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0'],
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertSame('PM-001', $citation->matchedRecordCode);
        $this->assertSame('1.0', $citation->matchedRecordVersion);
        $this->assertSame(self::PROVIDER_URI, $citation->documentUri);
        $this->assertSame(12, $citation->pageNumber);
        $this->assertSame('PM-001-sop-pemeliharaan.pdf', $citation->providerFileName);
        $this->assertSame('interval pemeriksaan 500 jam', $citation->providerSource);
    }

    public function test_file_citation_without_page_number_keeps_a_null_page(): void
    {
        $citation = (new KnowledgeCitationMapper)->map($this->fileCitation([
            'file_name' => 'PM-002-instruksi-kerja.pdf',
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => ['document_code' => 'PM-002', 'version' => '1.1'],
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertSame('PM-002', $citation->matchedRecordCode);
        $this->assertNull($citation->pageNumber);
        $this->assertNull($citation->providerSource);
    }

    public function test_file_citation_without_custom_metadata_document_code_is_rejected(): void
    {
        $citation = (new KnowledgeCitationMapper)->map($this->fileCitation([
            'file_name' => 'PM-001-sop-pemeliharaan.pdf',
            'document_uri' => self::PROVIDER_URI,
            'source' => 'interval pemeriksaan 500 jam',
            'page_number' => 12,
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertNull($citation);
    }

    public function test_file_citation_without_a_provider_uri_is_rejected(): void
    {
        $mapper = new KnowledgeCitationMapper;

        $missingUri = $this->fileCitation([
            'file_name' => 'PM-001-sop-pemeliharaan.pdf',
            'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0'],
        ]);

        $emptyUri = $this->fileCitation([
            'document_uri' => '',
            'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0'],
        ]);

        $this->assertNull($mapper->map($missingUri, self::TRUSTED_DOCUMENTS));
        $this->assertNull($mapper->map($emptyUri, self::TRUSTED_DOCUMENTS));
    }

    public function test_file_citation_with_a_non_integer_page_number_maps_without_a_page(): void
    {
        $mapper = new KnowledgeCitationMapper;

        $stringPage = $mapper->map($this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'page_number' => '12',
            'custom_metadata' => ['document_code' => 'PM-002', 'version' => '1.1'],
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertSame('PM-002', $stringPage->matchedRecordCode);
        $this->assertNull($stringPage->pageNumber);

        foreach ([0, -3, 12.5] as $pageNumber) {
            $nonPositive = $mapper->map($this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'page_number' => $pageNumber,
                'custom_metadata' => ['document_code' => 'PM-002', 'version' => '1.1'],
            ]), self::TRUSTED_DOCUMENTS);

            $this->assertSame('PM-002', $nonPositive->matchedRecordCode);
            $this->assertNull($nonPositive->pageNumber);
        }
    }

    public function test_file_citation_with_malformed_metadata_is_rejected(): void
    {
        $mapper = new KnowledgeCitationMapper;

        $annotations = [
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => 'document_code=PM-001',
            ]),
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => ['document_code' => ['PM-001'], 'version' => '1.0'],
            ]),
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => ['document_code' => '', 'version' => '1.0'],
            ]),
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => ['document_code' => 'PM-001'],
            ]),
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => ['document_code' => 'PM-001', 'version' => ''],
            ]),
        ];

        foreach ($annotations as $annotation) {
            $this->assertNull($mapper->map($annotation, self::TRUSTED_DOCUMENTS));
        }
    }

    public function test_file_citation_for_a_record_outside_the_published_set_is_rejected(): void
    {
        $citation = (new KnowledgeCitationMapper)->map($this->fileCitation([
            'file_name' => 'draft-internal.pdf',
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => ['document_code' => 'PM-999', 'version' => '1.0'],
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertNull($citation);
    }

    public function test_file_citation_for_a_version_other_than_the_published_version_is_rejected(): void
    {
        $citation = (new KnowledgeCitationMapper)->map($this->fileCitation([
            'file_name' => 'PM-001-sop-pemeliharaan-v1.1.pdf',
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.1'],
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertNull($citation);
    }

    public function test_string_versions_are_compared_verbatim_without_numeric_normalization(): void
    {
        $mapper = new KnowledgeCitationMapper;

        $verbatim = $mapper->map($this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => ['document_code' => 'PM-003', 'version' => '1.10'],
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertSame('1.10', $verbatim->matchedRecordVersion);

        // "1.1" = 1.10 secara numerik, tetapi bukan versi kanonik yang sama.
        $this->assertNull($mapper->map($this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => ['document_code' => 'PM-003', 'version' => '1.1'],
        ]), self::TRUSTED_DOCUMENTS));

        // "1.0" tidak boleh dinormalisasi menjadi "1", dan sebaliknya.
        $this->assertNull($mapper->map($this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1'],
        ]), self::TRUSTED_DOCUMENTS));

        $this->assertNull($mapper->map($this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => ['document_code' => 'PM-004', 'version' => '1.0'],
        ]), self::TRUSTED_DOCUMENTS));

        $this->assertNull($mapper->map($this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => ['document_code' => 'PM-004', 'version' => '1.00'],
        ]), self::TRUSTED_DOCUMENTS));
    }

    public function test_numeric_provider_version_is_rejected_instead_of_being_cast(): void
    {
        $mapper = new KnowledgeCitationMapper;

        foreach ([1, 1.0] as $numericVersion) {
            $this->assertNull($mapper->map($this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => ['document_code' => 'PM-004', 'version' => $numericVersion],
            ]), self::TRUSTED_DOCUMENTS));
        }
    }

    public function test_file_citation_from_another_provider_uri_is_rejected(): void
    {
        $citation = (new KnowledgeCitationMapper)->map($this->fileCitation([
            'file_name' => 'PM-001-sop-pemeliharaan.pdf',
            'document_uri' => self::OTHER_PROVIDER_URI,
            'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0'],
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertNull($citation);
    }

    public function test_file_citation_with_a_document_level_uri_does_not_match_a_store_level_trusted_uri(): void
    {
        $citation = (new KnowledgeCitationMapper)->map($this->fileCitation([
            'file_name' => 'PM-001-sop-pemeliharaan.pdf',
            'document_uri' => self::PROVIDER_URI.'/documents/derived-doc',
            'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0'],
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertNull($citation);
    }

    public function test_annotation_that_is_not_a_file_citation_is_rejected(): void
    {
        $citation = (new KnowledgeCitationMapper)->map([
            'type' => 'url_citation',
            'url' => 'https://example.test/knowledge/pm-001',
            'title' => 'PM-001',
        ], self::TRUSTED_DOCUMENTS);

        $this->assertNull($citation);
    }

    public function test_mapper_reads_the_file_citation_only_through_the_raw_gemini_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL.'/interactions' => Http::response([
            'status' => 'completed',
            'steps' => [[
                'type' => 'model_output',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Interval pemeriksaan 500 jam.',
                    'annotations' => [
                        $this->fileCitation([
                            'file_name' => 'PM-002-instruksi-kerja.pdf',
                            'document_uri' => self::PROVIDER_URI,
                            'source' => 'interval pemeriksaan 500 jam',
                            'page_number' => 12,
                            'custom_metadata' => ['document_code' => 'PM-002', 'version' => '1.1'],
                        ]),
                    ],
                ]],
            ]],
        ])]);

        $provider = $this->geminiProvider();

        $step = $provider->textGateway()->generateTextStep(
            $provider,
            'gemini-3.8-flash',
            null,
            [new UserMessage('Bagaimana interval pemeriksaan mesin?')],
            [new FileSearch(['knowledge-published'])],
            null,
            null,
            30,
            new StepContext,
        );

        $this->assertCount(0, $step->meta->citations);

        $citation = (new KnowledgeCitationMapper)->map(
            $step->raw->json('steps.0.content.0.annotations.0'),
            self::TRUSTED_DOCUMENTS,
        );

        $this->assertSame('PM-002', $citation->matchedRecordCode);
        $this->assertSame('1.1', $citation->matchedRecordVersion);
        $this->assertSame(self::PROVIDER_URI, $citation->documentUri);
        $this->assertSame(12, $citation->pageNumber);
    }

    public function test_two_records_sharing_one_provider_uri_keep_their_own_matched_identity(): void
    {
        $citations = (new KnowledgeCitationMapper)->mapAll([
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0'],
            ]),
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => ['document_code' => 'PM-002', 'version' => '1.1'],
            ]),
        ], self::TRUSTED_DOCUMENTS);

        $this->assertSame(
            ['PM-001', 'PM-002'],
            array_map(static fn ($citation): string => $citation->matchedRecordCode, $citations),
        );
        $this->assertSame(
            [self::PROVIDER_URI, self::PROVIDER_URI],
            array_map(static fn ($citation): string => $citation->documentUri, $citations),
        );
    }

    public function test_two_files_with_identical_custom_metadata_in_one_store_collapse_to_one_citation(): void
    {
        $citations = (new KnowledgeCitationMapper)->mapAll([
            $this->fileCitation([
                'file_name' => 'sop-a.pdf',
                'document_uri' => self::PROVIDER_URI,
                'page_number' => 7,
                'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0'],
            ]),
            $this->fileCitation([
                'file_name' => 'sop-b.pdf',
                'document_uri' => self::PROVIDER_URI,
                'page_number' => 7,
                'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0'],
            ]),
        ], self::TRUSTED_DOCUMENTS);

        $this->assertCount(1, $citations);
        $this->assertSame('PM-001', $citations[0]->matchedRecordCode);
        $this->assertSame(self::PROVIDER_URI, $citations[0]->documentUri);
    }

    public function test_malformed_annotations_are_skipped_and_duplicate_citations_are_collapsed(): void
    {
        $mapper = new KnowledgeCitationMapper;

        $page12 = $this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'page_number' => 12,
            'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0'],
        ]);

        $citations = $mapper->mapAll([
            $page12,
            'bukti-rusak',
            null,
            ['type' => 'file_citation', 'file_name' => ['unexpected' => 'shape']],
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'page_number' => 14,
                'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0'],
            ]),
            $page12,
        ], self::TRUSTED_DOCUMENTS);

        $this->assertCount(2, $citations);
        $this->assertSame([12, 14], array_map(static fn ($citation): ?int => $citation->pageNumber, $citations));
    }

    public function test_empty_citation_collection_maps_to_no_citations(): void
    {
        $this->assertSame([], (new KnowledgeCitationMapper)->mapAll([], self::TRUSTED_DOCUMENTS));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function fileCitation(array $fields): array
    {
        return ['type' => 'file_citation'] + $fields;
    }

    private function geminiProvider(): GeminiProvider
    {
        $events = new Dispatcher;

        return new GeminiProvider(
            new GeminiGateway($events),
            [
                'driver' => 'gemini',
                'name' => 'gemini',
                'key' => 'test-api-key',
                'url' => self::BASE_URL.'/',
            ],
            $events,
        );
    }
}
