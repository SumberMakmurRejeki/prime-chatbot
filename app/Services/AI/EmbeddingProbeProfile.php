<?php

namespace App\Services\AI;

/**
 * Profil probe tetap dan berversi untuk membuktikan karakter embedder.
 *
 * Teks probe hanya berisi kalimat umum non-bisnis, sehingga bukti referensi
 * dapat disimpan tanpa membawa data operasional PRIME. Mengubah teks probe
 * wajib menaikkan VERSION; evaluator menolak bukti dengan profil/versi yang
 * tidak dikenal daripada diam-diam membandingkan vektor dari input berbeda.
 */
final class EmbeddingProbeProfile
{
    public const PROFILE = 'prime-embedding-probe';

    public const VERSION = 1;

    /** @var array<string, string> */
    private const PROBES = [
        'semantic-short' => 'Centrifugal pump bearing replacement with shaft alignment check.',
        'numeric-and-punctuation' => 'Torque 45 Nm; clearance 0.05 mm; pressure 2.5 bar at 60 degC.',
        'semantic-long' => 'When a technician reports abnormal vibration on a rotating machine, verify coupling alignment, inspect bearing condition, confirm lubrication level, and record the vibration amplitude before restarting the equipment.',
        'unicode-bahasa' => 'Pompa sentrifugal dijaga dengan pelumasan; periksa kelurusan poros dan getaran bantalan.',
    ];

    /** @return array<string, string> */
    public static function probes(): array
    {
        return self::PROBES;
    }

    /** @return list<string> */
    public static function texts(): array
    {
        return array_values(self::PROBES);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::PROBES);
    }

    /**
     * Sidik jari profil, dipakai agar bukti tidak dapat dicocokkan dengan
     * definisi probe yang berbeda.
     */
    public static function fingerprint(): string
    {
        return hash('sha256', EmbeddingEvidenceCodec::canonicalJson([
            'profile' => self::PROFILE,
            'version' => self::VERSION,
            'probes' => self::PROBES,
        ]));
    }
}
