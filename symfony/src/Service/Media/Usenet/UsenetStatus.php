<?php

namespace App\Service\Media\Usenet;

/**
 * Canonical normalized status set for Usenet downloads, plus the mappers that
 * fold each downloader's vocabulary into it. Keeps the status strings in one
 * place so the templates and the badge logic key off a stable set.
 */
final class UsenetStatus
{
    public const DOWNLOADING = 'downloading';
    public const QUEUED      = 'queued';
    public const FETCHING    = 'fetching';     // grabbing the NZB from a URL
    public const PAUSED      = 'paused';
    public const VERIFYING   = 'verifying';   // par2 check / repair
    public const EXTRACTING  = 'extracting';  // unpack
    public const MOVING      = 'moving';      // post-proc / scripts
    public const COMPLETED   = 'completed';
    public const FAILED      = 'failed';
    public const UNKNOWN     = 'unknown';

    /** SABnzbd queue/history `status` strings → canonical. */
    public static function fromSabnzbd(string $status): string
    {
        return match (strtolower($status)) {
            'downloading'            => self::DOWNLOADING,
            'queued'                 => self::QUEUED,
            'grabbing', 'fetching'   => self::FETCHING,
            'paused'                 => self::PAUSED,
            'checking', 'verifying', 'repairing' => self::VERIFYING,
            'extracting', 'unpacking'            => self::EXTRACTING,
            'moving', 'running'                  => self::MOVING,
            'completed'              => self::COMPLETED,
            'failed'                 => self::FAILED,
            default                  => self::UNKNOWN,
        };
    }

    /**
     * NZBGet has two status fields. Queue items carry a `Status` like
     * DOWNLOADING / PAUSED / QUEUED / FETCHING; history items carry both a
     * `Status` (SUCCESS/FAILURE/DELETED) and a finer `ScriptStatus`. This maps
     * the queue-side `Status`.
     */
    public static function fromNzbgetQueue(string $status): string
    {
        return match (strtoupper($status)) {
            'DOWNLOADING'                 => self::DOWNLOADING,
            'FETCHING'                    => self::FETCHING,
            'QUEUED', 'PP_QUEUED'         => self::QUEUED,
            'PAUSED'                      => self::PAUSED,
            'LOADING_PARS', 'VERIFYING_SOURCES', 'VERIFYING_REPAIRED', 'VERIFYING', 'REPAIRING'
                                          => self::VERIFYING,
            'UNPACKING'                   => self::EXTRACTING,
            'RENAMING', 'MOVING', 'POST_PROCESSING', 'EXECUTING_SCRIPT', 'PP_FINISHED'
                                          => self::MOVING,
            default                       => self::UNKNOWN,
        };
    }

    /** NZBGet history `Status` (e.g. "SUCCESS/ALL", "FAILURE/PAR", "DELETED/MANUAL"). */
    public static function fromNzbgetHistory(string $status): string
    {
        $head = strtoupper(explode('/', $status)[0] ?? '');
        return match ($head) {
            'SUCCESS'  => self::COMPLETED,
            'WARNING'  => self::COMPLETED,
            'FAILURE'  => self::FAILED,
            'DELETED'  => self::FAILED,
            default    => self::UNKNOWN,
        };
    }
}
