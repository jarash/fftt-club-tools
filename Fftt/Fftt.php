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
}