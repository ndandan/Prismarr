<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wokeometer integration — local mirror of the Wokeometer catalog
 * (`wokeometer_media`) plus a single-row sync-state table
 * (`wokeometer_sync_state`: watermark, run lock, resumable cursor,
 * idempotency key, stats).
 *
 * Sync state lives here rather than in `setting` so a settings export/import
 * can never carry a foreign watermark or a stale lock onto another install,
 * and rather than in `cache.app` because that pool is wiped on every
 * settings save.
 *
 * Fresh and existing installs both run this; there is nothing to migrate
 * from. The state row (id = 1) is seeded here; the repository also
 * `INSERT OR IGNORE`s it (tests build the schema via SchemaTool, which never
 * runs this seed, and it self-heals a hand-deleted row).
 *
 * The sync-state PK is written as a table constraint (`PRIMARY KEY (id)`),
 * exactly as SchemaTool emits it for the non-generated entity id. Note:
 * `doctrine:schema:update --dump-sql` lists a rebuild of this table even on
 * a SchemaTool-built database (DBAL's SQLite introspection reports any
 * single INTEGER primary key as autoincrement) — a comparator false
 * positive, not drift.
 *
 * The column list must stay identical to the ORM mapping in
 * App\Entity\Media\WokeometerTitle / WokeometerSyncState — tests use
 * SchemaTool, never this migration. All timestamps are INTEGER UTC epoch
 * seconds.
 */
final class Version20260929000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wokeometer: create wokeometer_media + wokeometer_sync_state.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE wokeometer_media (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              wokeometer_id VARCHAR(36) NOT NULL,
              media_type VARCHAR(10) NOT NULL,
              tmdb_id INTEGER DEFAULT NULL,
              external_source VARCHAR(40) DEFAULT NULL,
              external_id VARCHAR(150) DEFAULT NULL,
              parent_wokeometer_id VARCHAR(36) DEFAULT NULL,
              season_number INTEGER DEFAULT NULL,
              title VARCHAR(255) NOT NULL,
              release_date VARCHAR(10) DEFAULT NULL,
              woke_score INTEGER DEFAULT NULL,
              tldr CLOB DEFAULT NULL,
              slug VARCHAR(255) DEFAULT NULL,
              is_analyzed BOOLEAN DEFAULT NULL,
              wokeometer_updated_at INTEGER NOT NULL,
              last_seen_at INTEGER NOT NULL,
              synced_at INTEGER NOT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_wokeometer_media_wid ON wokeometer_media (wokeometer_id)');
        $this->addSql('CREATE INDEX idx_wokeometer_media_tmdb ON wokeometer_media (tmdb_id, media_type)');
        $this->addSql('CREATE INDEX idx_wokeometer_media_ext ON wokeometer_media (external_source, external_id)');
        $this->addSql('CREATE INDEX idx_wokeometer_media_updated ON wokeometer_media (wokeometer_updated_at)');
        $this->addSql('CREATE INDEX idx_wokeometer_media_parent ON wokeometer_media (parent_wokeometer_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE wokeometer_sync_state (
              id INTEGER NOT NULL,
              watermark INTEGER DEFAULT NULL,
              full_sync_completed_at INTEGER DEFAULT NULL,
              lock_run_id VARCHAR(36) DEFAULT NULL,
              lock_heartbeat_at INTEGER DEFAULT NULL,
              run_mode VARCHAR(12) DEFAULT NULL,
              run_trigger VARCHAR(20) DEFAULT NULL,
              run_started_at INTEGER DEFAULT NULL,
              run_phase VARCHAR(10) DEFAULT NULL,
              run_cursor VARCHAR(36) DEFAULT NULL,
              run_idempotency_key VARCHAR(36) DEFAULT NULL,
              run_requests INTEGER NOT NULL DEFAULT 0,
              run_records INTEGER NOT NULL DEFAULT 0,
              run_transient_failures INTEGER NOT NULL DEFAULT 0,
              last_run_started_at INTEGER DEFAULT NULL,
              last_run_finished_at INTEGER DEFAULT NULL,
              last_success_at INTEGER DEFAULT NULL,
              last_status VARCHAR(20) DEFAULT NULL,
              last_error_code INTEGER DEFAULT NULL,
              last_error_message VARCHAR(255) DEFAULT NULL,
              last_run_requests INTEGER DEFAULT NULL,
              last_run_records INTEGER DEFAULT NULL,
              total_requests INTEGER NOT NULL DEFAULT 0,
              credits_remaining INTEGER DEFAULT NULL,
              credits_remaining_at INTEGER DEFAULT NULL,
              next_attempt_after INTEGER DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('INSERT INTO wokeometer_sync_state (id) VALUES (1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wokeometer_sync_state');
        $this->addSql('DROP TABLE wokeometer_media');
    }
}
