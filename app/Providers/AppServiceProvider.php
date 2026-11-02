<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AI\DocumentIngestion\PdfIngestionConfig;
use App\Services\AI\DocumentIngestion\PdfIngestionService;
use App\Services\AI\DocumentIngestion\PdfTextExtractor;
use App\Services\AI\DocumentIngestion\TextChunker;
use App\Services\AI\DocumentIngestion\TextNormalizer;
use App\Services\AI\VectorStore\Qdrant\QdrantCollectionInspector;
use App\Services\AI\VectorStore\Qdrant\QdrantConfig;
use App\Services\AI\VectorStore\Qdrant\QdrantVectorStore;
use App\Services\AI\VectorStore\VectorStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VectorStore::class, QdrantVectorStore::class);
        // Layanan kesiapan hanya butuh kemampuan baca collection, bukan seluruh
        // kemampuan mutasi vector store.
        $this->app->bind(QdrantCollectionInspector::class, QdrantVectorStore::class);
        $this->app->bind(QdrantConfig::class, fn (Application $app): QdrantConfig => QdrantConfig::fromArray(
            $app['config']->get('qdrant', []),
        ));

        $this->app->bind(PdfIngestionConfig::class, fn (Application $app): PdfIngestionConfig => PdfIngestionConfig::fromArray(
            $app['config']->get('ai.document_ingestion', []),
        ));

        $this->app->bind(PdfIngestionService::class, fn (Application $app): PdfIngestionService => new PdfIngestionService(
            new PdfTextExtractor($app->make(PdfIngestionConfig::class)),
            new TextNormalizer,
            new TextChunker,
            $app->make(PdfIngestionConfig::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage_ai', static fn (?User $user): bool => $user instanceof User
            && $user->is_active
            && $user->role === 'admin'
            && $user->manage_ai);
    }
}
