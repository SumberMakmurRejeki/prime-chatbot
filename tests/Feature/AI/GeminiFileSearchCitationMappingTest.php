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
 * Sejak Gemini menandai setiap dokumen dengan `import_identity` (token generasi
 * impor, 'g' + 48 hex), sitasi hanya sah bila token anotasi sama persis dengan
 * token pada record indeksasi tepercaya. Token diuji di sini diambil dari bukti
 * live tersanitasi: `SOP-UPCAST-001` revisi 03 dan `SOP-FNC01-PM-001` revisi 01
 * adalah dua kandidat terbit, sedangkan token revisi historis 02 dan draft 04
 * berasal dari daftar identitas yang dikecualikan pada bukti yang sama.
 *
 * Bentuk anotasi mengikuti respons live yang terekam: `document_uri` berisi path
 * File Search Store, bukan path dokumen individual, sehingga kecocokan URI
 * membuktikan keanggotaan store dan pemisahan generasi impor disandarkan pada
 * `import_identity`.
 */
class GeminiFileSearchCitationMappingTest extends TestCase
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    /** URI provider sintetis berformat resource name Google File Search Store. */
    private const PROVIDER_URI = 'fileSearchStores/prime-test-store';

    private const OTHER_PROVIDER_URI = 'fileSearchStores/other-store-fnc02';

    /** Token identitas impor sintetis; bentuknya mengikuti 'g' + 48 hex. */
    private const PM_001_IMPORT_IDENTITY = 'g1a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f7081';

    private const PM_002_IMPORT_IDENTITY = 'g2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192';

    private const PM_003_IMPORT_IDENTITY = 'g3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3';

    private const PM_004_IMPORT_IDENTITY = 'g4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4';

    /** Identitas impor revisi terbit dari bukti live tersanitasi. */
    private const UPCAST_REV_03_IMPORT_IDENTITY = 'gf5ba8d1a725a95632a8c06ec0c7421f616ca10b0b9c153d6';

    private const FNC_01_REV_01_IMPORT_IDENTITY = 'ge57f9faab91546c0b71fcaa8cf01701738b6f776c607e674';

    /** Identitas milik revisi historis (02) dan draft (04) pada bukti yang sama. */
    private const UPCAST_HISTORICAL_IMPORT_IDENTITY = 'g7da5c9f3a345d25ce3906d18693b08aff272e911fc0a3f42';

    private const UPCAST_DRAFT_IMPORT_IDENTITY = 'ge1d67b926d4d5e2f917c716622d54192e565efaba046c008';

    /** Store sintetis untuk kasus berbasis bukti live; nama store tidak membedakan generasi impor. */
    private const EVIDENCE_STORE_URI = 'fileSearchStores/prime-synthetic-live-store';

    /** @var array<string, array{version: string, document_uri: string, import_identity: string}> */
    private const TRUSTED_DOCUMENTS = [
        'PM-001' => ['version' => '1.0', 'document_uri' => self::PROVIDER_URI, 'import_identity' => self::PM_001_IMPORT_IDENTITY],
        'PM-002' => ['version' => '1.1', 'document_uri' => self::PROVIDER_URI, 'import_identity' => self::PM_002_IMPORT_IDENTITY],
        'PM-003' => ['version' => '1.10', 'document_uri' => self::PROVIDER_URI, 'import_identity' => self::PM_003_IMPORT_IDENTITY],
        'PM-004' => ['version' => '1', 'document_uri' => self::PROVIDER_URI, 'import_identity' => self::PM_004_IMPORT_IDENTITY],
    ];

    /** @var array<string, array{version: string, document_uri: string, import_identity: string}> */
    private const LIVE_EVIDENCE_DOCUMENTS = [
        'SOP-UPCAST-001' => ['version' => '03', 'document_uri' => self::EVIDENCE_STORE_URI, 'import_identity' => self::UPCAST_REV_03_IMPORT_IDENTITY],
        'SOP-FNC01-PM-001' => ['version' => '01', 'document_uri' => self::EVIDENCE_STORE_URI, 'import_identity' => self::FNC_01_REV_01_IMPORT_IDENTITY],
    ];

    public function test_file_citation_matching_a_trusted_record_preserves_identity_uri_and_page_number(): void
    {
        $citation = (new KnowledgeCitationMapper)->map($this->fileCitation([
            'file_name' => 'PM-001-sop-pemeliharaan.pdf',
            'document_uri' => self::PROVIDER_URI,
            'source' => 'interval pemeriksaan 500 jam',
            'page_number' => 12,
            'custom_metadata' => $this->trustedMetadata('PM-001', '1.0'),
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
            'custom_metadata' => $this->trustedMetadata('PM-002', '1.1'),
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertSame('PM-002', $citation->matchedRecordCode);
        $this->assertNull($citation->pageNumber);
        $this->assertNull($citation->providerSource);
    }

    public function test_upcast_rev_03_citation_from_live_evidence_maps_with_its_authorized_identity(): void
    {
        $citation = (new KnowledgeCitationMapper)->map($this->fileCitation([
            'file_name' => 'awgkdtf6swyh',
            'document_uri' => self::EVIDENCE_STORE_URI,
            'source' => 'Record fictional inspection tag UP-COPPER-317 in the mock ledger.',
            'page_number' => 1,
            'custom_metadata' => [
                'version' => '03',
                'import_identity' => self::UPCAST_REV_03_IMPORT_IDENTITY,
                'document_code' => 'SOP-UPCAST-001',
            ],
        ]), self::LIVE_EVIDENCE_DOCUMENTS);

        $this->assertSame('SOP-UPCAST-001', $citation->matchedRecordCode);
        $this->assertSame('03', $citation->matchedRecordVersion);
        $this->assertSame(self::EVIDENCE_STORE_URI, $citation->documentUri);
        $this->assertSame(1, $citation->pageNumber);
        $this->assertSame('awgkdtf6swyh', $citation->providerFileName);
        $this->assertSame(
            'Record fictional inspection tag UP-COPPER-317 in the mock ledger.',
            $citation->providerSource,
        );
    }

    public function test_fnc_01_rev_01_citation_from_live_evidence_maps_with_its_authorized_identity(): void
    {
        $citation = (new KnowledgeCitationMapper)->map($this->fileCitation([
            'file_name' => '5eygvfhunvor',
            'document_uri' => self::EVIDENCE_STORE_URI,
            'source' => 'FNC-MINT-073',
            'page_number' => 2,
            'custom_metadata' => [
                'version' => '01',
                'import_identity' => self::FNC_01_REV_01_IMPORT_IDENTITY,
                'document_code' => 'SOP-FNC01-PM-001',
            ],
        ]), self::LIVE_EVIDENCE_DOCUMENTS);

        $this->assertSame('SOP-FNC01-PM-001', $citation->matchedRecordCode);
        $this->assertSame('01', $citation->matchedRecordVersion);
        $this->assertSame(self::EVIDENCE_STORE_URI, $citation->documentUri);
        $this->assertSame(2, $citation->pageNumber);
        $this->assertSame('FNC-MINT-073', $citation->providerSource);
    }

    public function test_citation_with_an_identity_of_another_record_is_rejected(): void
    {
        $mapper = new KnowledgeCitationMapper;

        // Kode dan versi sesuai dua kandidat terbit, tetapi token identitas
        // ditukar: identitas FNC-01 tidak boleh mengesahkan sitasi Upcast, dan
        // sebaliknya.
        $this->assertNull($mapper->map($this->fileCitation([
            'document_uri' => self::EVIDENCE_STORE_URI,
            'custom_metadata' => [
                'document_code' => 'SOP-UPCAST-001',
                'version' => '03',
                'import_identity' => self::FNC_01_REV_01_IMPORT_IDENTITY,
            ],
        ]), self::LIVE_EVIDENCE_DOCUMENTS));

        $this->assertNull($mapper->map($this->fileCitation([
            'document_uri' => self::EVIDENCE_STORE_URI,
            'custom_metadata' => [
                'document_code' => 'SOP-FNC01-PM-001',
                'version' => '01',
                'import_identity' => self::UPCAST_REV_03_IMPORT_IDENTITY,
            ],
        ]), self::LIVE_EVIDENCE_DOCUMENTS));
    }

    public function test_citation_with_an_identity_of_another_revision_is_rejected(): void
    {
        $mapper = new KnowledgeCitationMapper;

        // Token generasi historis (revisi 02) dan draft (revisi 04) tidak pernah
        // mengesahkan sitasi, bahkan ketika kode dan versi yang dilaporkan
        // provider cocok dengan revisi terbit.
        foreach ([self::UPCAST_HISTORICAL_IMPORT_IDENTITY, self::UPCAST_DRAFT_IMPORT_IDENTITY] as $identity) {
            $this->assertNull($mapper->map($this->fileCitation([
                'document_uri' => self::EVIDENCE_STORE_URI,
                'custom_metadata' => [
                    'document_code' => 'SOP-UPCAST-001',
                    'version' => '03',
                    'import_identity' => $identity,
                ],
            ]), self::LIVE_EVIDENCE_DOCUMENTS));
        }
    }

    public function test_citation_with_a_single_character_change_in_the_identity_is_rejected(): void
    {
        $citation = $this->fileCitation([
            'document_uri' => self::EVIDENCE_STORE_URI,
            'custom_metadata' => [
                'document_code' => 'SOP-UPCAST-001',
                'version' => '03',
                'import_identity' => self::UPCAST_REV_03_IMPORT_IDENTITY,
            ],
        ]);

        $this->assertNotNull((new KnowledgeCitationMapper)->map($citation, self::LIVE_EVIDENCE_DOCUMENTS));

        $citation['custom_metadata']['import_identity'] = substr(self::UPCAST_REV_03_IMPORT_IDENTITY, 0, -1).'0';

        $this->assertNull((new KnowledgeCitationMapper)->map($citation, self::LIVE_EVIDENCE_DOCUMENTS));
    }

    public function test_citation_without_an_import_identity_is_rejected(): void
    {
        $mapper = new KnowledgeCitationMapper;

        // Bentuk anotasi lama (tanpa import_identity) tidak lagi cukup: kode,
        // versi, dan URI yang cocok tetap ditolak bila generasi impor tidak
        // dapat dibuktikan.
        $this->assertNull($mapper->map($this->fileCitation([
            'file_name' => 'PM-001-sop-pemeliharaan.pdf',
            'document_uri' => self::PROVIDER_URI,
            'source' => 'interval pemeriksaan 500 jam',
            'page_number' => 12,
            'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0'],
        ]), self::TRUSTED_DOCUMENTS));
    }

    public function test_citation_with_an_empty_or_malformed_import_identity_is_rejected(): void
    {
        $mapper = new KnowledgeCitationMapper;

        foreach (['', '   ', self::PM_001_IMPORT_IDENTITY.' ', strtoupper(self::PM_001_IMPORT_IDENTITY), [self::PM_001_IMPORT_IDENTITY], 1, 1.0, true, null] as $identity) {
            $this->assertNull($mapper->map($this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => [
                    'document_code' => 'PM-001',
                    'version' => '1.0',
                    'import_identity' => $identity,
                ],
            ]), self::TRUSTED_DOCUMENTS));
        }
    }

    public function test_citation_is_rejected_when_the_trusted_record_carries_no_usable_identity(): void
    {
        $mapper = new KnowledgeCitationMapper;

        $annotation = $this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => [
                'document_code' => 'PM-001',
                'version' => '1.0',
                'import_identity' => self::PM_001_IMPORT_IDENTITY,
            ],
        ]);

        // Record tepercaya tanpa identitas impor (baris historis) atau dengan
        // jenis nilai yang salah tidak dapat mengesahkan sitasi apa pun.
        $unusableTrustedRecords = [
            ['version' => '1.0', 'document_uri' => self::PROVIDER_URI],
            ['version' => '1.0', 'document_uri' => self::PROVIDER_URI, 'import_identity' => ''],
            ['version' => '1.0', 'document_uri' => self::PROVIDER_URI, 'import_identity' => '   '],
            ['version' => '1.0', 'document_uri' => self::PROVIDER_URI, 'import_identity' => 1],
            ['version' => '1.0', 'document_uri' => self::PROVIDER_URI, 'import_identity' => null],
            ['version' => '1.0', 'document_uri' => self::PROVIDER_URI, 'import_identity' => [self::PM_001_IMPORT_IDENTITY]],
        ];

        foreach ($unusableTrustedRecords as $trustedRecord) {
            $this->assertNull($mapper->map($annotation, ['PM-001' => $trustedRecord]));
        }
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

    public function test_an_identity_shared_by_two_trusted_records_authorizes_nothing(): void
    {
        $mapper = new KnowledgeCitationMapper;
        $sharedIdentityDocuments = [
            'PM-001' => ['version' => '1.0', 'document_uri' => self::PROVIDER_URI, 'import_identity' => self::PM_001_IMPORT_IDENTITY],
            'PM-002' => ['version' => '1.1', 'document_uri' => self::PROVIDER_URI, 'import_identity' => self::PM_001_IMPORT_IDENTITY],
        ];

        foreach ($sharedIdentityDocuments as $documentCode => $trustedRecord) {
            $this->assertNull($mapper->map($this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => [
                    'document_code' => $documentCode,
                    'version' => $trustedRecord['version'],
                    'import_identity' => self::PM_001_IMPORT_IDENTITY,
                ],
            ]), $sharedIdentityDocuments));
        }
    }

    public function test_identity_that_is_not_a_canonical_indexing_token_is_rejected(): void
    {
        $mapper = new KnowledgeCitationMapper;
        $validIdentity = self::PM_001_IMPORT_IDENTITY;
        $malformedIdentities = [
            'g'.str_repeat('a', 47),
            'g'.str_repeat('a', 49),
            'g'.str_repeat('A', 48),
            'x'.str_repeat('a', 48),
            'g'.str_repeat('a', 47).'!',
        ];

        foreach ($malformedIdentities as $identity) {
            $this->assertNull($mapper->map($this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => [
                    'document_code' => 'PM-001',
                    'version' => '1.0',
                    'import_identity' => $identity,
                ],
            ]), self::TRUSTED_DOCUMENTS));
        }

        // Kontrak jalur impor PRIME menegakkan satu bentuk token ('g' + 48
        // hex), termasuk pada record tepercaya: peta tepercaya yang membawa
        // token karangan tidak boleh mengesahkan sitasi, bahkan ketika anotasi
        // memakai token karangan yang sama persis.
        foreach ($malformedIdentities as $identity) {
            $this->assertNull($mapper->map($this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => [
                    'document_code' => 'PM-001',
                    'version' => '1.0',
                    'import_identity' => $identity,
                ],
            ]), ['PM-001' => [
                'version' => '1.0',
                'document_uri' => self::PROVIDER_URI,
                'import_identity' => $identity,
            ]]));
        }

        // Basis pembanding tetap token kanonik: record tepercaya yang tidak
        // berbentuk token pengindeks PRIME tidak mengesahkan apa pun.
        foreach ($malformedIdentities as $identity) {
            $this->assertNull($mapper->map($this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => [
                    'document_code' => 'PM-001',
                    'version' => '1.0',
                    'import_identity' => $validIdentity,
                ],
            ]), ['PM-001' => [
                'version' => '1.0',
                'document_uri' => self::PROVIDER_URI,
                'import_identity' => $identity,
            ]]));
        }
    }

    public function test_file_citation_without_a_provider_uri_is_rejected(): void
    {
        $mapper = new KnowledgeCitationMapper;

        $missingUri = $this->fileCitation([
            'file_name' => 'PM-001-sop-pemeliharaan.pdf',
            'custom_metadata' => $this->trustedMetadata('PM-001', '1.0'),
        ]);

        $emptyUri = $this->fileCitation([
            'document_uri' => '',
            'custom_metadata' => $this->trustedMetadata('PM-001', '1.0'),
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
            'custom_metadata' => $this->trustedMetadata('PM-002', '1.1'),
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertSame('PM-002', $stringPage->matchedRecordCode);
        $this->assertNull($stringPage->pageNumber);

        foreach ([0, -3, 12.5] as $pageNumber) {
            $nonPositive = $mapper->map($this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'page_number' => $pageNumber,
                'custom_metadata' => $this->trustedMetadata('PM-002', '1.1'),
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
                'custom_metadata' => ['document_code' => ['PM-001'], 'version' => '1.0', 'import_identity' => self::PM_001_IMPORT_IDENTITY],
            ]),
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => ['document_code' => '', 'version' => '1.0', 'import_identity' => self::PM_001_IMPORT_IDENTITY],
            ]),
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => ['document_code' => 'PM-001'],
            ]),
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => ['document_code' => 'PM-001', 'version' => '', 'import_identity' => self::PM_001_IMPORT_IDENTITY],
            ]),
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0', 'import_identity' => ''],
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
            'custom_metadata' => ['document_code' => 'PM-999', 'version' => '1.0', 'import_identity' => self::PM_001_IMPORT_IDENTITY],
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertNull($citation);
    }

    public function test_file_citation_for_a_version_other_than_the_published_version_is_rejected(): void
    {
        $citation = (new KnowledgeCitationMapper)->map($this->fileCitation([
            'file_name' => 'PM-001-sop-pemeliharaan-v1.1.pdf',
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => $this->trustedMetadata('PM-001', '1.1'),
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertNull($citation);
    }

    public function test_string_versions_are_compared_verbatim_without_numeric_normalization(): void
    {
        $mapper = new KnowledgeCitationMapper;

        $verbatim = $mapper->map($this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => $this->trustedMetadata('PM-003', '1.10'),
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertSame('1.10', $verbatim->matchedRecordVersion);

        // "1.1" = 1.10 secara numerik, tetapi bukan versi kanonik yang sama.
        $this->assertNull($mapper->map($this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => $this->trustedMetadata('PM-003', '1.1'),
        ]), self::TRUSTED_DOCUMENTS));

        // "1.0" tidak boleh dinormalisasi menjadi "1", dan sebaliknya.
        $this->assertNull($mapper->map($this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => $this->trustedMetadata('PM-001', '1'),
        ]), self::TRUSTED_DOCUMENTS));

        $this->assertNull($mapper->map($this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => $this->trustedMetadata('PM-004', '1.0'),
        ]), self::TRUSTED_DOCUMENTS));

        $this->assertNull($mapper->map($this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => $this->trustedMetadata('PM-004', '1.00'),
        ]), self::TRUSTED_DOCUMENTS));
    }

    public function test_numeric_provider_version_is_rejected_instead_of_being_cast(): void
    {
        $mapper = new KnowledgeCitationMapper;

        foreach ([1, 1.0] as $numericVersion) {
            $this->assertNull($mapper->map($this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => [
                    'document_code' => 'PM-004',
                    'version' => $numericVersion,
                    'import_identity' => self::PM_004_IMPORT_IDENTITY,
                ],
            ]), self::TRUSTED_DOCUMENTS));
        }
    }

    public function test_file_citation_from_another_provider_uri_is_rejected(): void
    {
        $mapper = new KnowledgeCitationMapper;

        $otherStore = $mapper->map($this->fileCitation([
            'file_name' => 'PM-001-sop-pemeliharaan.pdf',
            'document_uri' => self::OTHER_PROVIDER_URI,
            'custom_metadata' => $this->trustedMetadata('PM-001', '1.0'),
        ]), self::TRUSTED_DOCUMENTS);

        // Store sintetis lain pada kasus berbasis bukti live juga tidak sah.
        $otherEvidenceStore = $mapper->map($this->fileCitation([
            'document_uri' => self::PROVIDER_URI,
            'custom_metadata' => [
                'document_code' => 'SOP-UPCAST-001',
                'version' => '03',
                'import_identity' => self::UPCAST_REV_03_IMPORT_IDENTITY,
            ],
        ]), self::LIVE_EVIDENCE_DOCUMENTS);

        $this->assertNull($otherStore);
        $this->assertNull($otherEvidenceStore);
    }

    public function test_file_citation_with_a_document_level_uri_does_not_match_a_store_level_trusted_uri(): void
    {
        $citation = (new KnowledgeCitationMapper)->map($this->fileCitation([
            'file_name' => 'PM-001-sop-pemeliharaan.pdf',
            'document_uri' => self::PROVIDER_URI.'/documents/derived-doc',
            'custom_metadata' => $this->trustedMetadata('PM-001', '1.0'),
        ]), self::TRUSTED_DOCUMENTS);

        $this->assertNull($citation);
    }

    public function test_annotation_that_is_not_a_file_citation_is_rejected(): void
    {
        $mapper = new KnowledgeCitationMapper;

        $this->assertNull($mapper->map([
            'type' => 'url_citation',
            'url' => 'https://example.test/knowledge/pm-001',
            'title' => 'PM-001',
        ], self::TRUSTED_DOCUMENTS));

        // Sitasi legacy tanpa metadata provider tetap ditolak meskipun record
        // tepercaya yang kaya identitas diberikan pemanggil.
        $this->assertNull($mapper->map([
            'type' => 'url_citation',
            'url' => 'https://example.test/knowledge/pm-001',
            'title' => 'PM-001',
            'custom_metadata' => $this->trustedMetadata('PM-001', '1.0'),
        ], self::TRUSTED_DOCUMENTS));
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
                            'custom_metadata' => $this->trustedMetadata('PM-002', '1.1'),
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
                'custom_metadata' => $this->trustedMetadata('PM-001', '1.0'),
            ]),
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'custom_metadata' => $this->trustedMetadata('PM-002', '1.1'),
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
                'custom_metadata' => $this->trustedMetadata('PM-001', '1.0'),
            ]),
            $this->fileCitation([
                'file_name' => 'sop-b.pdf',
                'document_uri' => self::PROVIDER_URI,
                'page_number' => 7,
                'custom_metadata' => $this->trustedMetadata('PM-001', '1.0'),
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
            'custom_metadata' => $this->trustedMetadata('PM-001', '1.0'),
        ]);

        $citations = $mapper->mapAll([
            $page12,
            'bukti-rusak',
            null,
            ['type' => 'file_citation', 'file_name' => ['unexpected' => 'shape']],
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'page_number' => 14,
                'custom_metadata' => $this->trustedMetadata('PM-001', '1.0'),
            ]),
            $this->fileCitation([
                'document_uri' => self::PROVIDER_URI,
                'page_number' => 14,
                'custom_metadata' => ['document_code' => 'PM-001', 'version' => '1.0', 'import_identity' => self::PM_002_IMPORT_IDENTITY],
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
     * Metadata anotasi yang cocok dengan record tepercaya: token identitas impor
     * dibaca dari peta tepercaya agar kasus positif selalu sejalan dengannya.
     *
     * @return array{document_code: string, version: string, import_identity: string}
     */
    private function trustedMetadata(string $documentCode, string $version): array
    {
        return [
            'document_code' => $documentCode,
            'version' => $version,
            'import_identity' => self::TRUSTED_DOCUMENTS[$documentCode]['import_identity'],
        ];
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
