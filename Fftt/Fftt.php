<?php

if (!defined('FFTT_CLUB_TOOLS_FFTT_API_CLASS_CANDIDATES')) {
    define('FFTT_CLUB_TOOLS_FFTT_API_CLASS_CANDIDATES', [
        'Jarash\\FfttApi\\Service\\FFTTApi',
        'Alamirault\\FFTTApi\\Service\\FFTTApi',
    ]);
}

if (!function_exists('fftt_club_tools_resolve_fftt_api_class')) {
    function fftt_club_tools_resolve_fftt_api_class(): ?string
    {
        foreach (FFTT_CLUB_TOOLS_FFTT_API_CLASS_CANDIDATES as $className) {
            if (class_exists($className)) {
                return $className;
            }
        }

        return null;
    }
}

if (fftt_club_tools_resolve_fftt_api_class() === null) {
    $autoloadCandidates = [
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(__DIR__, 4) . '/vendor/autoload.php',
        dirname(__DIR__, 5) . '/vendor/autoload.php',
    ];

    if (defined('ABSPATH')) {
        $autoloadCandidates[] = ABSPATH . 'vendor/autoload.php';
    }

    foreach ($autoloadCandidates as $autoloadPath) {
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
            break;
        }
    }
}

class FfttService
{
    private const CACHE_KEY_PREFIX = 'fftt_ct_api_';

    public $ffttApi;
    public $clubId;
    private string $ffttApiClass;

    public function __construct(
        string $apiLogin,
        string $apiPassword,
        string $teamId,
    )
    {
        $resolvedClass = fftt_club_tools_resolve_fftt_api_class();
        if ($resolvedClass === null) {
            throw new RuntimeException('Classe FFTTApi introuvable. Vérifier l\'autoload Composer.');
        }

        $this->ffttApiClass = $resolvedClass;

        $this->ffttApi = new $this->ffttApiClass($apiLogin, $apiPassword);
        $this->clubId = $teamId;
    }

    private static function getCacheTtl(): int
    {
        $ttl = (int) get_option('fftt_club_tools_api_cache_ttl', 3600);
        $ttl = (int) apply_filters('fftt_club_tools_api_cache_ttl', $ttl);
        if ($ttl < 0) {
            $ttl = 0;
        }

        return $ttl;
    }

    public static function getCacheKeyPrefix(): string
    {
        return self::CACHE_KEY_PREFIX;
    }

    public static function clearApiCache(): int
    {
        global $wpdb;

        if (!isset($wpdb) || !property_exists($wpdb, 'options')) {
            return 0;
        }

        $prefix = self::getCacheKeyPrefix();
        $transientPrefix = $wpdb->esc_like('_transient_' . $prefix) . '%';
        $timeoutPrefix = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';

        $deletedValues = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $transientPrefix
            )
        );

        $deletedTimeouts = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $timeoutPrefix
            )
        );

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        return max(0, $deletedValues + $deletedTimeouts);
    }

    private static function getCacheKey(string $group, array $payload = []): string
    {
        $payload['group'] = $group;
        $payload['blog'] = (int) get_current_blog_id();

        return self::CACHE_KEY_PREFIX . md5((string) wp_json_encode($payload));
    }

    private function remember(string $group, array $payload, callable $resolver)
    {
        $ttl = self::getCacheTtl();
        if ($ttl <= 0) {
            return $resolver();
        }

        $cacheKey = self::getCacheKey($group, $payload);
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $value = $resolver();
        set_transient($cacheKey, $value, $ttl);

        return $value;
    }

    public function listJoueursByClubCached(): array
    {
        return (array) $this->remember(
            'players-list-by-club',
            [
                'club' => (string) $this->clubId,
            ],
            fn() => $this->ffttApi->listJoueursByClub($this->clubId)
        );
    }

    public function retrieveJoueurDetailsCached(string $licenceId)
    {
        return $this->remember(
            'player-details',
            [
                'club' => (string) $this->clubId,
                'licence' => $licenceId,
            ],
            fn() => $this->ffttApi->retrieveJoueurDetails($licenceId)
        );
    }

    public function retrieveVirtualPointsCached(string $licenceId)
    {
        return $this->remember(
            'player-virtual-points',
            [
                'club' => (string) $this->clubId,
                'licence' => $licenceId,
            ],
            fn() => $this->ffttApi->retrieveVirtualPoints($licenceId)
        );
    }

    public function listPartiesJoueurByLicenceCached(string $licenceId): array
    {
        return (array) $this->remember(
            'player-matches',
            [
                'club' => (string) $this->clubId,
                'licence' => $licenceId,
            ],
            fn() => $this->ffttApi->listPartiesJoueurByLicence($licenceId)
        );
    }

    public function listEquipesByClubCached(): array
    {
        return (array) $this->remember(
            'teams-list-by-club',
            [
                'club' => (string) $this->clubId,
            ],
            fn() => $this->ffttApi->listEquipesByClub($this->clubId)
        );
    }

    public function listEquipePouleByLienDivisionCached(string $divisionLink): array
    {
        return (array) $this->remember(
            'pool-teams-by-division-link',
            [
                'club' => (string) $this->clubId,
                'division_link' => $divisionLink,
            ],
            fn() => $this->ffttApi->listEquipePouleByLienDivision($divisionLink)
        );
    }
}